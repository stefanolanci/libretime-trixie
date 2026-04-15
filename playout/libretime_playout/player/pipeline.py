"""
Minimal passive pipeline monitor.

Runs in its own daemon thread.  Reads three signals that are already
available (no telnet, no HTTP to Liquidsoap/Icecast):

  ICE   – StreamLevelProbeThread.last_mean_volume  (in-memory read)
  FET   – boolean flag set by PypoFetch            (in-memory read)
  PLAY  – file written by Liquidsoap notify()      (fast local read)

Every *interval* seconds it POSTs a tiny JSON to /api/playout-state
so the dashboard JS can light the three lamps.

push.py and liquidsoap.py are NOT touched.
"""

from __future__ import annotations

import json
import logging
import time
from datetime import datetime, timezone
from pathlib import Path
from threading import Thread
from typing import TYPE_CHECKING, Callable, Optional

if TYPE_CHECKING:
    from libretime_api_client.v1 import ApiClient as LegacyClient

logger = logging.getLogger(__name__)

_NOW_PLAYING_PATH = Path("/var/lib/libretime/playout/.now_playing_sid")


class PipelineMonitor(Thread):
    daemon = True
    name = "pipeline_monitor"

    def __init__(
        self,
        legacy_client: LegacyClient,
        probe_volume_getter: Optional[Callable[[], Optional[float]]] = None,
        interval: float = 10.0,
    ):
        super().__init__()
        self._legacy_client = legacy_client
        self._probe_volume_getter = probe_volume_getter
        self._interval = interval
        self.has_schedule: bool = False

    def run(self) -> None:
        time.sleep(15.0)
        while True:
            try:
                self._report()
            except Exception:
                logger.debug("pipeline monitor tick failed", exc_info=True)
            time.sleep(self._interval)

    def _report(self) -> None:
        ice_audio: Optional[bool] = None
        if self._probe_volume_getter is not None:
            vol = self._probe_volume_getter()
            if vol is not None:
                ice_audio = vol > -45.0

        now_playing_sid: Optional[int] = None
        try:
            raw = _NOW_PLAYING_PATH.read_text().strip()
            if raw:
                now_playing_sid = int(raw)
        except (FileNotFoundError, ValueError, OSError):
            pass

        state = {
            "pipeline": {
                "ice_audio": ice_audio,
                "now_playing_sid": now_playing_sid,
                "has_schedule": self.has_schedule,
                "updated_at": datetime.now(timezone.utc).strftime(
                    "%Y-%m-%dT%H:%M:%SZ"
                ),
            }
        }

        self._legacy_client.update_playout_state(json.dumps(state))
