//approximate server time, because once we receive it from the server,
//there way have been a great amount of latency and it is no longer accurate.
var approximateServerTime = null;
var localRemoteTimeOffset = null;

var previousSong = null;
var currentSong = null;
var nextSong = null;

var currentShow = new Array();
var nextShow = new Array();

var showName = null;

var currentElem;

var serverUpdateInterval = 5000;
var uiUpdateInterval = 200;

var master_dj_on_air = false;
var live_dj_on_air = false;
var scheduled_play_on_air = false;
var scheduled_play_source = false;

var _scheduleVersion = 0;
var _playoutState = null;
var _plcStaleAfterMs = 20000;
var _syncStatus = "unknown"; // "synced", "processing", "diverged", "unknown"
var _versionMismatchSince = null;

//a reference returned by setTimeout. Useful for when we want clearTimeout()
var newSongTimeoutId = null;

//a reference returned by setTimeout. Useful for when we want clearTimeout()
var newShowTimeoutId = null;

//keep track of how many UI refreshes the ON-AIR light has been off for.
//For example, the uiUpdateInterval is every 200ms, so if onAirOffIterations
//is 25, then that means 5 seconds have gone by.
var onAirOffIterations = 0;

/* boolean flag to let us know if we should prepare to execute a function
 * that flips the playlist to the next song. This flag's purpose is to
 * make sure the function is only executed once*/
var nextSongPrepare = true;
var nextShowPrepare = true;

function secondsTimer() {
  /* This function constantly calls itself every 'uiUpdateInterval'
   * micro-seconds and is responsible for updating the UI. */
  if (localRemoteTimeOffset !== null) {
    var date = new Date();
    approximateServerTime = date.getTime() - localRemoteTimeOffset;
    updateProgressBarValue();
    updatePlaybar();
    controlOnAirLight();
    controlSwitchLight();
  }
  setTimeout(secondsTimer, uiUpdateInterval);
}

function newSongStart() {
  nextSongPrepare = true;
  if (nextSong.type == "track") {
    currentSong = nextSong;
    nextSong = null;
  }
}

function nextShowStart() {
  nextShowPrepare = true;
  currentShow[0] = nextShow.shift();
}

/* Called every "uiUpdateInterval" mseconds. */
function updateProgressBarValue() {
  var showPercentDone = 0;
  if (currentShow.length > 0) {
    showPercentDone =
      ((approximateServerTime - currentShow[0].showStartPosixTime) /
        currentShow[0].showLengthMs) *
      100;
    if (showPercentDone < 0 || showPercentDone > 100) {
      showPercentDone = 0;
      currentShow = new Array();
      currentSong = null;
    }
  }
  $("#progress-show").attr("style", "width:" + showPercentDone + "%");

  var songPercentDone = 0;
  var scheduled_play_div = $("#scheduled_play_div");
  var scheduled_play_line_to_switch = scheduled_play_div
    .parent()
    .find(".line-to-switch");

  if (currentSong !== null) {
    var songElapsedTime = 0;
    songPercentDone =
      ((approximateServerTime - currentSong.songStartPosixTime) /
        currentSong.songLengthMs) *
      100;
    songElapsedTime = approximateServerTime - currentSong.songStartPosixTime;
    if (songPercentDone < 0) {
      songPercentDone = 0;
      //currentSong = null;
    } else if (songPercentDone > 100) {
      songPercentDone = 100;
    } else {
      if (
        (currentSong.media_item_played == true && currentShow.length > 0) ||
        (songElapsedTime < 5000 && currentShow[0].record != 1)
      ) {
        scheduled_play_line_to_switch.attr("class", "line-to-switch on");
        scheduled_play_div.addClass("ready");
        scheduled_play_source = true;
      } else {
        scheduled_play_source = false;
        scheduled_play_line_to_switch.attr("class", "line-to-switch off");
        scheduled_play_div.removeClass("ready");
      }
      $("#progress-show").attr("class", "progress-show");
    }
  } else {
    scheduled_play_source = false;
    scheduled_play_line_to_switch.attr("class", "line-to-switch off");
    scheduled_play_div.removeClass("ready");
    $("#progress-show").attr("class", "progress-show-error");
  }
  $("#progress-bar").attr("style", "width:" + songPercentDone + "%");
}

