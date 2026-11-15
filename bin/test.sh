#!/usr/bin/env bash
# Führt Unit- und Integrationstests aus. Integration wird übersprungen, wenn tests/env fehlt.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
if [ ! -f vendor/autoload.php ]; then echo "vendor/ fehlt. Zuerst 'composer install'." >&2; exit 2; fi
rc=0
php tests/run.php unit || rc=1
if [ -f tests/env/wordpress/wp-config.php ]; then
	php tests/run.php integration || rc=1
else
	echo "HINWEIS: Integrationstests übersprungen (tests/env nicht installiert, 'bash tests/env/setup.sh' ausführen)."
fi
exit $rc
