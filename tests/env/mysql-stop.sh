#!/usr/bin/env bash
# Stoppt die lokale Test-MySQL. Mit --purge werden Datadir und Zugangsdaten gelöscht.
ENV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
M="$ENV_DIR/.run/mysql"
if [ -f "$M/pid" ] && kill -0 "$(cat "$M/pid")" 2>/dev/null; then
	kill "$(cat "$M/pid")"
	for _ in $(seq 1 60); do kill -0 "$(cat "$M/pid")" 2>/dev/null || break; sleep 0.5; done
	echo "MySQL gestoppt."
else
	echo "MySQL lief nicht."
fi
if [ "${1:-}" = "--purge" ]; then rm -rf "$M" "$ENV_DIR/.run/mysql.php"; echo "Datadir und Zugangsdaten gelöscht."; fi