function updatePlaybar() {
  /* Column 0 update */
  if (previousSong !== null) {
    $("#previous").text(previousSong.name + ",");
    $("#prev-length").text(convertToHHMMSSmm(previousSong.songLengthMs));
  } else {
    $("#previous").empty();
    $("#prev-length").empty();
  }

  if (currentSong !== null && !master_dj_on_air && !live_dj_on_air) {
    if (currentSong.record == "1") {
      $("#current").html(
        "<span style='color:red; font-weight:bold'>" +
          $.i18n._("Recording:") +
          "</span>" +
          currentSong.name +
          ",",
      );
    } else {
      $("#current").text(currentSong.name + ",");
      if (currentSong.metadata && currentSong.metadata.artwork_data) {
        var check_current_song = Cookies.get("current_track");
        var loaded = Cookies.get("loaded");

        if (check_current_song != currentSong.name) {
          $("#now-playing-artwork_containter").html(
            "<img class='artwork' src='" +
              currentSong.metadata.artwork_data +
              "' />",
          );
          Cookies.remove("current_track");
          Cookies.set("current_track", currentSong.name);
        }
        // makes sure it stays updated with current track if page loads
        if (loaded != UNIQID) {
          Cookies.remove("current_track");
          Cookies.remove("loaded");
          Cookies.set("loaded", UNIQID);
        }
      }
    }
  } else {
    if (master_dj_on_air) {
      if (showName) {
        $("#current").html(
          $.i18n._("Current") +
            ": <span style='color:red; font-weight:bold'>" +
            showName +
            " - " +
            $.i18n._("Master Stream") +
            "</span>",
        );
      } else {
        $("#current").html(
          $.i18n._("Current") +
            ": <span style='color:red; font-weight:bold'>" +
            $.i18n._("Master Stream") +
            "</span>",
        );
      }
    } else if (live_dj_on_air) {
      if (showName) {
        $("#current").html(
          $.i18n._("Current") +
            ": <span style='color:red; font-weight:bold'>" +
            showName +
            " - " +
            $.i18n._("Live Stream") +
            "</span>",
        );
      } else {
        $("#current").html(
          $.i18n._("Current") +
            ": <span style='color:red; font-weight:bold'>" +
            $.i18n._("Live Stream") +
            "</span>",
        );
      }
    } else {
      $("#current").html(
        $.i18n._("Current") +
          ": <span style='color:red; font-weight:bold'>" +
          $.i18n._("Nothing Scheduled") +
          "</span>",
      );
    }
  }

  if (nextSong !== null) {
    $("#next").text(nextSong.name + ",");
    $("#next-length").text(convertToHHMMSSmm(nextSong.songLengthMs));
  } else {
    $("#next").empty();
    $("#next-length").empty();
  }

  $("#start").empty();
  $("#end").empty();
  $("#time-elapsed").empty();
  $("#time-remaining").empty();
  $("#song-length").empty();
  if (currentSong !== null && !master_dj_on_air && !live_dj_on_air) {
    $("#start").text(currentSong.starts.split(" ")[1]);
    $("#end").text(currentSong.ends.split(" ")[1]);

    /* Get rid of the millisecond accuracy so that the second counters for both
     * show and song change at the same time. */
    var songStartRoughly =
      parseInt(Math.round(currentSong.songStartPosixTime / 1000), 10) * 1000;
    var songEndRoughly =
      parseInt(Math.round(currentSong.songEndPosixTime / 1000), 10) * 1000;

    $("#time-elapsed").text(
      convertToHHMMSS(approximateServerTime - songStartRoughly),
    );
    $("#time-remaining").text(
      convertToHHMMSS(songEndRoughly - approximateServerTime),
    );
    $("#song-length").text(convertToHHMMSS(currentSong.songLengthMs));
  }
  /* Column 1 update */
  $("#playlist").text($.i18n._("Current Show:"));
  var recElem = $(".recording-show");
  if (currentShow.length > 0) {
    $("#playlist").text(currentShow[0].name);
    currentShow[0].record == "1" ? recElem.show() : recElem.hide();
  } else {
    recElem.hide();
  }

  $("#show-length").empty();
  if (currentShow.length > 0) {
    $("#show-length").text(
      convertDateToHHMM(currentShow[0].showStartPosixTime) +
        " - " +
        convertDateToHHMM(currentShow[0].showEndPosixTime),
    );
  }

  /* Column 2 update */
  $("#time").text(convertDateToHHMMSS(approximateServerTime));
}

