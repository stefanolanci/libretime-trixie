"""
Optional background sampling of the published stream loudness via ffmpeg volumedetect.

Enable with environment variables on libretime-playout:
  LIBRETIME_STREAM_PROBE_URL=http://127.0.0.1:8000/main
  LIBRETIME_STREAM_PROBE_INTERVAL=8    # seconds between samples (optional)
  LIBRETIME_STREAM_PROBE_STALE_HOLD_SEC=45
    After a successful sample, keep last mean/link/flow on parse failures for this many
    seconds (Icecast reconnect / webstream transitions often break volumedetect parsing).
"""

from __future__ import annotations

import logging
import os
import re
import subprocess
import threading
import time

logger = logging.getLogger(__name__)

SAMPLE_SECONDS = "5"
LOW_MEAN_DBFS = -68.0
CRITICAL_MEAN_DBFS = -82.0
CRITICAL_PEAK_DBFS = -65.0
HARD_CRITICAL_MEAN_DBFS = -88.0


class StreamLevelProbeThread(threading.Thread):
    daemon = True
    name = "stream_level_probe"

    last_mean_volume: float | None = None
    last_peak_volume: float | None = None
    last_audio_level_state: str | None = None
    last_audio_level_comment: str | None = None
    last_link_up: bool | None = None
    last_flowing: bool | None = None

    def __init__(self, url: str, interval: float = 8.0) -> None:
        super().__init__()
        self._url = url
        self._interval = max(5.0, interval)
        self._last_good_mono: float = 0.0
        self._stale_hold_sec = float(
            os.environ.get("LIBRETIME_STREAM_PROBE_STALE_HOLD_SEC", "45")
        )

    def run(self) -> None:
        # Small startup delay to avoid misleading first probe when chain is still settling.
        time.sleep(min(10.0, self._interval))
        while True:
            try:
                self._sample_once()
            except Exception:  # pylint: disable=broad-exception-caught
                logger.exception("stream level probe failed")
            time.sleep(self._interval)

    def _sample_once(self) -> None:
        cmd = [
            "ffmpeg",
            "-hide_banner",
            "-nostats",
            "-loglevel",
            "info",
            "-t",
            SAMPLE_SECONDS,
            "-i",
            self._url,
            "-af",
            "volumedetect",
            "-f",
            "null",
            "-",
        ]
        proc = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=20,
            check=False,
        )
        blob = (proc.stderr or "") + (proc.stdout or "")
        mean_m = re.search(r"mean_volume:\s*([-\d.]+)\s*dB", blob)
        max_m = re.search(r"max_volume:\s*([-\d.]+)\s*dB", blob)
        if not mean_m or not max_m:
            logger.warning(
                "stream level probe: could not parse ffmpeg output (url=%s rc=%s)",
                self._url,
                proc.returncode,
            )
            age = time.monotonic() - self._last_good_mono
            if (
                self.last_mean_volume is not None
                and self._last_good_mono > 0.0
                and age < max(5.0, self._stale_hold_sec)
            ):
                # Hold last good telemetry for PLC during short Icecast / mux glitches.
                return
            self.last_mean_volume = None
            self.last_peak_volume = None
            self.last_audio_level_state = None
            self.last_audio_level_comment = "Audio probe unavailable"
            self.last_link_up = proc.returncode == 0
            self.last_flowing = False
            return
        mean_v = float(mean_m.group(1))
        max_v = float(max_m.group(1))
        self.last_mean_volume = mean_v
        self.last_peak_volume = max_v
        self.last_link_up = True
        self.last_flowing = True
        self._last_good_mono = time.monotonic()
        if mean_v < HARD_CRITICAL_MEAN_DBFS or (
            mean_v < CRITICAL_MEAN_DBFS and max_v < CRITICAL_PEAK_DBFS
        ):
            self.last_audio_level_state = "critical"
            self.last_audio_level_comment = "Near-silence detected on program output"
            logger.warning(
                "stream level probe: audio critical mean=%s dB max=%s dB url=%s",
                mean_m.group(1),
                max_m.group(1),
                self._url,
            )
        elif mean_v < LOW_MEAN_DBFS:
            self.last_audio_level_state = "low"
            self.last_audio_level_comment = "Audio amplitude very low"
            logger.warning(
                "stream level probe: audio low mean=%s dB max=%s dB url=%s",
                mean_m.group(1),
                max_m.group(1),
                self._url,
            )
        else:
            self.last_audio_level_state = "ok"
            self.last_audio_level_comment = "Audio on air"
            logger.info(
                "stream level probe: mean=%s dB max=%s dB",
                mean_m.group(1),
                max_m.group(1),
            )
