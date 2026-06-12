#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

find "$ROOT" -name '*.php' -not -path '*/vendor/*' -print0 | while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
  echo "PHP OK: ${file#$ROOT/}"
done

node --check "$ROOT/blocks/markdown/editor.js" >/dev/null
echo "JS OK: blocks/markdown/editor.js"

python3 -m json.tool "$ROOT/blocks/markdown/block.json" >/dev/null
echo "JSON OK: blocks/markdown/block.json"

php "$ROOT/tests/smoke.php"
echo "Smoke tests completed."
