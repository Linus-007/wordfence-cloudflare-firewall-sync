#!/usr/bin/env python3

from __future__ import annotations

import argparse
import shutil
import sys
import zipfile
from pathlib import Path


PLUGIN_SLUG = "wordfence-cloudflare-firewall-sync"
REQUIRED_FILES = (
    f"{PLUGIN_SLUG}/index.php",
    f"{PLUGIN_SLUG}/uninstall.php",
)


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build and verify the WordPress plugin release ZIP."
    )
    parser.add_argument(
        "--source",
        default="src",
        help="Plugin source directory.",
    )
    parser.add_argument(
        "--output",
        default=f"dist/{PLUGIN_SLUG}.zip",
        help="Release ZIP path.",
    )
    return parser.parse_args()


def should_exclude(path: Path) -> bool:
    excluded_names = {
        ".DS_Store",
        "__MACOSX",
        ".git",
        ".gitignore",
    }

    return any(part in excluded_names for part in path.parts)


def build_zip(source: Path, output: Path) -> None:
    if not source.is_dir():
        raise RuntimeError(f"Source directory does not exist: {source}")

    output.parent.mkdir(parents=True, exist_ok=True)

    if output.exists():
        output.unlink()

    with zipfile.ZipFile(
        output,
        mode="w",
        compression=zipfile.ZIP_DEFLATED,
        compresslevel=9,
    ) as archive:
        directory_entry = f"{PLUGIN_SLUG}/"
        archive.writestr(directory_entry, "")

        for path in sorted(source.rglob("*")):
            relative_path = path.relative_to(source)

            if should_exclude(relative_path):
                continue

            archive_path = Path(PLUGIN_SLUG) / relative_path
            archive_name = archive_path.as_posix()

            if path.is_dir():
                archive.writestr(f"{archive_name}/", "")
                continue

            archive.write(path, archive_name)


def verify_zip(output: Path) -> None:
    if not output.is_file():
        raise RuntimeError(f"Release ZIP was not created: {output}")

    if not zipfile.is_zipfile(output):
        raise RuntimeError(f"Release file is not a valid ZIP: {output}")

    with zipfile.ZipFile(output, mode="r") as archive:
        corrupt_file = archive.testzip()

        if corrupt_file is not None:
            raise RuntimeError(
                f"Corrupt file detected in release ZIP: {corrupt_file}"
            )

        names = archive.namelist()

    for required_file in REQUIRED_FILES:
        if required_file not in names:
            raise RuntimeError(
                f"Required plugin file is missing: {required_file}"
            )

    invalid_root_entries = {
        "index.php",
        "uninstall.php",
        "includes/",
        "assets/",
        "languages/",
    }

    for name in names:
        if name in invalid_root_entries:
            raise RuntimeError(
                f"Plugin content was incorrectly placed at ZIP root: {name}"
            )

        if not name.startswith(f"{PLUGIN_SLUG}/"):
            raise RuntimeError(
                f"Unexpected ZIP entry outside plugin directory: {name}"
            )


def print_contents(output: Path) -> None:
    with zipfile.ZipFile(output, mode="r") as archive:
        for name in archive.namelist():
            print(name)


def main() -> int:
    arguments = parse_arguments()

    source = Path(arguments.source).resolve()
    output = Path(arguments.output).resolve()

    try:
        build_zip(source, output)
        verify_zip(output)
    except RuntimeError as error:
        print(f"ERROR: {error}", file=sys.stderr)
        return 1

    size = output.stat().st_size

    print(f"Created: {output}")
    print(f"Size: {size} bytes")
    print("Contents:")
    print_contents(output)

    return 0


if __name__ == "__main__":
    sys.exit(main())
