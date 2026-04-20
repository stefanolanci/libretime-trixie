import logging
from pathlib import Path
from time import sleep
from typing import Any, Literal, Optional, Tuple, Union

from ..models import MessageFormatKind
from ..utils import quote
from ..version import parse_liquidsoap_version
from ._connection import LiquidsoapConnection

logger = logging.getLogger(__name__)


class LiquidsoapClientError(Exception):
    """
    A Liquidsoap client error
    """


class LiquidsoapClient:
    """
    A client to communicate with a running Liquidsoap server.

    The client is not thread safe.
    """

    conn: LiquidsoapConnection

    def __init__(
        self,
        host: str = "localhost",
        port: int = 0,
        path: Optional[Path] = None,
        timeout: int = 15,
    ):
        self.conn = LiquidsoapConnection(
            host=host,
            port=port,
            path=path,
            timeout=timeout,
        )

    def _quote(self, value: Any) -> str:
        return quote(value, double=True)

    def _set_var(self, name: str, value: Any) -> None:
        self.conn.write(f"var.set {name} = {value}")
        result = self.conn.read()
        if f"Variable {name} set" not in result:
            logger.error("unexpected response: %s", result)

    def version(self) -> Tuple[int, int, int]:
        with self.conn:
            self.conn.write("version")
            return parse_liquidsoap_version(self.conn.read())

    def wait_for_version(self, timeout: int = 30) -> Tuple[int, int, int]:
        while timeout > 0:
            try:
                version = self.version()
                logger.info("found version %s", version)
                return version
            except OSError as exception:
                logger.warning("could not get version: %s", exception)
                timeout -= 1
                sleep(1)

        raise LiquidsoapClientError("could not get liquidsoap version")

    @staticmethod
    def _request_queue_cmd(queue_id: int, action: str) -> str:
        if queue_id < 3:
            return f"request_queue.{queue_id + 1}.{action}"
        return f"request_queue.{action}"

    def queues_remove(self, *queues: int, force: bool = False) -> None:
        """Skip tracks in the given queues.

        In Liquidsoap 2.3, calling skip on an *empty* queue leaves the source
        in a broken state that silences the first track pushed afterwards
        (the infamous -91 dB bug).  We therefore query each queue first and
        only skip if it actually has pending requests.
        """
        with self.conn:
            for queue_id in queues:
                if force:
                    logger.info("force skip on queue s%d", queue_id)
                    # For "remove current track now", ask LS to flush pending prepared
                    # requests for this queue and skip immediately. This is stronger than
                    # a plain skip and reduces perceived lag between UI remove and audio.
                    self.conn.write(self._request_queue_cmd(queue_id, "flush_and_skip"))
                    self.conn.read()
                    continue
                if queue_id < 3:
                    qcmd = f"request_queue.{queue_id + 1}.queue"
                else:
                    qcmd = "request_queue.queue"
                self.conn.write(qcmd)
                contents = self.conn.read().strip()
                if contents:
                    self.conn.write(self._request_queue_cmd(queue_id, "skip"))
                    self.conn.read()
                else:
                    logger.debug("queue s%d already empty, skip avoided", queue_id)

    def queue_push(self, queue_id: int, entry: str, show_name: str) -> None:
        if queue_id < 3:
            cmd = f"request_queue.{queue_id + 1}.push {entry}"
        else:
            cmd = f"request_queue.push {entry}"
        with self.conn:
            self.conn.write(cmd)
            reply = self.conn.read()
            stripped = reply.strip().splitlines()
            if stripped and not stripped[0].lstrip("-").isdigit():
                preview = entry if len(entry) <= 240 else entry[:240] + "…"
                logger.error(
                    "unexpected liquidsoap push reply for %s: %s (entry preview: %s)",
                    cmd.split(" ", 1)[0],
                    reply,
                    preview,
                )
            try:
                self._set_var("show_name", self._quote(show_name))
            except Exception:
                logger.warning(
                    "failed to set show_name after push (non-fatal, queue_id=%d)",
                    queue_id,
                )

    def web_stream_get_id(self) -> str:
        with self.conn:
            self.conn.write("web_stream.get_id")
            return self.conn.read().splitlines()[0]

    def web_stream_start(self) -> None:
        with self.conn:
            self.conn.write("sources.start_schedule")
            self.conn.read()  # Flush
            self.conn.write("sources.start_web_stream")
            self.conn.read()  # Flush

    def web_stream_start_buffer(self, schedule_id: int, uri: str) -> None:
        with self.conn:
            self.conn.write(f"web_stream.set_id {schedule_id}")
            self.conn.read()  # Flush
            self.conn.write(f"http.restart {uri}")
            self.conn.read()  # Flush

    def web_stream_stop(self) -> None:
        with self.conn:
            self.conn.write("sources.stop_web_stream")
            self.conn.read()  # Flush

    def web_stream_stop_buffer(self) -> None:
        with self.conn:
            # LS 2.3 does not expose "http.stop" for input.ffmpeg-backed HTTP source.
            # Disable web stream routing through the supported source namespace.
            self.conn.write("sources.stop_web_stream")
            self.conn.read()  # Flush
            self.conn.write("web_stream.set_id -1")
            self.conn.read()  # Flush

    def source_switch_status(
        self,
        name: Literal["master_dj", "live_dj", "scheduled_play"],
        streaming: bool,
    ) -> None:
        name_map = {
            "master_dj": "input_main",
            "live_dj": "input_show",
            "scheduled_play": "schedule",
        }
        action = "start" if streaming else "stop"
        with self.conn:
            self.conn.write(f"sources.{action}_{name_map[name]}")
            self.conn.read()  # Flush

    def apply_stream_source_state(
        self,
        *,
        master_streaming: bool,
        show_streaming: bool,
        schedule_streaming: bool,
    ) -> None:
        """Apply master/show/schedule toggles in one telnet session (one connect/exit)."""
        def one(name: Literal["master_dj", "live_dj", "scheduled_play"], on: bool) -> str:
            name_map = {
                "master_dj": "input_main",
                "live_dj": "input_show",
                "scheduled_play": "schedule",
            }
            action = "start" if on else "stop"
            return f"sources.{action}_{name_map[name]}"

        with self.conn:
            self.conn.write(one("master_dj", master_streaming))
            self.conn.read()
            self.conn.write(one("live_dj", show_streaming))
            self.conn.read()
            self.conn.write(one("scheduled_play", schedule_streaming))
            self.conn.read()

    def pulse_schedule_routing(self) -> None:
        """
        Force a schedule off → on cycle in Liquidsoap.

        Not used during playout bootstrap: doing this after the first queue.push can
        deselect the automation source mid-decode and silence the first track on LS 2.3.
        Prefer ls_script cold start with schedule_streaming=false plus a single
        start_schedule from the API sync. Kept for manual troubleshooting if needed.
        """
        with self.conn:
            self.conn.write("sources.stop_schedule")
            self.conn.read()  # Flush
            self.conn.write("sources.start_schedule")
            self.conn.read()  # Flush

    def settings_update(
        self,
        *,
        station_name: Optional[str] = None,
        message_format: Optional[Union[MessageFormatKind, int]] = None,
        message_offline: Optional[str] = None,
        input_fade_transition: Optional[float] = None,
    ) -> None:
        with self.conn:
            if station_name is not None:
                self._set_var("station_name", self._quote(station_name))
            if message_format is not None:
                if isinstance(message_format, MessageFormatKind):
                    message_format = message_format.value
                # Use an interactive.string until Liquidsoap have interactive.int
                # variables
                self._set_var("message_format", self._quote(message_format))
            if message_offline is not None:
                self._set_var("message_offline", self._quote(message_offline))
            if input_fade_transition is not None:
                self._set_var("input_fade_transition", input_fade_transition)