function calcAdditionalData(currentItem) {
  currentItem.songStartPosixTime = convertDateToPosixTime(currentItem.starts);
  currentItem.songEndPosixTime = convertDateToPosixTime(currentItem.ends);
  currentItem.songLengthMs =
    currentItem.songEndPosixTime - currentItem.songStartPosixTime;
}

function calcAdditionalShowData(show) {
  if (show.length > 0) {
    show[0].showStartPosixTime = convertDateToPosixTime(
      show[0].start_timestamp,
    );
    show[0].showEndPosixTime = convertDateToPosixTime(show[0].end_timestamp);
    show[0].showLengthMs =
      show[0].showEndPosixTime - show[0].showStartPosixTime;
  }
}

function calculateTimeToNextSong() {
  if (approximateServerTime === null) {
    return;
  }

  if (newSongTimeoutId !== null) {
    /* We have a previous timeout set, let's unset it */
    clearTimeout(newSongTimeoutId);
    newSongTimeoutId = null;
  }

  var diff = nextSong.songStartPosixTime - approximateServerTime;
  if (diff < 0) diff = 0;
  nextSongPrepare = false;
  newSongTimeoutId = setTimeout(newSongStart, diff);
}

function calculateTimeToNextShow() {
  if (approximateServerTime === null) {
    return;
  }

  if (newShowTimeoutId !== null) {
    /* We have a previous timeout set, let's unset it */
    clearTimeout(newShowTimeoutId);
    newShowTimeoutId = null;
  }

  var diff = nextShow[0].showStartPosixTime - approximateServerTime;
  if (diff < 0) diff = 0;
  nextShowPrepare = false;
  newShowTimeoutId = setTimeout(nextShowStart, diff);
}

function parseItems(obj) {
  previousSong = obj.previous;
  currentSong = obj.current;
  nextSong = obj.next;

  if (previousSong !== null) {
    calcAdditionalData(previousSong);
  }
  if (currentSong !== null) {
    calcAdditionalData(currentSong);
  }
  if (nextSong !== null) {
    calcAdditionalData(nextSong);
    calculateTimeToNextSong();
  }

  currentShow = new Array();
  if (obj.currentShow.length > 0) {
    calcAdditionalShowData(obj.currentShow);
    currentShow = obj.currentShow;
  }

  nextShow = new Array();
  if (obj.nextShow.length > 0) {
    calcAdditionalShowData(obj.nextShow);
    nextShow = obj.nextShow;
    calculateTimeToNextShow();
  }

  var schedulePosixTime = convertDateToPosixTime(obj.schedulerTime);
  var date = new Date();
  localRemoteTimeOffset = date.getTime() - schedulePosixTime;
}

function parseSourceStatus(obj) {
  var live_div = $("#live_dj_div");
  var master_div = $("#master_dj_div");
  var live_li = live_div.parent();
  var master_li = master_div.parent();

  if (obj.live_dj_source == false) {
    live_li.find(".line-to-switch").attr("class", "line-to-switch off");
    live_div.removeClass("ready");
  } else {
    live_li.find(".line-to-switch").attr("class", "line-to-switch on");
    live_div.addClass("ready");
  }

  if (obj.master_dj_source == false) {
    master_li.find(".line-to-switch").attr("class", "line-to-switch off");
    master_div.removeClass("ready");
  } else {
    master_li.find(".line-to-switch").attr("class", "line-to-switch on");
    master_div.addClass("ready");
  }
}

