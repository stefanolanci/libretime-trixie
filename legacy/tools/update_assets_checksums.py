#!/usr/bin/env python3
"""
Recompute MD5 checksums in application/assets.json for every static file under public/.

Run from anywhere:
  python3 legacy/tools/update_assets_checksums.py

Or from legacy/:
  make update-assets-checksums

Assets::url() appends ?<checksum> so browsers fetch fresh JS/CSS after edits.
"""
from __future__ import annotations

import hashlib
import json
import sys
from pathlib import Path


def _md5_file(path: Path) -> str:
    h = hashlib.md5()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def main() -> int:
    legacy_root = Path(__file__).resolve().parent.parent
    assets_path = legacy_root / "application" / "assets.json"
    public_root = legacy_root / "public"

    if not assets_path.is_file():
        print(f"error: missing {assets_path}", file=sys.stderr)
        return 1
    if not public_root.is_dir():
        print(f"error: missing {public_root}", file=sys.stderr)
        return 1

    text = assets_path.read_text(encoding="utf-8")
    data = json.loads(text)

    if not isinstance(data, dict):
        print("error: assets.json root must be an object", file=sys.stderr)
        return 1

    missing = 0
    updated = 0
    for key in list(data.keys()):
        rel = Path(key)
        fpath = public_root / rel
        if not fpath.is_file():
            print(f"warn: file missing for key {key!r} -> {fpath}", file=sys.stderr)
            missing += 1
            continue
        new_hash = _md5_file(fpath)
        old = data[key]
        if old != new_hash:
            updated += 1
        data[key] = new_hash

    out = json.dumps(data, indent=2, sort_keys=False) + "\n"
    assets_path.write_text(out, encoding="utf-8", newline="\n")

    print(f"wrote {assets_path} ({updated} checksum(s) changed, {missing} missing file(s))")
    return 0 if missing == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
