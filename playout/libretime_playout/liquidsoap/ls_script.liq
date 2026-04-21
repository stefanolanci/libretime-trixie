boot_timestamp = string(time())

web_stream_enabled = ref(false)
web_stream_id = ref("-1")
# True from web_stream.set_id (prebuffer) until stop_web_stream. http_fallback still uses only
# web_stream_enabled so the file is not cut early; switch:blank+schedule uses this to avoid
# the outer default/offline dip between file EOF and start_web_stream.
web_stream_armed = ref(false)

show_name = interactive.string("show_name", "")

dynamic_metadata_callback = ref (fun (~new_track=false, s) -> begin () end)

just_switched = ref(false)

%include "ls_lib.liq"

sources = ref([])
source_id = ref (0)

# Dedicated web stream HTTP source controlled via telnet command "http.restart".
http = input.http_restart(id="http", initial_url=http_idle_initial_path)
ignore(output.dummy(fallible=true, http))

def create_source()
    this_source_id = source_id()
    l = request.queue(id="s#{this_source_id}")

    l = stereo(id="queue_src", l)

    # Replay gain: amplify reads the per-track value from annotation metadata.
    # The UI "Replay Gain" setting controls whether the API sends a value;
    # when disabled, libretime_replay_gain is absent and amplify uses 1.0.
    l = amplify(1., override="libretime_replay_gain", l)

    # LS 2.3 applies liq_cue_in / liq_cue_out / liq_fade_in / liq_fade_out
    # natively during request resolution - no explicit cue_cut or fade
    # operators needed.  The playout annotation sends the values configured
    # in the UI (per-track cue points, fade durations).

    l = metadata.map(notify_queue, l)
    l = cross_http(http_input_id="http", l)
    l = http_fallback(http_input_id="http", http=http, default=l)
    l = metadata.map(id="map_metadata:schedule", update=false, append_title, l)
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

def map_message_offline(m) =
  [("title", message_offline())]
end

default = metadata.map(id="map_metadata:offline", map_message_offline, default)
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
        log("user '#{args.user}' connected", label="#{input_name}_input")

        ret = process.test("libretime-playout-notify live-auth '#{input_name}' '#{args.user}' '#{args.password}'")
        if ret then
            log("user '#{args.user}' authenticated", label="#{input_name}_input")
        else
            log("user '#{args.user}' auth failed", label="#{input_name}_input",level=2)
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
            [({schedule_streaming() or web_stream_enabled() or web_stream_armed() or web_stream_id() != "-1"}, queue), ({true}, default)]
    )

s = if input_show_port != 0 and input_show_mount != "" then
    input_show_harbor =
        input.harbor(id="harbor:input_show",
            input_show_mount,
            port=input_show_port,
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
