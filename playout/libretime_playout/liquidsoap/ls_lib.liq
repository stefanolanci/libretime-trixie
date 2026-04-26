def gateway(args)
  command = "timeout --signal=KILL 45 libretime-playout-notify #{args} &"
  log(command)
  process.run(command)
end

def notify(m)
  gateway("media '#{m['schedule_table_id']}'")
  file.write(data="#{m['schedule_table_id']}", "/var/lib/libretime/playout/.now_playing_sid")
end

def notify_queue(m)
  f = dynamic_metadata_callback()
  ignore(f(m))
  notify(m)
  m
end

def notify_stream(m)
  if web_stream_id() != "-1" then
    # LS 2.3: json.stringify replaces the deprecated json_of.
    json_str = string.replace(pattern="\n",(fun (s) -> ""), json.stringify(m))
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

# HTTP input with "restart" command for web streams.
# We use input.http so telnet commands "#{id}.url/.stop/.start/.status" are available.
# If initial_url is an ffmpeg lavfi URI (anullsrc=...), input.http cannot parse it, so
# we bootstrap with a harmless dummy HTTP URL and rely on runtime "#{id}.url <web_url>".
# start=false keeps the HTTP input idle (and silent in the journal) until a real web
# stream URL is provided via the "restart" command below; otherwise input.http would
# retry the dummy URL every ~2s and flood the Liquidsoap log with connection errors.
#
# The "restart" telnet command is idempotent: when playout re-sends the same URL while
# the source is already streaming (observed during schedule refresh that re-asserts the
# active webstream slot), we skip the stop/start cycle. Without this guard, the cycle
# produces a sub-frame track boundary inside the http source (LS warns "Source created
# multiple tracks in a single frame!") which corrupts the PCM frames sent to the local
# Icecast outputs and triggers "Broken pipe" on /main and /main-low, disconnecting
# listener apps. The idempotent no-op preserves a stable stream for the listener while
# still allowing real URL changes, first activation, and network-recovery restarts.
def input.http_restart(~id, ~initial_url, ~connected_ref)
  startup_url =
    if string.contains(substring="anullsrc=", initial_url) then
      "http://127.0.0.1:9"
    else
      initial_url
    end
  def on_connect(_headers)
    connected_ref := true
  end
  def on_disconnect()
    connected_ref := false
  end
  def on_stop()
    connected_ref := false
  end
  def on_error(_error)
    connected_ref := false
  end
  source = stereo(input.http(id=id,
                             start=false,
                             on_connect=on_connect,
                             on_disconnect=on_disconnect,
                             on_stop=on_stop,
                             on_error=on_error,
                             startup_url))

  # Last URL actually armed via the "restart" telnet command. Used to detect redundant
  # calls from playout (same URL while already streaming) and skip the stop/start cycle.
  last_url = ref(startup_url)

  def stopped()
    status = string(server.execute("#{id}.status"))
    string.contains(substring="stopped", status)
  end

  server.register(namespace=id,
                  "restart",
                  usage="restart [url]",
                  fun (url) -> begin
                    target_url =
                      if url == "" then last_url()
                      else url end
                    if target_url == last_url() and not stopped() then
                      log("#{id}.restart: idempotent no-op (url unchanged, source streaming)")
                      "Done"
                    else
                      if url != "" then
                        log(string(server.execute("#{id}.url #{url}")))
                        last_url := url
                      end
                      log(string(server.execute("#{id}.stop")))
                      # LS 2.3: thread.run.recurrent replaces add_timeout
                      thread.run.recurrent(delay=0.5,
                        { if stopped() then
                            log(string(server.execute("#{id}.start"))) ;
                            (-1.)
                          else 0.5 end})
                      "Done"
                    end
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
  source = metadata.map(on_m, source)

  cross_d = 3.

  def crosser(ending, starting)
    # input.ffmpeg has no "#{id}.url" / "#{id}.status" telnet API (LS 2.3).
    url_raw = last_url()
    url =
      if string.contains(substring="ERROR:", url_raw) then ""
      else url_raw end
    status =
      if source.is_ready() then "streaming" else "stopped" end
    ignore(on_m([("source_url", url)]))
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
    # input.http may report is_ready() late around track boundaries even after
    # FFmpeg has connected. The native lifecycle callbacks track connection state
    # early enough for an armed webstream to take over when the schedule runs dry.
    web_stream_http_connected()
  end
  # Longer hold than the default 3s: after Icecast broken-pipe reconnect, http.is_ready
  # can flap false briefly and triggers to_static → to_live (audible + PLC chaos).
  connected = gracetime(delay=10., connected)

  def web_stream_should_play()
    connected() and (
      web_stream_enabled() or
      (web_stream_armed() and not default.is_ready())
    )
  end

  def to_live(a, b) =
    log("TRANSITION to live")
    if web_stream_armed() and not web_stream_enabled() and web_stream_id() != "-1" then
      log("web stream armed takeover")
      notify([("schedule_table_id", web_stream_id())]) ;
      web_stream_enabled := true
    end ;
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
    # `default` is the aggregate scheduled queue. If it runs dry slightly before
    # WEB_STREAM_OUTPUT_START, an already armed and connected webstream may take over
    # instead of briefly selecting the offline source.
    [({web_stream_should_play()}, http),
     ({true}, default)])

end
