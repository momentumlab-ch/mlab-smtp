#!/usr/bin/env bash
# Startet den PHP-Built-in-Server auf einem freien Port (oder PORT=...). Schreibt URL nach tests/env/.run/url.
# MLAB_TEST_DB=mysql: Der Server läuft gegen MySQL/MariaDB (wird bei Bedarf über mysql-start.sh gestartet, außer MLAB_MYSQL_HOST ist gesetzt).
set -euo pipefail
ENV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUN="$ENV_DIR/.run"
mkdir -p "$RUN"
if [ "${MLAB_TEST_DB:-sqlite}" = "mysql" ] && [ -z "${MLAB_MYSQL_HOST:-}" ]; then bash "$ENV_DIR/mysql-start.sh" >/dev/null; fi
export MLAB_TEST_DB="${MLAB_TEST_DB:-sqlite}"
[ -f "$ENV_DIR/wordpress/wp-config.php" ] || { echo "Zuerst: bash tests/env/setup.sh" >&2; exit 1; }
if [ -f "$RUN/pid" ] && kill -0 "$(cat "$RUN/pid")" 2>/dev/null; then
	echo "Läuft bereits: $(cat "$RUN/url")"
	exit 0
fi
PORT="${PORT:-$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0");echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT);')}"
HOST="127.0.0.1:$PORT"
PHP_CLI_SERVER_WORKERS=4 nohup php -d memory_limit=512M -S "$HOST" -t "$ENV_DIR/wordpress" "$ENV_DIR/router.php" >"$RUN/server.log" 2>&1 &
echo $! > "$RUN/pid"
echo "http://$HOST" > "$RUN/url"
for _ in $(seq 1 30); do
	curl -s -o /dev/null "http://$HOST/wp-login.php" && break
	sleep 0.3
done
echo "http://$HOST (wp-admin: http://$HOST/wp-admin/)"
