#!/usr/bin/env bash
# Baut dist/mlab-smtp-<version>.zip und dist/mlab-smtp.zip (Name des Release-Assets für den Updater) aus dem
# Arbeitsverzeichnis nach .distignore. Reproduzierbar: sortierte Einträge, feste Zeit (Commit-Zeit), feste Modi.
# Aufruf: bash bin/build-zip.sh [ausgabeordner]   (Standard: dist)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
OUT="${1:-dist}"
SLUG="mlab-smtp"
command -v python3 >/dev/null || { echo "FEHLER: python3 fehlt." >&2; exit 2; }

VERSION=$(grep -m1 -E '^[[:space:]*]*Version:' "$SLUG.php" | sed 's/.*Version:[[:space:]]*//; s/[[:space:]]*$//')
[ -n "$VERSION" ] || { echo "FEHLER: Version im Plugin-Header nicht gefunden." >&2; exit 1; }
EPOCH=$(git log -1 --format=%ct 2>/dev/null || true)
[ -n "$EPOCH" ] || EPOCH=${SOURCE_DATE_EPOCH:-315619200}
mkdir -p "$OUT"

python3 -I - "$ROOT" "$OUT" "$SLUG" "$VERSION" "$EPOCH" <<'PY'
import fnmatch, os, sys, time, zipfile

root, out, slug, version, epoch = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4], int(sys.argv[5])
patterns = []
for line in open(os.path.join(root, ".distignore"), encoding="utf-8"):
    line = line.strip()
    if line and not line.startswith("#"):
        patterns.append(line)

def ignored(rel, is_dir):
    parts = rel.split("/")
    for p in patterns:
        dir_only = p.endswith("/")
        p = p.rstrip("/")
        if p.startswith("/"):
            q = p[1:]
            if rel == q or rel.startswith(q + "/") or fnmatch.fnmatchcase(rel, q):
                return True
        else:
            # Name ohne "/" passt auf jede Ebene (Ordner oder Datei).
            for i, part in enumerate(parts):
                last = i == len(parts) - 1
                if dir_only and last and not is_dir:
                    continue
                if fnmatch.fnmatchcase(part, p):
                    return True
    return False

files = []
for dirpath, dirnames, filenames in os.walk(root, followlinks=False):
    rel_dir = os.path.relpath(dirpath, root).replace(os.sep, "/")
    rel_dir = "" if rel_dir == "." else rel_dir
    dirnames[:] = sorted(d for d in dirnames if not ignored((rel_dir + "/" + d).lstrip("/"), True))
    for f in sorted(filenames):
        rel = (rel_dir + "/" + f).lstrip("/")
        full = os.path.join(root, rel)
        if os.path.islink(full) or ignored(rel, False):
            continue
        files.append(rel)
files.sort()

t = time.gmtime(max(epoch, 315619200))[:6]
os.makedirs(out, exist_ok=True)
names = [f"{slug}-{version}.zip", f"{slug}.zip"]
first = os.path.join(out, names[0])
tmp = first + ".tmp"
with zipfile.ZipFile(tmp, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for rel in files:
        info = zipfile.ZipInfo(f"{slug}/{rel}", date_time=t)
        info.compress_type = zipfile.ZIP_DEFLATED
        info.create_system = 3
        # Feste Modi: 0644 für Dateien, nie ausführbar.
        info.external_attr = (0o100644) << 16
        with open(os.path.join(root, rel), "rb") as fh:
            z.writestr(info, fh.read(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
os.replace(tmp, first)
with open(first, "rb") as a, open(os.path.join(out, names[1]), "wb") as b:
    b.write(a.read())
print(f"{len(files)} Dateien")
PY
echo "PASS: gebaut: $OUT/$SLUG-$VERSION.zip und $OUT/$SLUG.zip ($(stat -c%s "$OUT/$SLUG.zip") Bytes)"
