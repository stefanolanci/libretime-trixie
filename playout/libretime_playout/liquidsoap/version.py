import re
from subprocess import run
from typing import Tuple

LIQUIDSOAP_VERSION_RE = re.compile(r"(?:Liquidsoap )?(\d+)\.(\d+)\.(\d+)")
# libretime-trixie targets Debian 13 (Trixie): Liquidsoap 2.3.x from the distribution only.
LIQUIDSOAP_MIN_VERSION = (2, 3, 0)


def parse_liquidsoap_version(version: str) -> Tuple[int, int, int]:
    match = LIQUIDSOAP_VERSION_RE.search(version)

    if match is None:
        return (0, 0, 0)
    return (int(match.group(1)), int(match.group(2)), int(match.group(3)))


def get_liquidsoap_version() -> Tuple[int, int, int]:
    """Parse semver from ``liquidsoap --version`` (Trixie ships 2.3+; no legacy --check path)."""
    cmd = run(
        ("liquidsoap", "--version"),
        check=True,
        capture_output=True,
        text=True,
    )
    combined = f"{cmd.stdout}\n{cmd.stderr}"
    parsed = parse_liquidsoap_version(combined)
    if parsed == (0, 0, 0):
        raise RuntimeError(
            "Could not parse Liquidsoap version from `liquidsoap --version` output. "
            "libretime-trixie expects the Debian Trixie liquidsoap package."
        )
    return parsed


def require_liquidsoap_version(version: Tuple[int, int, int]) -> None:
    """Fail fast when the installed binary is below the minimum for this fork."""
    if version < LIQUIDSOAP_MIN_VERSION:
        major, minor, patch = version
        min_major, min_minor, min_patch = LIQUIDSOAP_MIN_VERSION
        raise RuntimeError(
            f"Liquidsoap {major}.{minor}.{patch} is not supported. "
            f"libretime-trixie requires Liquidsoap >= {min_major}.{min_minor}.{min_patch} "
            "(Debian Trixie package)."
        )
