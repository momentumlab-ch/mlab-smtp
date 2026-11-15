#!/usr/bin/env bash
# Installiert WordPress/plugin-check (Release-Tag) als Plugin in die Testumgebung und aktiviert es.
# Exit: 0 installiert, 3 nicht verfügbar (Grund wird genannt, kein Scheinerfolg), 1 sonstiger Fehler.
# Umgebung: MLAB_PLUGIN_CHECK_REF (Standard 2.2.0), MLAB_PLUGIN_CHECK_REPO.
set -uo pipefail
ENV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP="$ENV_DIR/wordpress"
CACHE="$ENV_DIR/.cache"
REF="${MLAB_PLUGIN_CHECK_REF:-2.2.0}"
REPO="${MLAB_PLUGIN_CHECK_REPO:-https://github.com/WordPress/plugin-check}"
DEST="$WP/wp-content/plugins/plugin-check"
export COMPOSER_ALLOW_SUPERUSER=1
unavailable() { echo "NICHT VERFÜGBAR: Plugin Check kann hier nicht installiert werden: $*" >&2; exit 3; }

[ -f "$WP/wp-load.php" ] || unavailable "WordPress-Testumgebung fehlt ('bash tests/env/setup.sh' ausführen)."
wpcli() { php -d memory_limit=512M "$ENV_DIR/vendor/wp-cli/wp-cli/php/boot-fs.php" --path="$WP" --allow-root "$@"; }

if [ ! -f "$DEST/plugin.php" ] && [ ! -f "$DEST/plugin-check.php" ]; then
	command -v git >/dev/null || unavailable "git fehlt."
	mkdir -p "$CACHE"
	if [ ! -d "$CACHE/plugin-check-$REF/.git" ]; then
		rm -rf "$CACHE/plugin-check-$REF"
		out=$(git clone --quiet --depth 1 --branch "$REF" "$REPO" "$CACHE/plugin-check-$REF" 2>&1) || unavailable "git clone von $REPO ($REF) schlug fehl: $(echo "$out" | tail -2 | tr '\n' ' ')"
	fi
	rm -rf "$DEST"; mkdir -p "$DEST"
	cp -a "$CACHE/plugin-check-$REF/." "$DEST/" && rm -rf "$DEST/.git" || unavailable "Kopieren fehlgeschlagen."
	if [ -f "$DEST/composer.json" ]; then
		command -v composer >/dev/null || unavailable "composer fehlt."
		out=$(cd "$DEST" && composer install --no-dev --no-interaction --no-progress --prefer-dist 2>&1) || { rm -rf "$DEST"; unavailable "composer install schlug fehl: $(echo "$out" | tail -3 | tr '\n' ' ')"; }
	fi
fi
MAIN=$(grep -l '^ \* Plugin Name:' "$DEST"/*.php 2>/dev/null | head -1)
[ -n "$MAIN" ] || unavailable "Hauptdatei von Plugin Check nicht gefunden in $DEST."
act=$(wpcli plugin activate plugin-check 2>&1) || unavailable "Aktivierung schlug fehl: $(echo "$act" | tail -2 | tr '\n' ' ')"
if ! wpcli help plugin check >/dev/null 2>&1; then unavailable "WP-CLI-Befehl 'wp plugin check' ist nach der Aktivierung nicht vorhanden."; fi
echo "OK: Plugin Check $REF installiert und aktiviert ($DEST)."
