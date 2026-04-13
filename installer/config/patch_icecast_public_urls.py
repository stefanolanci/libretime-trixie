#!/usr/bin/env python3
"""Set stream.outputs.icecast[*].public_url for HTTPS listening on Icecast."""
from __future__ import annotations

import pathlib
import sys

MOUNTS = ("main", "main-low", "backup")


def main() -> int:
    if len(sys.argv) != 4:
        print(
            "usage: patch_icecast_public_urls.py <config.yml> <hostname> <https-port>",
            file=sys.stderr,
        )
        return 2
    path = pathlib.Path(sys.argv[1])
    host = sys.argv[2]
    port = sys.argv[3]
    base = f"https://{host}:{port}"
    text = path.read_text(encoding="utf-8")
    for mount in MOUNTS:
        needle = f"        public_url:\n        mount: {mount}"
        if needle not in text:
            print(f"patch_icecast_public_urls: pattern not found for mount {mount!r}", file=sys.stderr)
            return 1
        repl = f"        public_url: {base}/{mount}\n        mount: {mount}"
        text = text.replace(needle, repl, 1)
    path.write_text(text, encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
