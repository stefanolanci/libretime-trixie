boot_timestamp = string(time())

web_stream_enabled = ref(false)
web_stream_id = ref("-1")
web_stream_http_connected = ref(false)
# True from web_stream.set_id (prebuffer) until stop_web_stream. The aggregate
# http_fallback can take over from the scheduled queue when the queued file has
# ended slightly before the WEB_STREAM_OUTPUT_START event fires.
web_stream_armed = ref(false)

show_name = interactive.string("show_name", "")

dynamic_metadata_callback = ref (fun (~new_track=false, s) -> begin () end)

just_switched = ref(false)

%include "ls_lib.liq"

sources = ref([])
source_id = ref (0)

# Dedicated web stream HTTP source controlled via telnet command "http.restart".
http = input.http_restart(id="http",
                          initial_url=http_idle_initial_path,
                          connected_ref=web_stream_http_connected)
ignore(output.dummy(fallible=true, http))

def create_source()
    this_source_id = source_id()
    l = request.queue(id="s#{this_source_id}")

    l = stereo(id="queue_src", l)

    # Replay gain: amplify reads the per-track value from annotation metadata.
    # The UI "Replay Gain" setting controls whether the API sends a value;
    # when disabled, libretime_replay_gain is absent and amplify uses 1.0.
    l = amplify(1., override="libretime_replay_gain", l)

    # Cue points are handled by request resolution; fade metadata shapes the
    # per-track audio envelope before scheduled queue overlap creates the crossfade.
    l = track_fade_envelope(id="queue_fade_#{this_source_id}", l)

    l = metadata.map(notify_queue, l)
    l = cross_http(http_input_id="http", l)
    sources := list.append([l], sources())
    server.register(namespace="queues",
                "s#{this_source_id}_skip",
                fun (s) -> begin log("queues.s#{this_source_id}_skip")
                    clear_queue(l)
                    "Done"
                end)
    source_id := source_id() + 1
end

create_source()
create_source()
create_source()
create_source()

queue = add(normalize=false, sources())
queue = http_fallback(http_input_id="http", http=http, default=queue)
queue = metadata.map(id="map_metadata:schedule", update=false, append_title, queue)
queue = insert_metadata(queue)
dynamic_metadata_callback := queue.insert_metadata

output.dummy(fallible=true, queue)

def web_stream_set_id(value)
    sid = string.trim(value)
    web_stream_id := sid
    if sid != "-1" then web_stream_armed := true else web_stream_armed := false end
    string(web_stream_id())
end

def web_stream_get_id()
    string(web_stream_id())
end

server.register(namespace="sources",
                description="Start webstream source",
                "start_web_stream",
                fun (s) -> begin log("sources.start_web_stream")
                    notify([("schedule_table_id", web_stream_id())])
                    web_stream_armed := true ;
                    web_stream_enabled := true "enabled" end)
server.register(namespace="sources",
                description="Stop webstream source",
                "stop_web_stream",
                fun (s) -> begin log("sources.stop_web_stream")
                    web_stream_armed := false ;
                    web_stream_enabled := false "disabled" end)

server.register(namespace="web_stream",
                description="Set the web stream id",
                "set_id",
                fun (s) -> begin log("web_stream.set_id") web_stream_set_id(s) end)

server.register(namespace="web_stream",
                description="Get the web stream id",
                "get_id",
                fun (s) -> begin log("web_stream.get_id") web_stream_get_id() end)

default = amplify(id="silence_src", 0.00001, noise())
default = insert_metadata(default)
offline_metadata_callback = default.insert_metadata

def clean_message_offline() =
  string.replace(pattern="\\\"", (fun (s) -> ""), message_offline())
end

def map_message_offline(m) =
  [("title", clean_message_offline())]
end

default = metadata.map(id="map_metadata:offline", map_message_offline, default)

def push_message_offline_metadata() =
  ignore(offline_metadata_callback([("title", clean_message_offline())]))
end

ignore(push_message_offline_metadata())
ignore(thread.run.recurrent(delay=15., { push_message_offline_metadata(); 15. }))
ignore(output.dummy(default, fallible=true))

input_main_streaming = ref (false)
input_show_streaming = ref (false)
schedule_streaming = ref (false)

def start_input_main() input_main_streaming := true end
def stop_input_main() input_main_streaming := false end
def start_input_show() input_show_streaming := true end
def stop_input_show() input_show_streaming := false end
def start_schedule() schedule_streaming := true; just_switched := true end
def stop_schedule() schedule_streaming := false end

def update_source_status(sourcename, status) =
    gateway("live '#{sourcename}' '#{status}'")
end

def input_main_on_connect(header) update_source_status("master_dj", true) end
def input_main_on_disconnect() update_source_status("master_dj", false) end
def input_show_on_connect(header) update_source_status("live_dj", true) end
def input_show_on_disconnect() update_source_status("live_dj", false) end

