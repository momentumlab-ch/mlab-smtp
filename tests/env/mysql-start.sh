#!/usr/bin/env bash
# Startet eine wegwerfbare lokale MariaDB/MySQL-Instanz (eigenes Datadir unter tests/env/.run/mysql, nur Unix-Socket,
# kein TCP). Legt Datenbank und Benutzer mit zufälligem Passwort an; Zugangsdaten in tests/env/.run/mysql.php (ignoriert).
set -euo pipefail
ENV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
M="$ENV_DIR/.run/mysql"
DATA="$M/data"; SOCK="$M/mysql.sock"; CRED="$ENV_DIR/.run/mysql.php"
mkdir -p "$M"
SRV="$(command -v mariadbd || command -v mysqld || true)"
[ -n "$SRV" ] || { echo "Kein mariadbd/mysqld gefunden (apt-get install mariadb-server)." >&2; exit 1; }
INST="$(command -v mariadb-install-db || command -v mysql_install_db || true)"
CLI="$(command -v mariadb || command -v mysql || true)"

if [ -f "$M/pid" ] && kill -0 "$(cat "$M/pid")" 2>/dev/null; then echo "MySQL läuft bereits (Socket $SOCK)"; exit 0; fi
rm -f "$SOCK"

if [ ! -d "$DATA/mysql" ]; then
	echo "==> Datadir initialisieren"
	if [[ "$SRV" == *mariadbd ]]; then
		"$INST" --no-defaults --user=root --datadir="$DATA" --auth-root-authentication-method=normal --skip-test-db >"$M/install.log" 2>&1
	else
		"$SRV" --no-defaults --initialize-insecure --user=root --datadir="$DATA" >"$M/install.log" 2>&1
	fi
fi

echo "==> Server starten"
nohup "$SRV" --no-defaults --user=root --datadir="$DATA" --socket="$SOCK" --pid-file="$M/pid" \
	--skip-networking --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_520_ci \
	--innodb-flush-log-at-trx-commit=2 --max-connections=200 --log-error="$M/error.log" >"$M/server.out" 2>&1 &
for _ in $(seq 1 60); do
	[ -S "$SOCK" ] && "$CLI" --no-defaults -uroot -S "$SOCK" -e 'SELECT 1' >/dev/null 2>&1 && break
	sleep 0.5
done
"$CLI" --no-defaults -uroot -S "$SOCK" -e 'SELECT 1' >/dev/null 2>&1 || { echo "Start fehlgeschlagen, siehe $M/error.log" >&2; exit 1; }

if [ ! -f "$CRED" ]; then
	PASS="$(php -r 'echo bin2hex(random_bytes(16));')"
	"$CLI" --no-defaults -uroot -S "$SOCK" <<SQL
CREATE DATABASE IF NOT EXISTS mlab_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
CREATE USER IF NOT EXISTS 'mlab'@'localhost' IDENTIFIED BY '$PASS';
GRANT ALL PRIVILEGES ON mlab_test.* TO 'mlab'@'localhost';
FLUSH PRIVILEGES;
SQL
	umask 077
	cat > "$CRED" <<PHP
<?php
// Generiert von mysql-start.sh. Nicht committen.
return array( 'host' => 'localhost:$SOCK', 'name' => 'mlab_test', 'user' => 'mlab', 'pass' => '$PASS' );
PHP
fi
"$CLI" --no-defaults -uroot -S "$SOCK" -e 'SELECT VERSION()' | tail -1
echo "MySQL bereit (Socket $SOCK). Modus: MLAB_TEST_DB=mysql"
