#!/usr/bin/env bash
# Reproduzierbare lokale WordPress-Testumgebung (Standard: SQLite). Idempotent.
# MLAB_TEST_DB=mysql: dieselbe WordPress-Installation läuft gegen MySQL/MariaDB (Socket-Instanz aus mysql-start.sh
# oder, z. B. in CI, MLAB_MYSQL_HOST/NAME/USER/PASS). Der SQLite-Modus bleibt unverändert der Standard.
# Umgebungsvariablen: MLAB_ADMIN_PASSWORD (sonst zufällig), MLAB_SITE_TITLE, MLAB_WP_URL, MLAB_TEST_DB.
set -euo pipefail
ENV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$ENV_DIR/../.." && pwd)"
WP="$ENV_DIR/wordpress"
CACHE="$ENV_DIR/.cache"
SQLITE_REPO="https://github.com/WordPress/sqlite-database-integration"
SQLITE_REF="${MLAB_SQLITE_REF:-main}"
URL="${MLAB_WP_URL:-http://localhost:8888}"
MODE="${MLAB_TEST_DB:-sqlite}"
case "$MODE" in sqlite|mysql) ;; *) echo "MLAB_TEST_DB muss sqlite oder mysql sein." >&2; exit 1;; esac
export MLAB_TEST_DB="$MODE"
export COMPOSER_ALLOW_SUPERUSER=1

mkdir -p "$CACHE"

echo "==> Composer: WordPress-Core und WP-CLI"
if [ ! -f "$WP/wp-load.php" ] || [ ! -x "$ENV_DIR/vendor/wp-cli/wp-cli/php/boot-fs.php" ]; then
	(cd "$ENV_DIR" && composer install --no-interaction --no-progress)
fi
# Falls der Core-Installer als root nicht lief, liegt der Core unter vendor/.
if [ ! -f "$WP/wp-load.php" ] && [ -d "$ENV_DIR/vendor/johnpbloch/wordpress-core" ]; then
	mkdir -p "$WP" && cp -a "$ENV_DIR/vendor/johnpbloch/wordpress-core/." "$WP/"
fi
[ -f "$WP/wp-load.php" ] || { echo "WordPress-Core nicht gefunden." >&2; exit 1; }

if [ "$MODE" = "sqlite" ]; then
echo "==> SQLite-Integration"
PLUGIN_DIR="$WP/wp-content/plugins/sqlite-database-integration"
if [ ! -f "$PLUGIN_DIR/load.php" ]; then
	[ -d "$CACHE/sqlite-database-integration/.git" ] || git clone --depth 1 --branch "$SQLITE_REF" "$SQLITE_REPO" "$CACHE/sqlite-database-integration"
	mkdir -p "$WP/wp-content/plugins"
	rm -rf "$PLUGIN_DIR"
	cp -a "$CACHE/sqlite-database-integration" "$PLUGIN_DIR"
	rm -rf "$PLUGIN_DIR/.git"
fi
# Das echte SQLite-Drop-in liegt als db.php.sqlite; db.php ist ein Schalter, der es nur ohne MLAB_TEST_DB=mysql lädt.
if [ ! -f "$WP/wp-content/db.php.sqlite" ]; then
	if [ -f "$WP/wp-content/db.php" ] && grep -q 'SQLITE_' "$WP/wp-content/db.php"; then
		cp "$WP/wp-content/db.php" "$WP/wp-content/db.php.sqlite"
	else
		sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$PLUGIN_DIR#g" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" \
			"$PLUGIN_DIR/db.copy" > "$WP/wp-content/db.php.sqlite"
	fi
fi
if ! grep -q MLAB_TEST_DB "$WP/wp-content/db.php" 2>/dev/null; then
	cat > "$WP/wp-content/db.php" <<'PHP'
<?php
// Generiert von tests/env/setup.sh: im MySQL-Modus wird das SQLite-Drop-in nie geladen.
if ( 'mysql' !== getenv( 'MLAB_TEST_DB' ) ) {
	require __DIR__ . '/db.php.sqlite';
}
PHP
fi
elif [ -f "$WP/wp-content/db.php" ] && grep -q 'SQLITE_' "$WP/wp-content/db.php"; then
	# Vorhandene SQLite-Installation: Drop-in zum Schalter umbauen (im MySQL-Modus wird es nie geladen).
	cp "$WP/wp-content/db.php" "$WP/wp-content/db.php.sqlite"
	printf '%s\n' '<?php' '// Generiert von tests/env/setup.sh: im MySQL-Modus wird das SQLite-Drop-in nie geladen.' \
		"if ( 'mysql' !== getenv( 'MLAB_TEST_DB' ) ) {" "	require __DIR__ . '/db.php.sqlite';" '}' > "$WP/wp-content/db.php"