function parseSwitchStatus(obj) {
  if (obj.live_dj_source == "on") {
    live_dj_on_air = true;
  } else {
    live_dj_on_air = false;
  }

  if (obj.master_dj_source == "on") {
    master_dj_on_air = true;
  } else {
    master_dj_on_air = false;
  }

  if (obj.scheduled_play == "on") {
    scheduled_play_on_air = true;
  } else {
    scheduled_play_on_air = false;
  }

  var scheduled_play_switch = $("#scheduled_play.source-switch-button");
  var live_dj_switch = $("#live_dj.source-switch-button");
  var master_dj_switch = $("#master_dj.source-switch-button");

  scheduled_play_switch.find("span").html(obj.scheduled_play);
  if (scheduled_play_on_air) {
    scheduled_play_switch.addClass("active");
  } else {
    scheduled_play_switch.removeClass("active");
  }

  live_dj_switch.find("span").html(obj.live_dj_source);
  if (live_dj_on_air) {
    live_dj_switch.addClass("active");
  } else {
    live_dj_switch.removeClass("active");
  }

  master_dj_switch.find("span").html(obj.master_dj_source);
  if (master_dj_on_air) {
    master_dj_switch.addClass("active");
  } else {
    master_dj_switch.removeClass("active");
  }
}

function controlOnAirLight() {
  if (
    (scheduled_play_on_air && scheduled_play_source) ||
    live_dj_on_air ||
    master_dj_on_air
  ) {
    $("#on-air-info").attr("class", "on-air-info on");
    onAirOffIterations = 0;
  } else if (onAirOffIterations < 20) {
    //if less than 4 seconds have gone by (< 20 executions of this function)
    //then keep the ON-AIR light on. Only after at least 3 seconds have gone by,
    //should we be allowed to turn it off. This is to stop the light from temporarily turning
    //off between tracks: CC-3725
    onAirOffIterations++;
  } else {
    $("#on-air-info").attr("class", "on-air-info off");
  }
}

function controlSwitchLight() {
  var live_li = $("#live_dj_div").parent();
  var master_li = $("#master_dj_div").parent();
  var scheduled_play_li = $("#scheduled_play_div").parent();

  if (
    scheduled_play_on_air &&
    scheduled_play_source &&
    !live_dj_on_air &&
    !master_dj_on_air
  ) {
    scheduled_play_li
      .find(".line-to-on-air")
      .attr("class", "line-to-on-air on");
    live_li.find(".line-to-on-air").attr("class", "line-to-on-air off");
    master_li.find(".line-to-on-air").attr("class", "line-to-on-air off");
  } else if (live_dj_on_air && !master_dj_on_air) {
    scheduled_play_li
      .find(".line-to-on-air")
      .attr("class", "line-to-on-air off");
    live_li.find(".line-to-on-air").attr("class", "line-to-on-air on");
    master_li.find(".line-to-on-air").attr("class", "line-to-on-air off");
  } else if (master_dj_on_air) {
    scheduled_play_li
      .find(".line-to-on-air")
      .attr("class", "line-to-on-air off");
    live_li.find(".line-to-on-air").attr("class", "line-to-on-air off");
    master_li.find(".line-to-on-air").attr("class", "line-to-on-air on");
  } else {
    scheduled_play_li
      .find(".line-to-on-air")
      .attr("class", "line-to-on-air off");
    live_li.find(".line-to-on-air").attr("class", "line-to-on-air off");
    master_li.find(".line-to-on-air").attr("class", "line-to-on-air off");
  }
}

function getScheduleFromServer() {
  $.ajax({
    url: baseUrl + "Schedule/get-current-playlist/format/json",
    dataType: "json",
    success: function (data) {
      parseItems(data.entries);
      parseSourceStatus(data.source_status);
      parseSwitchStatus(data.switch_status);
      showName = data.show_name;

      if (typeof data.schedule_version !== "undefined") {
        _scheduleVersion = data.schedule_version;
      }
      if (typeof data.playout_state !== "undefined" && data.playout_state) {
        _playoutState = data.playout_state;
      }
      updatePlcPanel();
    },
    error: function (jqXHR, textStatus, errorThrown) {},
  });
}

