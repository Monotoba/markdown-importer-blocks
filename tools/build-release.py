#!/usr/bin/env python3
"""Build a reproducible, installable WordPress plugin ZIP."""

import argparse
import json
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PLUGIN = "markdown-importer-blocks"
FILES = (
    "LICENSE",
    "readme.txt",
    "markdown-importer-blocks.php",
    "includes/class-markdown-parser.php",
    "blocks/markdown/block.json",
    "blocks/markdown/editor.asset.php",
    "blocks/markdown/editor.css",
    "blocks/markdown/editor.js",
    "blocks/markdown/style.css",
)


def release_version():
    plugin = (ROOT / "markdown-importer-blocks.php").read_text(encoding="utf-8")
    readme = (ROOT / "readme.txt").read_text(encoding="utf-8")
    block = json.loads((ROOT / "blocks/markdown/block.json").read_text(encoding="utf-8"))

    def find(pattern, content, label):
        match = re.search(pattern, content, re.MULTILINE)
        if not match:
            raise ValueError(f"Missing {label} version")
        return match.group(1)

    versions = {
        "plugin header": find(r"^ \* Version:\s+(\d+\.\d+\.\d+)$", plugin, "plugin header"),
        "PHP constant": find(r"^define\( 'MIB_VERSION', '(\d+\.\d+\.\d+)' \);$", plugin, "PHP constant"),
        "WordPress readme": find(r"^Stable tag: (\d+\.\d+\.\d+)$", readme, "WordPress readme"),
        "block metadata": block.get("version"),
    }
    if len(set(versions.values())) != 1:
        raise ValueError(f"Version mismatch: {versions}")
    return versions["plugin header"]


def check_tag(tag):
    version = release_version()
    if tag != f"v{version}":
        raise ValueError(f"Release tag {tag!r} does not match plugin version v{version}")
    return version


def build(output):
    version = release_version()
    missing = [path for path in FILES if not (ROOT / path).is_file()]
    if missing:
        raise FileNotFoundError(f"Missing release files: {', '.join(missing)}")
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in FILES:
            info = zipfile.ZipInfo(f"{PLUGIN}/{path}", date_time=(2020, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            archive.writestr(info, (ROOT / path).read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    return version


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, help="ZIP destination")
    parser.add_argument("--check-tag", help="Require the tag to match every version declaration")
    args = parser.parse_args()
    if args.check_tag:
        check_tag(args.check_tag)
    output = args.output or ROOT / "dist" / f"{PLUGIN}-{release_version()}.zip"
    version = build(output)
    print(f"Built {output} (v{version})")
