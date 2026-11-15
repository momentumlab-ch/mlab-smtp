#!/usr/bin/env bash
# Erzeugt languages/mlab-smtp.pot aus dem tatsächlichen Code (WP-CLI i18n). Aufruf: bash bin/i18n-make-pot.sh [Zieldatei]
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_DIR="$ROOT/tests/env"
OUT="${1:-$ROOT/languages/mlab-smtp.pot}"
[ -f "$ENV_DIR/vendor/wp-cli/wp-cli/php/boot-fs.php" ] || { echo "WP-CLI fehlt: bash tests/env/setup.sh" >&2; exit 3; }
php -d memory_limit=1024M "$ENV_DIR/vendor/wp-cli/wp-cli/php/boot-fs.php" --path="$ENV_DIR/wordpress" --allow-root \
	i18n make-pot "$ROOT" "$OUT" --slug=mlab-smtp --domain=mlab-smtp \
	--exclude=lib,tests,bin,docs,dist,vendor,node_modules,.github --skip-js --quiet \
	--headers='{"Report-Msgid-Bugs-To":"https://github.com/momentumlab-ch/mlab-smtp/issues","Language-Team":"MomentumLab"}'
echo "POT: $OUT"
