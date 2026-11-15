#!/usr/bin/env bash
# Ein Einstieg für alle Prüfungen vor einem Release. Reihenfolge: Lint, PHPCS, Version, Tests, i18n, Plugin Check, ZIP bauen, ZIP prüfen.
# Ausgabe: Schlusstabelle mit PASS / FAIL / SKIPPED je Schritt. Exit 1, wenn ein Pflichtschritt FAIL ist.
# SKIPPED wird nie als bestanden gezählt, ist aber (ausser bei Pflichtschritten) kein Abbruch. MLAB_CHECK_STRICT=1: SKIPPED zählt als Fehler.
# Aufruf: bash bin/check.sh [erwartete-version|tag]
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
EXPECTED="${1:-}"
LOGDIR="$(mktemp -d)"
trap 'rm -rf "$LOGDIR"' EXIT

names=(); results=(); notes=()
record() { names+=("$1"); results+=("$2"); notes+=("$3"); }

# run <name> <pflicht:1|0> <skip-exit-code|-> <befehl...>
run() {
	local name="$1" mandatory="$2" skipcode="$3"; shift 3
	local log="$LOGDIR/${#names[@]}.log"
	echo "==> $name"
	"$@" >"$log" 2>&1; local rc=$?
	if [ "$rc" -eq 0 ]; then
		tail -n 3 "$log" | sed 's/^/    /'
		record "$name" PASS ""
	elif [ "$skipcode" != "-" ] && [ "$rc" -eq "$skipcode" ]; then
		sed 's/^/    /' "$log" | tail -n 8
		record "$name" SKIPPED "NICHT AUSGEFÜHRT (Exit $rc), siehe Meldung"
	else
		sed 's/^/    /' "$log" | tail -n 40
		record "$name" FAIL "Exit $rc"
		[ "$mandatory" = "1" ] || true
	fi
}
skip() { echo "==> $1"; echo "    NICHT AUSGEFÜHRT: $2"; record "$1" SKIPPED "$2"; }

run "Lint (php -l)" 1 - bash bin/lint.sh
if [ -x vendor/bin/phpcs ]; then run "PHPCS (WordPress Coding Standards)" 1 - bash bin/phpcs.sh; else skip "PHPCS (WordPress Coding Standards)" "vendor/bin/phpcs fehlt ('composer install')"; fi
run "Versionsprüfung" 1 - bash bin/check-version.sh ${EXPECTED:+"$EXPECTED"}
if [ -f tests/env/wordpress/wp-config.php ]; then
	run "Unit- und Integrationstests" 1 - php tests/run.php
else
	run "Unit-Tests (ohne Integration)" 1 - php tests/run.php unit
	skip "Integrationstests" "tests/env fehlt ('bash tests/env/setup.sh')"
fi
if [ -f bin/i18n-check.sh ]; then run "i18n-Aktualität" 1 3 bash bin/i18n-check.sh; else skip "i18n-Aktualität" "bin/i18n-check.sh existiert noch nicht"; fi
run "Plugin Check" 1 3 bash bin/plugin-check.sh
run "ZIP bauen" 1 - bash bin/build-zip.sh dist
if [ "${results[$((${#results[@]} - 1))]}" = "PASS" ]; then
	MLAB_REQUIRE_LOCALES="${MLAB_REQUIRE_LOCALES:-0}" run "ZIP prüfen (Struktur, Secrets, Installationstest)" 1 3 bash bin/verify-zip.sh dist/mlab-smtp.zip
else
	skip "ZIP prüfen (Struktur, Secrets, Installationstest)" "ZIP wurde nicht gebaut"
	results[$((${#results[@]} - 1))]=FAIL
fi

echo
echo "================ Ergebnis ================"
failed=0; skipped=0
for i in "${!names[@]}"; do
	printf '%-8s %s%s\n' "${results[$i]}" "${names[$i]}" "${notes[$i]:+  (${notes[$i]})}"
	[ "${results[$i]}" = "FAIL" ] && failed=$((failed + 1))
	[ "${results[$i]}" = "SKIPPED" ] && skipped=$((skipped + 1))
done
echo "=========================================="
if [ "$skipped" -gt 0 ]; then
	echo "ACHTUNG: $skipped Schritt(e) NICHT AUSGEFÜHRT (SKIPPED). Das ist kein Bestehen."
fi
if [ "$failed" -gt 0 ]; then echo "FAIL: $failed Pflichtschritt(e) fehlgeschlagen."; exit 1; fi
if [ "$skipped" -gt 0 ] && [ "${MLAB_CHECK_STRICT:-0}" = "1" ]; then echo "FAIL: MLAB_CHECK_STRICT=1 und Schritte übersprungen."; exit 1; fi
if [ "$skipped" -gt 0 ]; then echo "TEILWEISE: alle ausgeführten Schritte bestanden, aber nicht alle Schritte liefen."; exit 0; fi
echo "PASS: alle Schritte bestanden."
