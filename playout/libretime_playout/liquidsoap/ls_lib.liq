def gateway(args)
  command = "timeout --signal=KILL 45 libretime-playout-notify #{args} &"
  log(command)
  process.run(command)
end

def notify(m)
  gateway("media '#{m['schedule_table_id']}'")
end

def notify_queue(m)
  f = dynamic_metadata_callback()
  ignore(f(m))
  notify(m)
  m
end

def notify_stream(m)
  if web_stream_id() != "-1" then
    json_str = string.replace(pattern="\n",(fun (s) -> ""), json_of(m))
    json_str = string.replace(pattern="'",(fun (s) -> "'\''"), json_str)
    gateway("webstream '#{web_stream_id()}' '#{json_str}'")
  end
end

def append_title(m) =
  log("Using message format #{message_format()}")

  if list.assoc.mem("mapped", m) then
    m
  else
      if message_format() == "1" then
        [("title", "#{show_name()} - #{m['artist']} - #{m['title']}"), ("mapped", "true")]
      elsif message_format() == "2" then
        [("title", "#{station_name()} - #{show_name()}"), ("mapped", "true")]
      else
        if "#{m['artist']}" == "" then
          [("title", "#{m['title']}"), ("mapped", "true")]
        else
          [("title", "#{m['artist']} - #{m['title']}"), ("mapped", "true")]
        end
     end
  end
end

# Transition for live DJ inputs (show/main harbor).
# Duration is controlled by the UI setting "input_fade_transition".
def transition(a,b) =
  log("transition called...")
  add(
    normalize=false,
    [
      sequence([
        blank(duration=0.01),
        fade.in(duration=input_fade_transition(), b)
      ]),
      fade.out(duration=input_fade_transition(), a)
    ]
  )
end

# Transition from default (silent noise) to automation queue.
# On the first switch after start_schedule(), crossfade for smooth ramp-in.
# Later switches hard-cut: tracks carry per-track fade metadata (liq_fade_in/out).
def transition_default(a,b) =
  log("transition_default called...")
  if just_switched() then
    just_switched := false
    add(
      normalize=false,
      [
        sequence([
          blank(duration=0.01),
          fade.in(duration=input_fade_transition(), b)
        ]),
        fade.out(duration=input_fade_transition(), a)
      ]
    )
  else
    just_switched := false
    b
  end
end

def make_output_on_connect_handler(stream)
  def on_connect()
    gateway("stream '#{stream}' '#{boot_timestamp}'")
  end
  on_connect
end

def make_output_on_error_handler(stream)
  def on_error(msg)
    gateway("stream '#{stream}' '#{boot_timestamp}' --error='#{msg}'")
    5.
  end
  on_error
end

def clear_queue(s)
    source.skip(s)
end

# HTTP input with "restart" command.  Uses input.ffmpeg which relays http(s)
# streams and accepts lavfi sources for idle audio (e.g. anullsrc).
def input.http_restart(~id, ~initial_url)

  ffmpeg_input =
    if string.contains(substring="anullsrc=", initial_url) then
      input.ffmpeg(id=id, format="lavfi", initial_url)
    else
      input.ffmpeg(id=id, initial_url)
    end
  source = audio_to_stereo(ffmpeg_input)

  def stopped()
    "stopped" == list.hd(server.execute("#{id}.status"), default="")
  end

  server.register(namespace=id,
                  "restart",
                  usage="restart [url]",
                  fun (url) -> begin
                    if url != "" then
                      log(string(server.execute("#{id}.url #{url}")))
                    end
                    log(string(server.execute("#{id}.stop")))
                    # LS 2.3: thread.run.recurrent replaces add_timeout
                    thread.run.recurrent(delay=0.5,
                      { if stopped() then
                          log(string(server.execute("#{id}.start"))) ;
                          (-1.)
                        else 0.5 end})
                    "Done"
                  end)

  source

end

# Crossfade transitions between URL changes in HTTP web streams.
def cross_http(~debug=true, ~http_input_id, source)
  id = http_input_id
  last_url = ref("")
  change = ref(false)

  def on_m(m) =
    notify_stream(m)
    changed = m["source_url"] != last_url()
    log("URL now #{m['source_url']} (change: #{changed})")
    if changed then
      if last_url() != "" then change := true end
      last_url := m["source_url"]
    end
    m
  end
  source = map_metadata(on_m, source)

  cross_d = 3.

  def crosser(ending, starting)
    url = list.hd(server.execute("#{id}.url"), default="")
    status = list.hd(server.execute("#{id}.status"))
    on_m([("source_url", url)])
    if debug then
      log("New track inside HTTP stream")
      log("  status: #{status}")
      log("  need to cross: #{change()}")
    end
    if change() then
      change := false
      sequence([fade.out(duration=cross_d, ending.source), fade.in(starting.source)])
    else
      sequence([ending.source, starting.source])
    end
  end

  # LS 2.3: conservative param removed.
  cross(duration=cross_d, crosser, source)

end

# Fallback between HTTP web stream and default source with fading.
def http_fallback(~http_input_id, ~http, ~default)

  id = http_input_id

  def gracetime(~delay=3., f)
    last_true = ref(0.)
    { if f() then
        last_true := time()
        true
      else
        time() < last_true() + delay
    end }
  end

  def connected()
    status = list.hd(server.execute("#{id}.status"), default="")
    not(list.mem(status, ["polling", "stopped"]))
  end
  connected = gracetime(connected)

  def to_live(a, b) =
    log("TRANSITION to live")
    add(normalize=false,
      [fade.in(b), fade.out(a)])
  end
  def to_static(a, b) =
    log("TRANSITION to static")
    sequence([fade.out(a), fade.in(b)])
  end

  switch(
    track_sensitive=false,
    transitions=[to_live, to_static],
    [({connected() and source.is_ready(http) and web_stream_enabled()}, http),
     ({true}, default)])

end