fi
mkdir -p "$WP/wp-content/database" "$WP/wp-content/uploads"

echo "==> wp-config.php"
if [ ! -f "$WP/wp-config.php" ] || ! grep -q MLAB_TEST_DB "$WP/wp-config.php"; then
	salts="$(php -r 'foreach(["AUTH_KEY","SECURE_AUTH_KEY","LOGGED_IN_KEY","NONCE_KEY","AUTH_SALT","SECURE_AUTH_SALT","LOGGED_IN_SALT","NONCE_SALT"] as $k){echo "define( \x27$k\x27, \x27".bin2hex(random_bytes(32))."\x27 );\n";}')"
	cat > "$WP/wp-config.php" <<PHP
<?php
// Generiert von tests/env/setup.sh. Nicht committen.
// MLAB_TEST_DB=mysql: Zugangsdaten aus MLAB_MYSQL_* oder tests/env/.run/mysql.php; sonst SQLite (Drop-in in db.php).
\$mlab_my = array( 'host' => 'localhost', 'name' => 'wordpress', 'user' => '', 'pass' => '' );
if ( 'mysql' === getenv( 'MLAB_TEST_DB' ) ) {
	if ( false !== getenv( 'MLAB_MYSQL_HOST' ) ) {
		\$mlab_my = array( 'host' => getenv( 'MLAB_MYSQL_HOST' ), 'name' => getenv( 'MLAB_MYSQL_NAME' ), 'user' => getenv( 'MLAB_MYSQL_USER' ), 'pass' => (string) getenv( 'MLAB_MYSQL_PASS' ) );
	} elseif ( is_file( __DIR__ . '/../.run/mysql.php' ) ) {
		\$mlab_my = require __DIR__ . '/../.run/mysql.php';
	} else {
		die( 'MLAB_TEST_DB=mysql, aber keine Zugangsdaten (bash tests/env/mysql-start.sh).' );
	}
}
define( 'DB_NAME', \$mlab_my['name'] );
define( 'DB_USER', \$mlab_my['user'] );
define( 'DB_PASSWORD', \$mlab_my['pass'] );
define( 'DB_HOST', \$mlab_my['host'] );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$salts
\$table_prefix = 'wp_';
\$mlab_host   = \$_SERVER['HTTP_HOST'] ?? '${URL#*://}';
define( 'WP_HOME', 'http://' . \$mlab_host );
define( 'WP_SITEURL', 'http://' . \$mlab_host );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'DISALLOW_FILE_EDIT', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'FS_METHOD', 'direct' );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP
fi

echo "==> Plugin verlinken"
mkdir -p "$WP/wp-content/plugins"
ln -sfn "$ROOT" "$WP/wp-content/plugins/mlab-smtp"

wpcli() { php -d memory_limit=512M "$ENV_DIR/vendor/wp-cli/wp-cli/php/boot-fs.php" --path="$WP" --allow-root "$@"; }

echo "==> WordPress installieren (Modus: $MODE)"
if ! wpcli core is-installed 2>/dev/null; then
	PASS="${MLAB_ADMIN_PASSWORD:-$(php -r 'echo bin2hex(random_bytes(10));')}"
	wpcli core install --url="$URL" --title="${MLAB_SITE_TITLE:-mlab-smtp Test}" --admin_user=admin --admin_password="$PASS" --admin_email=admin@example.test --skip-email
	wpcli rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
	PWFILE="$ENV_DIR/admin-password.txt"; [ "$MODE" = "mysql" ] && PWFILE="$ENV_DIR/.run/admin-password-mysql.txt"
	mkdir -p "$ENV_DIR/.run"; umask 077 && echo "$PASS" > "$PWFILE"
	echo "Admin-Benutzer: admin  Passwort: $PASS  (gespeichert in $PWFILE)"
else
	echo "bereits installiert (Passwort siehe tests/env/admin-password.txt, falls vorhanden)"
fi

echo "==> Plugin aktivieren"
wpcli plugin activate mlab-smtp 2>&1 | tail -2
echo "Fertig. Server starten: bash tests/env/serve.sh"
