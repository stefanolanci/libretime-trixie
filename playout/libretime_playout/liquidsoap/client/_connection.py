import logging
import socket
from threading import RLock
from pathlib import Path
from typing import Optional

logger = logging.getLogger(__name__)


class InvalidConnection(ConnectionError):
    """
    Call was made with an invalid connection
    """


class LiquidsoapConnection:
    _host: str
    _port: int
    _path: Optional[Path] = None
    _timeout: int

    _sock: Optional[socket.socket] = None
    _eof = b"END"
    _lock: RLock

    def __init__(
        self,
        host: str = "localhost",
        port: int = 0,
        path: Optional[Path] = None,
        timeout: int = 5,
    ):
        """
        Create a connection to a Liquidsoap server.

        Args:
            host: Host of the Liquidsoap server. Defaults to "localhost".
            port: Port of the Liquidsoap server. Defaults to 0.
            path: Unix socket path of the Liquidsoap server. If defined, use a unix
                socket instead of the host and port address. Defaults to None.
            timeout: Socket timeout. Defaults to 5.
        """
        self._path = path
        self._host = host
        self._port = port
        self._timeout = timeout
        self._lock = RLock()

    def address(self) -> str:
        return f"{self._host}:{self._port}" if self._path is None else str(self._path)

    def __enter__(self):
        self._lock.acquire()
        try:
            self.connect()
            return self
        except Exception:
            self._lock.release()
            raise

    def __exit__(self, exc_type, exc_value, _traceback):
        try:
            self.close()
        finally:
            self._lock.release()

    def connect(self):
        with self._lock:
            try:
                logger.debug("connecting to %s", self.address())

                if self._path is not None:
                    self._sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
                    self._sock.settimeout(self._timeout)
                    self._sock.connect(str(self._path))
                else:
                    self._sock = socket.create_connection(
                        address=(self._host, self._port),
                        timeout=self._timeout,
                    )

            except (OSError, ConnectionError):
                self._sock = None
                raise

    def close(self):
        with self._lock:
            sock = self._sock
            if sock is not None:
                logger.debug("closing connection to %s", self.address())

                try:
                    try:
                        self.write("exit")
                        # Reading for clean exit
                        while sock.recv(1024):
                            continue
                    except (OSError, ConnectionError):
                        logger.debug("connection to %s already closed", self.address())

                finally:
                    try:
                        sock.close()
                    finally:
                        self._sock = None

    def write(self, *messages: str):
        with self._lock:
            sock = self._sock
            if sock is None:
                raise InvalidConnection()

            for message in messages:
                logger.debug("sending %s", message)
                buffer = message.encode(encoding="utf-8")
                buffer += b"\n"

                sock.sendall(buffer)

    def read(self) -> str:
        with self._lock:
            sock = self._sock
            if sock is None:
                raise InvalidConnection()

            chunks = []
            while True:
                chunk = sock.recv(1024)
                if not chunk:
                    break

                eof_index = chunk.find(self._eof)
                if eof_index >= 0:
                    chunk = chunk[:eof_index]
                    chunks.append(chunk)
                    break

                chunks.append(chunk)

            buffer = b"".join(chunks)
            buffer = buffer.replace(b"\r\n", b"\n")
            buffer = buffer.rstrip(b"END")
            buffer = buffer.strip(b"\n")
            message = buffer.decode("utf-8")

            logger.debug("received %s", message)
            return message
