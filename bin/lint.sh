#!/usr/bin/env bash
# php -l über alle PHP-Dateien ausser lib/, vendor/ und der lokalen WordPress-Testumgebung.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
fail=0
while IFS= read -r -d '' f; do
	out=$(php -l "$f" 2>&1) || { echo "$out"; fail=1; }
done < <(find . \( -path ./lib -o -path ./vendor -o -path ./node_modules -o -path ./tests/env/wordpress -o -path ./tests/env/vendor -o -path ./tests/env/.cache -o -path ./.git \) -prune -o -name '*.php' -print0)
exit $fail
