#!/usr/bin/env bash
# Prüft, dass die Version überall gleich ist: Plugin-Header, MLAB_SMTP_VERSION, readme.txt (Stable tag und
# oberster Changelog-Eintrag) und oberster Eintrag in CHANGELOG.md. Optional: erwartete Version oder Tag (v1.5.0).
# Aufruf: bash bin/check-version.sh [erwartete-version|tag]
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

fail=0
trim() { sed 's/^[[:space:]]*//; s/[[:space:]]*$//'; }
first() { grep -m1 -E "$1" "$2" 2>/dev/null || true; }

header=$(first '^[[:space:]*]*Version:' mlab-smtp.php | sed 's/.*Version:[[:space:]]*//' | trim)
const=$(first "define\( *'MLAB_SMTP_VERSION'" mlab-smtp.php | sed "s/.*MLAB_SMTP_VERSION', *'\([^']*\)'.*/\1/")
stable=$(first '^Stable tag:' readme.txt | sed 's/^[^:]*:[[:space:]]*//' | trim)
rm_log=$(awk '/^== Changelog ==/{f=1;next} f && /^= .* =$/{gsub(/^= | =$/,"");print;exit}' readme.txt | trim)
cl_log=$(awk '/^## /{x=$2;gsub(/[][]/,"",x);print x;exit}' CHANGELOG.md | trim)

show() { printf '  %-34s %s\n' "$1" "${2:-<nicht gefunden>}"; }
echo "Versionen:"
show "Plugin-Header (Version:)" "$header"
show "Konstante MLAB_SMTP_VERSION" "$const"
show "readme.txt Stable tag" "$stable"
show "readme.txt oberster Changelog" "$rm_log"
show "CHANGELOG.md oberster Eintrag" "$cl_log"

ref="$header"
if [ -z "$ref" ]; then echo "FEHLER: Im Plugin-Header wurde keine Version gefunden." >&2; exit 1; fi
if ! [[ "$ref" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]]; then
	echo "FEHLER: Version '$ref' ist nicht im Format X.Y.Z." >&2; fail=1
fi
cmp() {
	if [ "$2" != "$ref" ]; then echo "FEHLER: $1 ist '${2:-leer}', erwartet '$ref' (Plugin-Header)." >&2; fail=1; fi
}
cmp "Konstante MLAB_SMTP_VERSION" "$const"
cmp "readme.txt Stable tag" "$stable"
cmp "readme.txt oberster Changelog-Eintrag" "$rm_log"
cmp "CHANGELOG.md oberster Eintrag" "$cl_log"

if [ $# -ge 1 ] && [ -n "$1" ]; then
	expected="${1#v}"
	if [ "$expected" != "$ref" ]; then
		echo "FEHLER: Erwartet wurde Version '$expected' (Argument '$1'), im Plugin steht '$ref'." >&2; fail=1
	fi
fi

if [ "$fail" -ne 0 ]; then echo "FAIL: Versionen sind nicht einheitlich."; exit 1; fi
echo "PASS: Version $ref ist überall identisch."
