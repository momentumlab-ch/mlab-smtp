#!/usr/bin/env bash
# Prüft ein Plugin-ZIP: Struktur, verbotene Pfade, php -l, Secret-Scan, Sprachdateien und eine echte Installation,
# Aktivierung, Mail-Smoke-Test, Deaktivierung und Deinstallation in einer frischen WordPress-Kopie (SQLite).
# Aufruf: bash bin/verify-zip.sh <zip>
# Exit: 0 alles bestanden, 1 mindestens ein FAIL, 3 Installationstest nicht ausführbar (kein FAIL, aber NICHT bestanden).
# Umgebung: MLAB_REQUIRE_LOCALES=1 macht fehlende Sprachen (en_US, fr_FR, it_IT) zum FAIL, sonst WARN.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="mlab-smtp"
ZIP="${1:-}"
[ -n "$ZIP" ] && [ -f "$ZIP" ] || { echo "Aufruf: bash bin/verify-zip.sh <zip>" >&2; exit 2; }
ZIP="$(cd "$(dirname "$ZIP")" && pwd)/$(basename "$ZIP")"

fails=0; skipped=0
pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*"; fails=$((fails + 1)); }
warn() { echo "WARN: $*"; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
X="$TMP/x"; mkdir -p "$X"
unzip -q "$ZIP" -d "$X" || { fail "ZIP lässt sich nicht entpacken"; exit 1; }
unzip -Z1 "$ZIP" | sort > "$TMP/list.txt"
P="$X/$SLUG"

# 1. Struktur
top=$(cut -d/ -f1 "$TMP/list.txt" | sort -u)
if [ "$top" = "$SLUG" ]; then pass "genau ein Ordner oben: $SLUG/"; else fail "Ordner oben ist '$(echo "$top" | tr '\n' ' ')', erwartet nur '$SLUG'"; fi
for f in "$SLUG.php" readme.txt uninstall.php LICENSE CHANGELOG.md; do
	[ -f "$P/$f" ] && pass "$f vorhanden" || fail "$f fehlt"
done
for d in inc assets languages lib/plugin-update-checker; do
	[ -d "$P/$d" ] && pass "$d/ vorhanden" || fail "$d/ fehlt"
done
hdr() { grep -m1 -E "^[[:space:]*]*$1:" "$P/$SLUG.php" 2>/dev/null | sed "s/.*$1:[[:space:]]*//; s/[[:space:]]*\$//"; }
miss=""
for k in "Plugin Name" Version "Requires at least" "Requires PHP" License "Text Domain"; do [ -n "$(hdr "$k")" ] || miss="$miss '$k'"; done
[ -z "$miss" ] && pass "Plugin-Header vollständig (Version $(hdr Version))" || fail "Plugin-Header ohne:$miss"
stable=$(grep -m1 -i '^Stable tag:' "$P/readme.txt" 2>/dev/null | sed 's/^[^:]*:[[:space:]]*//; s/[[:space:]]*$//')
[ -n "$stable" ] && [ "$stable" = "$(hdr Version)" ] && pass "Stable tag = Version" || fail "Stable tag '$stable' passt nicht zur Version '$(hdr Version)'"

# 2. Verbotene Pfade
bad=$(grep -E '(^|/)(tests|bin|docs|\.git|\.github|node_modules|dist)(/|$)|^[^/]+/vendor(/|$)|(^|/)vendor/bin(/|$)|(^|/)(composer\.(json|lock)|package(-lock)?\.json|phpcs\.xml[^/]*|\.distignore|\.gitignore|wp-config[^/]*\.php|\.env[^/]*)$|\.(log|map|zip|orig|rej|swp)$|~$|(^|/)\.DS_Store$' "$TMP/list.txt" || true)
[ -z "$bad" ] && pass "keine verbotenen Pfade (tests, bin, docs, .git, node_modules, vendor, Konfigurationsdateien)" || { fail "verbotene Pfade im ZIP:"; echo "$bad" | sed 's/^/      /'; }
exe=$(unzip -Z "$ZIP" | awk '$1 ~ /^-..x/ {print $NF}' | head -5)
[ -z "$exe" ] && pass "keine ausführbaren Dateien" || { fail "ausführbare Dateien:"; echo "$exe" | sed 's/^/      /'; }

# 3. php -l
n=0; lint_bad=0
while IFS= read -r -d '' f; do
	n=$((n + 1))
	out=$(php -l "$f" 2>&1) || { echo "$out" | sed "s#$X/##" | sed 's/^/      /'; lint_bad=$((lint_bad + 1)); }
done < <(find "$P" -name '*.php' -print0 | sort -z)
[ "$lint_bad" -eq 0 ] && pass "php -l: $n PHP-Dateien ohne Syntaxfehler" || fail "php -l: $lint_bad von $n Dateien mit Fehler"

# 4. Secret-Scan
ALLOW="$ROOT/bin/secret-scan-allow.txt"
grep -vE '^[[:space:]]*(#|$)' "$ALLOW" 2>/dev/null > "$TMP/allow.txt" || true
: > "$TMP/hits.txt"
scan() { # name ere
	(cd "$X" && grep -rnIE -e "$2" . 2>/dev/null) | sed 's#^\./##' | sed "s#^#[$1] #" >> "$TMP/hits.txt" || true
}
scan "Resend-Key" '\bre_[A-Za-z0-9]{20,}'
scan "Webhook-Secret" 'whsec_[A-Za-z0-9+/=]{16,}'
scan "Bearer-Token" 'Bearer[[:space:]]+[A-Za-z0-9._~+/=-]{20,}'
scan "Private-Key" '-----BEGIN [A-Z ]*PRIVATE KEY-----'
scan "AWS-Key" '(AKIA|ASIA)[0-9A-Z]{16}'
scan "Zuweisung" "(password|passwd|secret|api_?key|access_?token)['\"]?[[:space:]]*(=>|=|:)[[:space:]]*['\"][^'\"\$[:space:]]{8,}['\"]"
scan "Lokaler-Pfad" '(/home/[a-z]|/tmp/|/Users/[A-Za-z]|[A-Z]:\\Users\\|/root/)'
scan "env-Inhalt" '^[A-Z][A-Z0-9_]{3,}=[^[:space:]]{6,}$'
if [ -s "$TMP/allow.txt" ]; then grep -vEf "$TMP/allow.txt" "$TMP/hits.txt" > "$TMP/hits2.txt" || true; else cp "$TMP/hits.txt" "$TMP/hits2.txt"; fi
if [ ! -s "$TMP/hits2.txt" ]; then pass "Secret-Scan: keine Funde ($(find "$X" -type f | wc -l) Dateien, $(wc -l < "$TMP/hits.txt") Treffer durch Erlaubnisliste abgedeckt)"; else
	fail "Secret-Scan: $(wc -l < "$TMP/hits2.txt") Fund(e), Inhalt wird gekürzt gezeigt:"; cut -c1-160 "$TMP/hits2.txt" | head -30 | sed 's/^/      /'; fi

# 5. Sprachen
if [ -f "$P/languages/$SLUG.pot" ]; then pass "languages/$SLUG.pot vorhanden"; else fail "languages/$SLUG.pot fehlt"; fi
po_bad=0; po_n=0
for po in "$P"/languages/*.po; do
	[ -e "$po" ] || continue
	po_n=$((po_n + 1))
	[ -f "${po%.po}.mo" ] || { fail "$(basename "$po") hat keine .mo-Datei"; po_bad=1; }
done
[ "$po_n" -gt 0 ] && [ "$po_bad" -eq 0 ] && pass "alle $po_n .po-Dateien haben eine .mo-Datei"
for loc in en_US fr_FR it_IT; do
	if [ -f "$P/languages/$SLUG-$loc.po" ] && [ -f "$P/languages/$SLUG-$loc.mo" ]; then pass "Sprache $loc vollständig (.po und .mo)"; else
		if [ "${MLAB_REQUIRE_LOCALES:-0}" = "1" ]; then fail "Sprache $loc fehlt im ZIP ($SLUG-$loc.po/.mo)"; else warn "Sprache $loc noch nicht im ZIP ($SLUG-$loc.po/.mo fehlt; mit MLAB_REQUIRE_LOCALES=1 ein FAIL)"; fi
	fi
done

# 6. Installationstest in frischer WordPress-Kopie
ENVWP="$ROOT/tests/env/wordpress"
WPCLI_SRC="$ROOT/tests/env/vendor/wp-cli/wp-cli/php/boot-fs.php"
if [ ! -f "$ENVWP/wp-load.php" ] || [ ! -f "$WPCLI_SRC" ] || [ ! -f "$ENVWP/wp-content/db.php.sqlite" ]; then
	echo "NICHT AUSGEFÜHRT: Installationstest (tests/env fehlt oder ist unvollständig; 'bash tests/env/setup.sh' ausführen)."
	skipped=1
else
	W="$TMP/wp"
	mkdir -p "$W"
	cp -al "$ENVWP/." "$W/" 2>/dev/null || cp -a "$ENVWP/." "$W/"
	rm -rf "$W/wp-content/plugins/$SLUG" "$W/wp-content/database" "$W/wp-content/mu-plugins"
	mkdir -p "$W/wp-content/database" "$W/wp-content/plugins"
	sed -i "s/define( 'WP_DEBUG_DISPLAY', false );/define( 'WP_DEBUG_DISPLAY', true );/" "$W/wp-config.php"
	wp() { php -d memory_limit=512M -d display_errors=1 -d error_reporting=-1 "$WPCLI_SRC" --path="$W" --url=http://localhost:8899 --allow-root "$@"; }
	if wp core install --title=ziptest --admin_user=admin --admin_password="$(php -r 'echo bin2hex(random_bytes(8));')" --admin_email=admin@example.test --skip-email > "$TMP/core.log" 2>&1; then
		pass "frische WordPress-Installation (SQLite) bereit"
		unzip -q "$ZIP" -d "$W/wp-content/plugins"
		wp plugin activate "$SLUG" > "$TMP/act.log" 2>&1; act_rc=$?
		if [ "$act_rc" -eq 0 ] && grep -q 'Success' "$TMP/act.log" && ! grep -qiE 'fatal|warning|notice|deprecated|parse error|stack trace|PHP ' "$TMP/act.log"; then pass "Aktivierung über WP-CLI ohne PHP-Meldungen"; else fail "Aktivierung (Exit $act_rc):"; sed 's/^/      /' "$TMP/act.log" | head -15; fi
		cat > "$TMP/smoke.php" <<'PHP'
<?php
defined( 'MLAB_SMTP_STAGING' ) || define( 'MLAB_SMTP_STAGING', 'off' ); // Staging-Schutz der Testumgebung (local) aus, sonst wird nicht gesendet.
global $wpdb;
$out = array();
foreach ( array( 'messages', 'attempts', 'events', 'recipients' ) as $t ) {
	$name = $wpdb->prefix . 'mlab_smtp_' . $t;
	$out[] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name ? 'TABLE_OK ' : 'TABLE_MISSING ' ) . $t;
}
$hits = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$hits ) {
	$hits[] = $url;
	return array( 'headers' => array(), 'body' => '{"id":"smoke-1"}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
}, 1, 3 );
mlab_smtp_store( array( 'provider' => 'resend', 'setup_done' => 1, 'from_email' => 'info@example.test', 'from_name' => 'Smoke', 'resend_api_key' => 'dummy_key_for_smoke_test' ) );
$failed = '';
add_action( 'wp_mail_failed', static function ( $err ) use ( &$failed ) {
	$failed = $err->get_error_message();
} );
try {
	$ok    = wp_mail( 'to@example.test', 'Smoke', 'Body' );
	$out[] = 'MAIL_DONE ' . ( $ok ? 'true' : 'false' ) . ' http=' . count( $hits ) . ( '' !== $failed ? ' failed=' . $failed : '' );
} catch ( \Throwable $e ) {
	$out[] = 'MAIL_FATAL ' . get_class( $e ) . ': ' . $e->getMessage();
}
echo implode( "\n", $out ), "\n";
PHP
		wp eval-file "$TMP/smoke.php" > "$TMP/smoke.log" 2>&1; [ -n "${MLAB_VERIFY_DEBUG:-}" ] && cat "$TMP/smoke.log"
		if [ "$(grep -c '^TABLE_OK' "$TMP/smoke.log")" -eq 4 ]; then pass "alle 4 Tabellen (messages, attempts, events, recipients) existieren"; else fail "Tabellen fehlen:"; sed 's/^/      /' "$TMP/smoke.log" | head; fi
		if grep -q '^MAIL_DONE' "$TMP/smoke.log" && ! grep -qiE 'fatal|warning|notice|deprecated|stack trace' "$TMP/smoke.log"; then pass "wp_mail()-Smoke-Test ohne Fatal ($(grep '^MAIL_DONE' "$TMP/smoke.log"), HTTP gemockt)"; else fail "wp_mail()-Smoke-Test:"; sed 's/^/      /' "$TMP/smoke.log" | head -15; fi
		wp plugin deactivate "$SLUG" > "$TMP/deact.log" 2>&1 && ! grep -qiE 'fatal|warning|notice|deprecated' "$TMP/deact.log" && pass "Deaktivierung ohne PHP-Meldungen" || { fail "Deaktivierung:"; sed 's/^/      /' "$TMP/deact.log" | head; }
		wp plugin uninstall "$SLUG" > "$TMP/uninst.log" 2>&1 && ! grep -qiE 'fatal|warning|notice|deprecated' "$TMP/uninst.log" && pass "Deinstallation ohne PHP-Meldungen" || { fail "Deinstallation:"; sed 's/^/      /' "$TMP/uninst.log" | head; }
		cat > "$TMP/gone.php" <<'PHP'
<?php
global $wpdb;
$tables = $wpdb->get_col( "SHOW TABLES LIKE '%mlab\_smtp%'" );
$opts   = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%mlab\_smtp%'" );
echo 'LEFT_TABLES ' . count( $tables ) . ' ' . implode( ',', $tables ) . "\n";
echo 'LEFT_OPTIONS ' . count( $opts ) . ' ' . implode( ',', $opts ) . "\n";
echo 'CRON ' . ( wp_next_scheduled( 'mlab_smtp_daily' ) ? 'left' : 'none' ) . "\n";
PHP
		wp eval-file "$TMP/gone.php" > "$TMP/gone.log" 2>&1
		if grep -q '^LEFT_TABLES 0' "$TMP/gone.log" && grep -q '^LEFT_OPTIONS 0' "$TMP/gone.log" && grep -q '^CRON none' "$TMP/gone.log"; then pass "nach Deinstallation sind Tabellen, Optionen und Cron-Ereignis weg"; else fail "Rückstände nach Deinstallation:"; sed 's/^/      /' "$TMP/gone.log" | head; fi
		[ ! -d "$W/wp-content/plugins/$SLUG" ] && pass "Plugin-Ordner nach Deinstallation entfernt" || fail "Plugin-Ordner noch vorhanden"
	else
		fail "frische WordPress-Installation schlug fehl:"; sed 's/^/      /' "$TMP/core.log" | head -10
	fi
fi

echo "----"
if [ "$fails" -gt 0 ]; then echo "FAIL: $fails Prüfung(en) fehlgeschlagen für $(basename "$ZIP")"; exit 1; fi
if [ "$skipped" -gt 0 ]; then echo "NICHT VOLLSTÄNDIG: Installationstest übersprungen (Exit 3), sonst alles bestanden."; exit 3; fi
echo "PASS: $(basename "$ZIP") vollständig geprüft"