def make_input_auth_handler(input_name)
    def auth_handler(args)
        log("user '#{args.user}' connected from #{args.address}", label="#{input_name}_input")

        ret = process.test("libretime-playout-notify live-auth '#{input_name}' '#{args.user}' '#{args.password}'")
        # fail2ban-friendly file log (one line per auth outcome, IP last).
        # Used by the libretime-harbor jail (backend=polling on logpath).
        # Format intentionally compact: no timestamp in the line — fail2ban uses
        # file write time via {^LN-BEG} datepattern in the filter.
        status = if ret then "auth_ok" else "auth_failed" end
        ignore(file.write(
            data="libretime-harbor[#{input_name}] status=#{status} ip=#{args.address}\n",
            append=true,
            perms=0o640,
            "/var/log/libretime/harbor-auth.log"
        ))
        if ret then
            log("user '#{args.user}' authenticated from #{args.address}", label="#{input_name}_input")
        else
            log("user '#{args.user}' auth failed from #{args.address}", label="#{input_name}_input",level=2)
        end

        ret
    end
    auth_handler
end

s = switch(id="switch:blank+schedule",
            track_sensitive=false,
            transitions=[transition_default, transition],
            # Web streams are routed inside `queue` (http_fallback). Keep this branch while
            # schedule is on, output is enabled, web_stream_armed (set_id prebuffer), or non-idle
            # web_stream_id; otherwise a file->web handoff can briefly select `default` (offline).
            #
            # A future-only schedule should not suppress the offline metadata source.
            # Keep the schedule branch only when it can actually produce audio (or while
            # a web stream handoff is armed); otherwise fall back to `default`, whose
            # metadata is the configured "Off Air Metadata" value.
            [({(schedule_streaming() and source.is_ready(queue)) or web_stream_enabled() or web_stream_armed() or web_stream_id() != "-1"}, queue), ({true}, default)]
    )

s = if input_show_port != 0 and input_show_mount != "" then
    input_show_harbor =
        input.harbor(id="harbor:input_show",
            input_show_mount,
            port=input_show_port,
            transport=input_show_transport,
            auth=make_input_auth_handler("show"),
            max=40.,
            on_connect=input_show_on_connect,
            on_disconnect=input_show_on_disconnect)
    input_show_source = stereo(input_show_harbor)

    ignore(output.dummy(input_show_source, fallible=true))

    switch(id="switch:blank+schedule+show",
            track_sensitive=false,
            transitions=[transition, transition],
            [({input_show_streaming() and input_show_harbor.buffer_length() > 0.}, input_show_source), ({true}, s)]
        )
else
    s
end

s = if input_main_port != 0 and input_main_mount != "" then
    input_main_harbor =
        input.harbor(id="harbor:input_main",
            input_main_mount,
            port=input_main_port,
            transport=input_main_transport,
            auth=make_input_auth_handler("main"),
            max=40.,
            on_connect=input_main_on_connect,
            on_disconnect=input_main_on_disconnect)
    input_main_source = stereo(input_main_harbor)

    ignore(output.dummy(input_main_source, fallible=true))

    switch(id="switch:blank+schedule+show+main",
            track_sensitive=false,
            transitions=[transition, transition],
            [({input_main_streaming() and input_main_harbor.buffer_length() > 0.}, input_main_source), ({true}, s)]
        )
else
    s
end

server.register(namespace="sources",
    description="Stop main input source.",
    usage="stop_input_main",
    "stop_input_main",
    fun (s) -> begin log("sources.stop_input_main") stop_input_main() "Done" end)
server.register(namespace="sources",
    description="Start main input source.",
    usage="start_input_main",
    "start_input_main",
    fun (s) -> begin log("sources.start_input_main") start_input_main() "Done" end)
server.register(namespace="sources",
    description="Stop show input source.",
    usage="stop_input_show",
    "stop_input_show",
    fun (s) -> begin log("sources.stop_input_show") stop_input_show() "Done" end)
server.register(namespace="sources",
    description="Start show input source.",
    usage="start_input_show",
    "start_input_show",
    fun (s) -> begin log("sources.start_input_show") start_input_show() "Done" end)
server.register(namespace="sources",
    description="Stop schedule source.",
    usage="stop_schedule",
    "stop_schedule",
    fun (s) -> begin log("sources.stop_schedule") stop_schedule() "Done" end)
server.register(namespace="sources",
    description="Start schedule source.",
    usage="start_schedule",
    "start_schedule",
    fun (s) -> begin log("sources.start_schedule") start_schedule() "Done" end)
server.register(namespace="metadata",
    description="Refresh offline metadata.",
    usage="refresh_offline",
    "refresh_offline",
    fun (s) -> begin log("metadata.refresh_offline") push_message_offline_metadata() "Done" end)
