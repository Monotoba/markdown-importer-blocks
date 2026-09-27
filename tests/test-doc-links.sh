#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readme="$root/README.md"
plugin="$root/markdown-importer-blocks.php"

for url in \
  'https://github.com/Monotoba/code-content-blocks' \
  'https://github.com/Monotoba/math-content-blocks' \
  'https://github.com/Monotoba/Mermaid-WP-Block'; do
  grep -Fq "$url" "$readme" || {
    echo "Missing companion repository link: $url" >&2
    exit 1
  }
done

grep -Fq 'Plugin URI:  https://github.com/Monotoba/markdown-importer-blocks' "$plugin"

if grep -Eq '\.\./\.\./(code-content-blocks|math-content-blocks|mermaid-content-blocks)|example\.invalid' "$readme" "$plugin"; then
  echo 'Stale repository link or placeholder URL found' >&2
  exit 1
fi

echo 'Repository links validated.'
