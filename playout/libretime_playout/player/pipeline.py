"""
Minimal passive pipeline monitor.

Runs in its own daemon thread.  Reads three signals that are already
available (no telnet, no HTTP to Liquidsoap/Icecast):

  AUD   – StreamLevelProbeThread.last_mean_volume vs −45 dB (in-memory read)
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
from urllib.error import URLError
from urllib.request import urlopen
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
        probe_link_getter: Optional[Callable[[], Optional[bool]]] = None,
        probe_flow_getter: Optional[Callable[[], Optional[bool]]] = None,
        icecast_status_url: str = "http://127.0.0.1:8000/status-json.xsl",
        icecast_mount: str = "main",
        interval: float = 5.0,
    ):
        super().__init__()
        self._legacy_client = legacy_client
        self._probe_volume_getter = probe_volume_getter
        self._probe_link_getter = probe_link_getter
        self._probe_flow_getter = probe_flow_getter
        self._icecast_status_url = icecast_status_url
        self._icecast_mount = icecast_mount.lstrip("/")
        self._interval = interval
        self.has_schedule: bool = False

    def run(self) -> None:
        time.sleep(min(5.0, self._interval))
        while True:
            try:
                self._report()
            except Exception:
                logger.debug("pipeline monitor tick failed", exc_info=True)
            time.sleep(self._interval)

    def _report(self) -> None:
        ingest_connected = self._read_icecast_ingest_status()

        ice_audio: Optional[bool] = None
        link_up: Optional[bool] = None
        flow_up: Optional[bool] = None
        if self._probe_volume_getter is not None:
            vol = self._probe_volume_getter()
            if vol is not None:
                ice_audio = vol > -45.0
            elif ingest_connected is True:
                # Probe could not sample (e.g. mount reconnect); do not force AUD off
                # while Icecast still shows an active source on the configured mount.
                ice_audio = True
        if self._probe_link_getter is not None:
            link_up = self._probe_link_getter()
        if self._probe_flow_getter is not None:
            flow_up = self._probe_flow_getter()

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
                "link_up": link_up,
                "flow_up": flow_up,
                "ingest_connected": ingest_connected,
                "now_playing_sid": now_playing_sid,
                "has_schedule": self.has_schedule,
                "updated_at": datetime.now(timezone.utc).strftime(
                    "%Y-%m-%dT%H:%M:%SZ"
                ),
            }
        }

        self._legacy_client.update_playout_state(json.dumps(state))

    def _read_icecast_ingest_status(self) -> Optional[bool]:
        try:
            with urlopen(self._icecast_status_url, timeout=3) as resp:  # nosec B310
                payload = json.load(resp)
        except (OSError, URLError, json.JSONDecodeError):
            return None

        source = payload.get("icestats", {}).get("source")
        if source is None:
            return False
        if isinstance(source, list):
            for src in source:
                mount = str(src.get("listenurl", "")).rstrip("/").split("/")[-1]
                if mount == self._icecast_mount:
                    return True
            return False
        mount = str(source.get("listenurl", "")).rstrip("/").split("/")[-1]
        return mount == self._icecast_mount
