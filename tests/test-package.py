#!/usr/bin/env python3
"""Check release ZIP contents and version alignment."""

import hashlib
import importlib.util
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("build_release", ROOT / "tools/build-release.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class ReleaseTests(unittest.TestCase):
    def test_installable_and_reproducible(self):
        with tempfile.TemporaryDirectory() as directory:
            first, second = (Path(directory) / name for name in ("one.zip", "two.zip"))
            self.assertEqual(builder.build(first), builder.release_version())
            builder.build(second)
            self.assertEqual(hashlib.sha256(first.read_bytes()).digest(), hashlib.sha256(second.read_bytes()).digest())
            with zipfile.ZipFile(first) as archive:
                self.assertEqual(set(archive.namelist()), {f"markdown-importer-blocks/{path}" for path in builder.FILES})
                self.assertIsNone(archive.testzip())
                self.assertIn(b"Plugin Name: Markdown Importer Blocks", archive.read("markdown-importer-blocks/markdown-importer-blocks.php"))
                self.assertIn(b"BSD 2-Clause License", archive.read("markdown-importer-blocks/LICENSE"))
                for item in archive.infolist():
                    self.assertEqual(item.date_time, (2020, 1, 1, 0, 0, 0))

    def test_tag_must_match(self):
        self.assertEqual(builder.check_tag(f"v{builder.release_version()}"), builder.release_version())
        with self.assertRaisesRegex(ValueError, "does not match"):
            builder.check_tag("v9.9.9")

    def test_version_mismatch_fails(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for path in ("markdown-importer-blocks.php", "readme.txt", "blocks/markdown/block.json"):
                target = root / path
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes((ROOT / path).read_bytes())
            readme = root / "readme.txt"
            current = builder.release_version()
            readme.write_text(readme.read_text(encoding="utf-8").replace(f"Stable tag: {current}", "Stable tag: 9.9.9"), encoding="utf-8")
            with mock.patch.object(builder, "ROOT", root):
                with self.assertRaisesRegex(ValueError, "Version mismatch"):
                    builder.release_version()


if __name__ == "__main__":
    unittest.main()
