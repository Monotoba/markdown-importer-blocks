#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
grep -Fq 'BSD 2-Clause License' "$root/LICENSE"
grep -Fq 'Copyright (c) 2026 Randall Morgan' "$root/LICENSE"
grep -Fq 'Redistributions of source code must retain' "$root/LICENSE"
grep -Fq 'Redistributions in binary form must reproduce' "$root/LICENSE"
grep -Fq 'License:     BSD-2-Clause' "$root/markdown-importer-blocks.php"
grep -Fq 'License: BSD-2-Clause' "$root/readme.txt"
grep -Fq 'License-BSD_2--Clause-blue.svg' "$root/README.md"

if grep -Eq 'MIT License|License: *MIT|License-MIT' \
  "$root/LICENSE" "$root/markdown-importer-blocks.php" "$root/readme.txt" "$root/README.md"; then
  echo 'Obsolete MIT declaration found' >&2
  exit 1
fi

echo 'BSD 2-Clause declarations validated.'
