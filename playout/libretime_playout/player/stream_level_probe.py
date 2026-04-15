"""
Optional background sampling of the published stream loudness via ffmpeg volumedetect.

Enable with environment variables on libretime-playout:
  LIBRETIME_STREAM_PROBE_URL=http://127.0.0.1:8000/main
  LIBRETIME_STREAM_PROBE_INTERVAL=8    # seconds between samples (optional)
"""

from __future__ import annotations

import logging
import re
import subprocess
import threading
import time

logger = logging.getLogger(__name__)


class StreamLevelProbeThread(threading.Thread):
    daemon = True
    name = "stream_level_probe"

    last_mean_volume: float | None = None
    last_link_up: bool | None = None
    last_flowing: bool | None = None

    def __init__(self, url: str, interval: float = 8.0) -> None:
        super().__init__()
        self._url = url
        self._interval = max(5.0, interval)

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
            "3",
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
            self.last_mean_volume = None
            self.last_link_up = proc.returncode == 0
            self.last_flowing = False
            return
        mean_v = float(mean_m.group(1))
        self.last_mean_volume = mean_v
        self.last_link_up = True
        self.last_flowing = True
        if mean_v < -45.0:
            logger.warning(
                "stream level probe: very low audio mean=%s dB max=%s dB url=%s",
                mean_m.group(1),
                max_m.group(1),
                self._url,
            )
        else:
            logger.info(
                "stream level probe: mean=%s dB max=%s dB",
                mean_m.group(1),
                max_m.group(1),
            )
