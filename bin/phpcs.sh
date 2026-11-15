#!/usr/bin/env bash
# PHPCS mit WordPress Coding Standards. Gibt nichts aus, wenn alles sauber ist.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
if [ ! -x vendor/bin/phpcs ]; then
	echo "vendor/bin/phpcs fehlt. Zuerst 'composer install' ausführen." >&2
	exit 2
fi
# installed_paths explizit setzen: der Composer-Installer läuft als root nicht.
PATHS="$ROOT/vendor/wp-coding-standards/wpcs,$ROOT/vendor/phpcsstandards/phpcsutils,$ROOT/vendor/phpcsstandards/phpcsextra"
exec vendor/bin/phpcs --standard=phpcs.xml.dist --runtime-set installed_paths "$PATHS" --report=full -q --no-colors "$@"
