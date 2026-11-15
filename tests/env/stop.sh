#!/usr/bin/env bash
# Stoppt den Testserver.
ENV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUN="$ENV_DIR/.run"
if [ -f "$RUN/pid" ]; then
	kill "$(cat "$RUN/pid")" 2>/dev/null && echo "Server gestoppt." || echo "Server lief nicht mehr."
	rm -f "$RUN/pid" "$RUN/url"
else
	echo "Kein Server gestartet."
fi
