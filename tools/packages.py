#!/usr/bin/env python3

import sys
from argparse import ArgumentParser
from configparser import ConfigParser
from os import PathLike
from pathlib import Path
from typing import Iterator, List, Optional, Set

DEFAULT_PACKAGES_FILENAME = "packages.ini"
FORMATS = ("list", "line")
DISTRIBUTIONS = ("trixie",)

SETTINGS_SECTION = "=settings"
DEVELOPMENT_SECTION = "=development"


def load_packages(
    raw: str,
    distribution: str,
    development: bool = False,
    exclude: Optional[List[str]] = None,
) -> Set[str]:
    if distribution not in DISTRIBUTIONS:
        raise ValueError(f"Invalid distribution '{distribution}'")

    manager = ConfigParser(default_section=SETTINGS_SECTION)
    manager.read_string(raw)

    packages: Set[str] = set()
    exclude_set = set(exclude or [])
    for section, entries in manager.items():
        if (not development and section == DEVELOPMENT_SECTION) or section in exclude_set:
            continue

        for package, distributions in entries.items():
            if distribution in [d.strip() for d in distributions.split(",")]:
                packages.add(package)

    return packages


def _collect_sections(raw: str) -> Set[str]:
    manager = ConfigParser(default_section=SETTINGS_SECTION)
    manager.read_string(raw)
    return set(manager.sections()) | {SETTINGS_SECTION, DEVELOPMENT_SECTION}


def list_packages_files(
    paths: List[PathLike],
) -> Iterator[Path]:
    for path_like in paths:
        path = Path(path_like)

        if path.is_dir():
            path = path / DEFAULT_PACKAGES_FILENAME

        if not path.is_file():
            raise ValueError(f"{path} is not a file!")

        yield path


def list_packages(
    paths: List[PathLike],
    distribution: str,
    development: bool = False,
    exclude: Optional[List[str]] = None,
) -> List[str]:
    packages: Set[str] = set()
    seen_sections: Set[str] = set()
    for package_file in list_packages_files(paths):
        raw = package_file.read_text()
        seen_sections.update(_collect_sections(raw))
        packages.update(load_packages(raw, distribution, development, exclude))

    for unknown in sorted(set(exclude or []) - seen_sections):
        print(
            f"packages.py: warning: --exclude section '{unknown}' not found in any packages.ini",
            file=sys.stderr,
        )

    return sorted(packages)


def run():
    parser = ArgumentParser()
    parser.add_argument(
        "-f",
        "--format",
        choices=FORMATS,
        help="print packages list in a specific format.",
        default="list",
    )
    parser.add_argument(
        "-d",
        "--dev",
        help="include development packages.",
        action="store_true",
    )
    parser.add_argument(
        "-e",
        "--exclude",
        help="exclude packages sections.",
        action="append",
    )
    parser.add_argument(
        "distribution",
        choices=DISTRIBUTIONS,
        help="list packages for the given distribution (Debian 13 Trixie only).",
    )
    parser.add_argument(
        "path",
        nargs="+",
        help="list packages from given files or directories.",
    )
    args = parser.parse_args()

    packages = list_packages(args.path, args.distribution, args.dev, args.exclude)

    if args.format == "list":
        print("\n".join(packages))
    else:
        print(" ".join(packages))


if __name__ == "__main__":
    run()