/** PLC status line + anomaly row: green = nominal, yellow = non-blocking, red = blocking */
function plcStateLevelFromCode6(code6) {
  if (code6 === "111111") {
    return "nominal";
  }
  if (
    code6 === "110011" ||
    code6 === "111101" ||
    (code6[0] === "0" && code6[1] === "1") ||
    (code6[2] === "1" && code6[1] === "0") ||
    (code6[5] === "1" && code6[4] === "0")
  ) {
    return "critical";
  }
  return "warn";
}

function setPlcStateTone(realEl, anomalyEl, level) {
  realEl.removeClass("plc-state-nominal plc-state-warn plc-state-critical");
  anomalyEl.removeClass("plc-state-nominal plc-state-warn plc-state-critical");
  realEl.addClass("plc-state-" + level);
  var t = anomalyEl.text();
  if (typeof t === "string" && $.trim(t).length > 0) {
    anomalyEl.addClass("plc-state-" + level);
  }
}

function updatePlcPanel() {
  function setLamp($el, state, title) {
    var css = "plc-lamp lamp-off";
    if (state === "green") css = "plc-lamp lamp-green";
    else if (state === "red") css = "plc-lamp lamp-red";
    else if (state === "yellow") css = "plc-lamp lamp-yellow";
    $el.attr("class", css).attr("title", title);
  }

  function bit(v) {
    return v ? "1" : "0";
  }

  function interpretSixBitState(code6) {
    var known = {
      111111: [$.i18n._("Nominal on-air"), $.i18n._("Audio chain and logic aligned")],
      111110: [$.i18n._("Ready, waiting playout"), $.i18n._("Chain live, schedule active, no current track")],
      111101: [$.i18n._("Logic mismatch"), $.i18n._("PLAY active without schedule")],
      111100: [$.i18n._("Real chain OK, logic idle"), $.i18n._("No schedule and no active track")],
      110011: [$.i18n._("Silent playout fault"), $.i18n._("Playback declared but audio under threshold")],
      110010: [$.i18n._("Silent chain"), $.i18n._("Flow present but no audible program")],
      100000: [$.i18n._("Link only"), $.i18n._("Probe reachable, no stream flow")],
      000000: [$.i18n._("Chain stopped"), $.i18n._("No real or logic activity")],
    };
    if (known[code6]) {
      return known[code6];
    }
    if (code6[0] === "0" && code6[1] === "1") {
      return [$.i18n._("Telemetry inconsistency"), $.i18n._("Flow active while link is down")];
    }
    if (code6[2] === "1" && code6[1] === "0") {
      return [$.i18n._("Telemetry inconsistency"), $.i18n._("Audio detected without flow")];
    }
    if (code6[5] === "1" && code6[4] === "0") {
      return [$.i18n._("Logic anomaly"), $.i18n._("PLAY active while FET is off")];
    }
    return [$.i18n._("Mixed transitional state"), $.i18n._("Monitor sequence in progress")];
  }

  var lampLink = $("#plc-lamp-link");
  var lampFlow = $("#plc-lamp-flow");
  var lampAud = $("#plc-lamp-aud");
  var lampInce = $("#plc-lamp-ince");
  var lampFetch = $("#plc-lamp-fetch");
  var lampPlay = $("#plc-lamp-play");
  var realStepEl = $("#plc-step-real");
  var logicStepEl = $("#plc-step-logic");
  var anomalyEl = $("#plc-anomaly");
  if (!lampFetch.length || !lampLink.length) return;

  if (!_playoutState || !_playoutState.pipeline) {
    setLamp(lampLink, "off", $.i18n._("No data"));
    setLamp(lampFlow, "off", $.i18n._("No data"));
    setLamp(lampAud, "off", $.i18n._("No data"));
    setLamp(lampInce, "off", $.i18n._("No data"));
    setLamp(lampFetch, "off", $.i18n._("No data"));
    setLamp(lampPlay, "off", $.i18n._("No data"));
    realStepEl.text($.i18n._("State: no data"));
    logicStepEl.text($.i18n._("Detail: waiting first valid sample"));
    anomalyEl.text("");
    setPlcStateTone(realStepEl, anomalyEl, "warn");
    return;
  }

  var p = _playoutState.pipeline;
  var hasNowPlaying =
    typeof p.now_playing_sid !== "undefined" &&
    p.now_playing_sid !== null &&
    p.now_playing_sid !== "";
  var updatedAtMs = p.updated_at ? Date.parse(p.updated_at) : NaN;
  var isStale = !isNaN(updatedAtMs) && Date.now() - updatedAtMs > _plcStaleAfterMs;
  var linkUp = p.link_up === true;
  var flowUp = p.flow_up === true;
  var audUp = p.ice_audio === true;
  var ingestUp = p.ingest_connected === true;

  if (isStale) {
    setLamp(lampLink, "off", $.i18n._("Stale data"));
    setLamp(lampFlow, "off", $.i18n._("Stale data"));
    setLamp(lampAud, "off", $.i18n._("Stale data"));
    setLamp(lampInce, "off", $.i18n._("Stale data"));
    setLamp(lampFetch, "off", $.i18n._("Stale data"));
    setLamp(lampPlay, "off", $.i18n._("Stale data"));
    realStepEl.text($.i18n._("State: stale data"));
    logicStepEl.text($.i18n._("Detail: PLC update delayed"));
    anomalyEl.text($.i18n._("PLC update delayed"));
    setPlcStateTone(realStepEl, anomalyEl, "critical");
    return;
  }

  // REAL lamps: LNK FLW AUD ICE
  if (p.link_up === true) {
    setLamp(lampLink, "green", $.i18n._("Probe link OK"));
  } else if (p.link_up === false) {
    setLamp(lampLink, "red", $.i18n._("Probe link DOWN"));
  } else {
    setLamp(lampLink, "off", $.i18n._("Probe unavailable"));
  }

  if (p.flow_up === true) {
    setLamp(lampFlow, "green", $.i18n._("Stream flow detected"));
  } else if (p.flow_up === false) {
    setLamp(lampFlow, "red", $.i18n._("No stream flow"));
  } else {
    setLamp(lampFlow, "off", $.i18n._("Flow unknown"));
  }

  if (p.ice_audio === true) {
    setLamp(lampAud, "green", $.i18n._("Audio on air"));
  } else if (p.ice_audio === false) {
    setLamp(lampAud, "red", $.i18n._("Audio below threshold"));
  } else {
    setLamp(lampAud, "off", $.i18n._("Waiting for probe"));
  }

  if (p.ingest_connected === true) {
    setLamp(lampInce, "green", $.i18n._("Icecast connection active"));
  } else if (p.ingest_connected === false) {
    setLamp(lampInce, "red", $.i18n._("Icecast connection missing"));
  } else {
    setLamp(lampInce, "off", $.i18n._("Icecast connection unknown"));
  }

  // LOGIC lamps: FET PLAY
  if (p.has_schedule) {
    setLamp(lampFetch, "green", $.i18n._("Schedule active"));
  } else {
    setLamp(lampFetch, "off", $.i18n._("No schedule"));
  }

  if (hasNowPlaying) {
    setLamp(lampPlay, "green", $.i18n._("Playing") + " (sid " + p.now_playing_sid + ")");
  } else if (p.has_schedule) {
    setLamp(lampPlay, "off", $.i18n._("Idle"));
  } else {
    setLamp(lampPlay, "off", $.i18n._("No schedule"));
  }

  var code6 =
    bit(linkUp) +
    bit(flowUp) +
    bit(audUp) +
    bit(ingestUp) +
    bit(p.has_schedule) +
    bit(hasNowPlaying);
  var interpreted = interpretSixBitState(code6);
  realStepEl.text($.i18n._("State: ") + interpreted[0]);
  logicStepEl.text($.i18n._("Detail: ") + interpreted[1] + " [" + code6 + "]");

  var anomalies = [];
  if (hasNowPlaying && p.ice_audio === false) {
    anomalies.push($.i18n._("PLAY=1 but AUD=0"));
  }
  if (flowUp && !linkUp) {
    anomalies.push($.i18n._("FLW=1 but LNK=0"));
  }
  if (audUp && !flowUp) {
    anomalies.push($.i18n._("AUD=1 but FLW=0"));
  }
  if (hasNowPlaying && !p.has_schedule) {
    anomalies.push($.i18n._("PLAY=1 with FET=0"));
  }
  anomalyEl.text(anomalies.join(" | "));
  setPlcStateTone(realStepEl, anomalyEl, plcStateLevelFromCode6(code6));
}

