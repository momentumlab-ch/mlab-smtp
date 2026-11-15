#!/usr/bin/env bash
# Führt WordPress/plugin-check ('wp plugin check mlab-smtp') in der Testumgebung aus. ERROR bricht ab, WARNINGs werden gelistet.
# Exit: 0 keine Fehler, 1 ERROR-Funde, 3 Plugin Check nicht verfügbar (kein Scheinerfolg).
# Umgebung: MLAB_PLUGIN_CHECK_CATEGORIES (optional, kommagetrennt).
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_DIR="$ROOT/tests/env"
WP="$ENV_DIR/wordpress"
bash "$ENV_DIR/install-plugin-check.sh" || { rc=$?; echo "NICHT AUSGEFÜHRT: Plugin Check (Exit $rc), siehe Meldung oben." >&2; exit 3; }
wpcli() { php -d memory_limit=1024M "$ENV_DIR/vendor/wp-cli/wp-cli/php/boot-fs.php" --path="$WP" --allow-root "$@"; }
# Geprüft wird, was ausgeliefert wird: das ZIP (Standard dist/mlab-smtp.zip, wird bei Bedarf gebaut) in einem
# nicht aktivierten Plugin-Ordner mlab-smtp-dist, mit --slug=mlab-smtp.
ZIP="${1:-$ROOT/dist/mlab-smtp.zip}"
[ -f "$ZIP" ] || bash "$ROOT/bin/build-zip.sh" "$(dirname "$ZIP")" >/dev/null || { echo "FAIL: ZIP konnte nicht gebaut werden." >&2; exit 1; }
CHK="$WP/wp-content/plugins/mlab-smtp-dist"
TMPX="$(mktemp -d)"; trap 'rm -rf "$TMPX" "$CHK"' EXIT
unzip -q "$ZIP" -d "$TMPX" || { echo "FAIL: ZIP nicht lesbar." >&2; exit 1; }
rm -rf "$CHK"; mv "$TMPX/mlab-smtp" "$CHK"
# Nur für das WordPress.org-Verzeichnis relevante Funde (Update-Checker, Update URI, deutsches readme.txt) sind vorerst ausgenommen:
# das Plugin wird über GitHub-Releases verteilt (docs/directory-submission.md). MLAB_PLUGIN_CHECK_DIRECTORY=1 zählt sie mit.
IGNORE="plugin_updater_detected,update_uri_not_allowed,readme_short_description_non_official_language,readme_description_non_official_language"
args=(plugin check mlab-smtp-dist --slug=mlab-smtp --format=json)
[ "${MLAB_PLUGIN_CHECK_DIRECTORY:-0}" = "1" ] || args+=("--ignore-codes=$IGNORE")
[ -n "${MLAB_PLUGIN_CHECK_CATEGORIES:-}" ] && args+=("--categories=$MLAB_PLUGIN_CHECK_CATEGORIES")
ERR="$(mktemp)"
out=$(wpcli "${args[@]}" 2>"$ERR"); rc=$?
if ! printf '%s' "$out" | grep -q '^\['; then
	if [ "$rc" -ne 0 ] || [ -s "$ERR" ]; then
		echo "FAIL: Plugin Check lieferte keine auswertbare Ausgabe (Exit $rc):" >&2
		{ printf '%s\n' "$out"; cat "$ERR"; } | head -15 >&2
		rm -f "$ERR"; exit 1
	fi
	out='FILE: none'
fi
rm -f "$ERR"
printf '%s' "$out" | MLAB_ROOT="$ROOT" MLAB_STRICT="${MLAB_PLUGIN_CHECK_STRICT:-0}" python3 -I -c '
import html, json, os, re, sys
# Ausgabe von WP-CLI: je Datei "FILE: pfad" und danach eine JSON-Liste.
items = []; cur = "?"
for ln in sys.stdin.read().splitlines():
    ln = ln.strip()
    if ln.startswith("FILE: "): cur = ln[6:]
    elif ln.startswith("["):
        for x in json.loads(ln):
            x["file"] = cur; items.append(x)
root = os.environ.get("MLAB_ROOT", "")
def rel(f): return f.split("/mlab-smtp/")[-1] if "/mlab-smtp/" in f else (f[len(root)+1:] if root and f.startswith(root) else f)
strict = os.environ.get("MLAB_STRICT") == "1"
def third(x): return rel(x["file"]).startswith("lib/") 
def line(x): return "  %s:%s:%s  %s  %s" % (rel(x["file"]), x.get("line","?"), x.get("column","?"), x.get("code","?"), " ".join(html.unescape(str(x.get("message",""))).split())[:180])
errors = [x for x in items if str(x.get("type","")).upper() == "ERROR" and (strict or not third(x))]
lib_err = [x for x in items if str(x.get("type","")).upper() == "ERROR" and third(x) and not strict]
warns = [x for x in items if str(x.get("type","")).upper() == "WARNING"]
print("WARNINGs: %d" % len(warns))
for x in warns: print(line(x))
print("ERRORs in Fremdcode lib/ (nicht zählend, MLAB_PLUGIN_CHECK_STRICT=1 zählt sie): %d" % len(lib_err))
for x in lib_err: print(line(x))
print("ERRORs im eigenen Code: %d" % len(errors))
for x in errors: print(line(x))
print("FAIL: Plugin Check meldet ERROR-Funde." if errors else "PASS: Plugin Check ohne ERROR im eigenen Code (%d WARNINGs, %d ERRORs in lib/)." % (len(warns), len(lib_err)))
sys.exit(1 if errors else 0)
'
exit "${PIPESTATUS[1]}"
