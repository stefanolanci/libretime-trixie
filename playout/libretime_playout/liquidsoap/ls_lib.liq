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
    #if a string has a single apostrophe in it, let's comment it out by ending the string before right before it
    #escaping the apostrophe, and then starting a new string right after it. This is why we use 3 apostrophes.
    json_str = string.replace(pattern="'",(fun (s) -> "'\''"), json_str)

    gateway("webstream '#{web_stream_id()}' '#{json_str}'")
  end
end

# A function applied to each metadata chunk
def append_title(m) =
  log("Using message format #{message_format()}")

  if list.assoc.mem("mapped", m) then
    # protection against applying this function twice. It shouldn't be happening and bug
    # file with Liquidsoap.
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

# Schedule <-> offline default: hard cut to the new branch (Liquidsoap 2.3 pads missing
# switch transitions with `fun (x,y) -> y`). Upstream 2.1 used the same idea via
# `transition_default` + `just_switched` so the first automation track after leaving
# default was plain `b`, not wrapped in fade.in — on LS 2.3 + ffmpeg any fade.in on the
# request.queue path can silence a whole track (~−91 dBFS) while metadata stays valid.
# First arg is the outgoing branch (unused); returning `b` is the documented cut.
def transition_schedule_cut(a, b) =
  b
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

# NOTE
# A few values are hardcoded and may be dependent:
#  - the delay in gracetime is linked with the buffer duration of input.http
#    (delay should be a bit less than buffer)
#  - crossing duration should be less than buffer length
#    (at best, a higher duration will be ineffective)

# HTTP input with "restart" command that waits for "stop" to be effected
# before "start" command is issued. Optionally it takes a new URL to play,
# which makes it a convenient replacement for "url".
# In the future, this may become a core feature of the HTTP input.
# TODO If we stop and restart quickly several times in a row,
#   the data bursts accumulate and create buffer overflow.
#   Flushing the buffer on restart could be a good idea, but
#   it would also create an interruptions while the buffer is
#   refilling... on the other hand, this would avoid having to
#   fade using both cross() and switch().buffer=5.,max=15.,,autostart=false
# input.ffmpeg relays http(s) streams and accepts a local file path for idle audio; input.http
# with a bogus URL spams decoder errors. Telnet commands stay under the same source id (e.g. http.restart).
# Default idle from entrypoint is lavfi anullsrc (infinite silence). File paths still work if configured.
def input.http_restart(~id, ~initial_url)

  ffmpeg_input =
    if string.contains(substring="anullsrc=", initial_url) then
      input.ffmpeg(id=id, format="lavfi", initial_url)
    else
      input.ffmpeg(id=id, initial_url)
    end
  # No mksafe here: on LS 2.3.x it flips to safe_blank during idle lavfi/bootstrap and can
  # interact badly with the main clock; anullsrc is already continuous. Web relay URLs rely
  # on ffmpeg restart/stop handling in telnet commands.
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
                    add_timeout(0.5,
                      { if stopped() then
                          log(string(server.execute("#{id}.start"))) ;
                          (-1.)
                        else 0.5 end})
                    "Done"
                  end)

  source

end