function setupQtip() {
  var qtipElem = $("#about-link");

  if (qtipElem.length > 0) {
    qtipElem.qtip({
      content: $("#about-txt").html(),
      show: "mouseover",
      hide: { when: "mouseout", fixed: true },
      position: {
        corner: {
          target: "center",
          tooltip: "topRight",
        },
      },
      style: {
        border: {
          width: 0,
          radius: 4,
        },
        name: "light", // Use the default light style
      },
    });
  }
}

function setSwitchListener(ele) {
  var sourcename = $(ele).attr("id");
  var status_span = $(ele).find("span");
  var status = status_span.html();
  $.get(
    baseUrl +
      "Dashboard/switch-source/format/json/sourcename/" +
      sourcename +
      "/status/" +
      status,
    function (data) {
      if (data.error) {
        alert(data.error);
      } else {
        if (data.status == "ON") {
          $(ele).addClass("active");
        } else {
          $(ele).removeClass("active");
        }
        status_span.html(data.status);
      }
    },
  );
}

function kickSource(ele) {
  var sourcename = $(ele).attr("id");

  $.get(
    baseUrl +
      "Dashboard/disconnect-source/format/json/sourcename/" +
      sourcename,
    function (data) {
      if (data.error) {
        alert(data.error);
      }
    },
  );
}

