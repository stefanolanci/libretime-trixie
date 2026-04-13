boot_timestamp = string(time())

web_stream_enabled = ref(false)
web_stream_id = ref( '-1')

show_name = interactive.string("show_name", "")

dynamic_metadata_callback = ref (fun (~new_track=false, s) -> begin () end)

%include "ls_lib.liq"

sources = ref([])
source_id = ref (0)

def create_source()
    this_source_id = source_id()
    l = request.queue(id="s#{this_source_id}")#, length=0.5

    l = audio_to_stereo(id="queue_src", l)
    # cue_cut is deprecated in Liquidsoap 2.3.2+ (integrated into request resolution);
    # keeping it here silenced decoded audio on some builds.
    # Playout sets annotate:libretime_replay_gain only; do not use override="replay_gain"
    # or ICY/decoder metadata can force ~0 linear gain before the track stabilizes.
    l = amplify(1., override="libretime_replay_gain", l)

    # Do not wrap each queue in fade.in/fade.out here: on Liquidsoap 2.3.x with ffmpeg,
    # that combination silences the automation path (~−91 dBFS on Icecast) while metadata
    # and decoder logs still look healthy.

    l = map_metadata(notify_queue,l)
    # Web streams use the http source via telnet; file automation does not need
    # crossfade wrappers around it.
    l = map_metadata(id="map_metadata:schedule", update=false, append_title, l)
    sources := list.append([l], sources())
    server.register(namespace="queues",
                "s#{this_source_id}_skip",
                fun (s) -> begin log("queues.s#{this_source_id}_skip")
                    clear_queue(l)
                    "Done"
                end)
    source_id := source_id() + 1
end

# Do not call enable_replaygain_metadata(): on Liquidsoap 2.3.x + ffmpeg it can force the
# automation chain to ~−91 dBFS silence while ICY metadata and VU still update. Library
# replay gain from playout uses amplify(..., override="libretime_replay_gain") and
# annotate:libretime_replay_gain when replay gain is enabled in stream preferences.
create_source()
create_source()
create_source()
create_source()

queue = add(normalize=false, sources())
# crossfade() caused the first scheduled track to stay silent until the next track
# boundary (metadata/ICY correct, ~−91 dBFS on encoders) on Liquidsoap 2.3.x + ffmpeg.
# Per-file liq_fade_in/out in annotate still apply at request resolution.
queue = insert_metadata(queue)
dynamic_metadata_callback := queue.insert_metadata

output.dummy(fallible=true, queue)

http = input.http_restart(id="http", initial_url=http_idle_initial_path)
ignore(output.dummy(fallible=true, http))

def web_stream_set_id(value)
    web_stream_id := value
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
                    web_stream_enabled := true "enabled" end)
server.register(namespace="sources",
                description="Stop webstream source",
                "stop_web_stream",
                fun (s) -> begin log("sources.stop_web_stream") web_stream_enabled := false "disabled" end)

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

default = map_metadata(id="map_metadata:offline", map_message_offline, default)
ignore(output.dummy(default, fallible=true))

input_main_streaming = ref (false)
input_show_streaming = ref (false)
# Start false: bootstrap apply_liquidsoap_stream_switches (schedule_streaming=True) calls
# start_schedule once the queue is still empty, so the switch takes the automation branch
# without needing a post-push telnet pulse (which broke the first active decode on LS 2.3).
# Brief default (~offline noise) until playout connects is acceptable.
schedule_streaming = ref (false)

def start_input_main() input_main_streaming := true end
def stop_input_main() input_main_streaming := false end
def start_input_show() input_show_streaming := true end
def stop_input_show() input_show_streaming := false end
def start_schedule() schedule_streaming := true end
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

        # Check auth based on return value from auth script
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

# Do not use ({true}, default): when the queue is momentarily not ready, switch would steal
# the output to near-silent default while replay_metadata keeps ICY titles (mute ~−91 dBFS).
# Use transition_schedule_cut (instant handoff): no fade.in on the queue, matching LS 2.3
# default padding `fun (x,y) -> y` and upstream 2.1’s “plain b” path for default→automation.
s = switch(id="switch:blank+schedule",
            track_sensitive=false,
            transitions=[transition_schedule_cut, transition_schedule_cut],
            [({schedule_streaming()}, queue), ({not schedule_streaming()}, default)]
    )

s = if input_show_port != 0 and input_show_mount != "" then
    # buffer_length() is defined on input.harbor, not on audio_to_stereo(); require it so an idle
    # harbor does not steal the stream. Harbor is used only when the UI switch is on (see fetch.py
    # bootstrap: streaming = switch and connected).
    input_show_harbor =
        input.harbor(id="harbor:input_show",
            input_show_mount,
            port=input_show_port,
            auth=make_input_auth_handler("show"),
            max=40.,
            on_connect=input_show_on_connect,
            on_disconnect=input_show_on_disconnect)
    input_show_source = audio_to_stereo(input_show_harbor)

    ignore(output.dummy(input_show_source, fallible=true))

    switch(id="switch:blank+schedule+show",
            track_sensitive=false,
            transitions=[transition, transition],
            # Do not require source.is_ready(harbor): on Liquidsoap 2.3 it can stay false while PCM is valid,
            # so the mix would stay on automation despite a connected encoder.
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
    input_main_source = audio_to_stereo(input_main_harbor)

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
