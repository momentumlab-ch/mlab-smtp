#!/usr/bin/env bash
# POT neu erzeugen, in alle .po übernehmen (vorhandene Übersetzungen bleiben), .mo bauen.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_DIR="$ROOT/tests/env"
bash "$ROOT/bin/i18n-make-pot.sh"
php "$ROOT/bin/i18n.php" sync
php -d memory_limit=1024M "$ENV_DIR/vendor/wp-cli/wp-cli/php/boot-fs.php" --path="$ENV_DIR/wordpress" --allow-root i18n make-mo "$ROOT/languages" --quiet
echo ".mo gebaut"