var stream_window = null;

function init() {
  //begin producer "thread"
  setInterval(getScheduleFromServer, serverUpdateInterval);

  //begin consumer "thread"
  secondsTimer();

  setupQtip();

  $(".listen-control-button").click(function () {
    if (stream_window == null || stream_window.closed)
      stream_window = window.open(
        baseUrl + "Dashboard/stream-player",
        "name",
        "width=400,height=158",
      );
    stream_window.focus();
    return false;
  });
}

/* We never retrieve the user's password from the db
 * and when we call isValid($params) the form values are cleared
 * and repopulated with $params which does not have the password
 * field. Therefore, we fill the password field with 6 x's
 */
function setCurrentUserPseudoPassword() {
  $("#cu_password").val("xxxxxx");
  $("#cu_passwordVerify").val("xxxxxx");
}

/*$(window).resize(function() {
 */ /* If we don't do this, the menu can stay hidden after resizing */ /*
    if ($(this).width() > 970) {
        $("#nav .responsive-menu").show();
    }
});*/

$(document).ready(function () {
  if ($("#master-panel").length > 0) init();
  if ($(".errors").length === 0) {
    setCurrentUserPseudoPassword();
  }

  $("body").on("click", "#current-user", function () {
    $.ajax({
      url: baseUrl + "user/edit-user/format/json",
    });
  });

  $("body").on("click", "#cu_save_user", function () {
    Cookies.set("airtime_locale", $("#cu_locale").val(), { path: "/" });
  });

  // When the 'Listen' button is clicked we set the width
  // of the share button to the width of the 'Live Stream'
  // text. This differs depending on the language setting
  $("#popup-link").css("width", $(".jp-container h1").css("width"));

  /*$('#menu-btn').click(function() {
        $('#nav .responsive-menu').slideToggle();
    });*/
});
