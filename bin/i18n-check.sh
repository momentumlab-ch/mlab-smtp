#!/usr/bin/env bash
# Prüft die Übersetzungen: POT passt zum Code, jede Sprache vollständig, Platzhalter/HTML/Plural stimmig, .mo aktuell.
# Exit 0 = alles gut, 1 = Fehler, 3 = nicht ausführbar (Werkzeuge fehlen).
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[ -f "$ROOT/tests/env/vendor/autoload.php" ] || { echo "Werkzeuge fehlen: bash tests/env/setup.sh" >&2; exit 3; }
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
bash "$ROOT/bin/i18n-make-pot.sh" "$TMP/fresh.pot" >/dev/null || exit 3
rc=0
# POT-Aktualität: gleiche Texte (ohne Kopfzeilen, Datum, Quellzeilen)
norm() { php -r 'require $argv[1]."/tests/env/vendor/autoload.php"; $t=Gettext\Translations::fromPoFile($argv[2]); $o=[]; foreach($t as $e){$o[]=$e->getContext()."\x04".$e->getOriginal()."\x04".$e->getPlural();} sort($o); echo implode("\n",$o),"\n";' "$ROOT" "$1"; }
if ! diff <(norm "$ROOT/languages/mlab-smtp.pot") <(norm "$TMP/fresh.pot") >"$TMP/diff.txt"; then
	echo "FAIL: languages/mlab-smtp.pot ist nicht aktuell (bash bin/i18n-sync.sh). Unterschiede:"; head -n 15 "$TMP/diff.txt"; rc=1
else
	echo "OK: POT passt zum Code"
fi
php "$ROOT/bin/i18n.php" check || rc=1
exit $rc
