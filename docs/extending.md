# Erweiterungspunkte

Kurzübersicht der Filter und Aktionen des Zustellmotors. Der Kernpfad enthält keine Lizenzabfrage.

## Filter

- `mlab_smtp_route_candidates` (`array $routes`): Wege einer Nachricht in der Reihenfolge, in der sie versucht werden. Jeder Eintrag: `array( 'route' => 'primary'|'fallback', 'provider' => string )`. Wege lassen sich umsortieren oder entfernen. Ungültige Einträge werden ignoriert, eine leere Liste ergibt den Standard.
- `mlab_smtp_queue_before_send` (`bool|WP_Error $proceed`, `array $row`): Der Worker fragt vor dem Senden einer eingereihten Nachricht. `false` oder ein `WP_Error` stellt sie um fünf Minuten zurück (Meldung als letzter Fehler).
- `mlab_smtp_worker_run_phpmailer_init` (`bool`, Standard `false`): `phpmailer_init` auch im Worker ausführen.
- `mlab_smtp_queue_max_bytes` (`int`, Standard 10 MB): Obergrenze der codierten Nachricht für die Warteschlange.
- `mlab_smtp_engine_spawn` (`bool`, Standard `true`): Worker nach dem Einreihen per `spawn_cron()` wecken.

## Aktionen

- `mlab_smtp_message_status_changed` (`int $id`, `string $status`, `string $old`, `string $source`): Der Motor hat den Status einer Nachricht geändert. `$source`: `sync`, `worker`, `manual` oder `enqueue`.
- `mlab_smtp_message_accepted` und `mlab_smtp_message_failed` (Array mit `id`, `uuid`, `status`, `class`, `mode`, `via`, `fallback`, `provider_msg_id`, `error`): Der Anbieter hat angenommen, beziehungsweise die Nachricht ist endgültig fehlgeschlagen, unklar oder teilweise angenommen.

## Funktionen

- `mlab_smtp_engine_summary()`: Zähler (wartend, unklar, endgültig fehlgeschlagen), Wartezeit, Cron-Zustand und Text.
- `mlab_smtp_engine_manual_retry( $id )`, `mlab_smtp_engine_manual_resend( $id )`, `mlab_smtp_engine_mark_delivered( $id )`: manuelle Aktionen, Rückgabe `true` oder `WP_Error`. `mlab_smtp_engine_action_url( $id, 'retry'|'resend'|'delivered' )` liefert die Adresse mit Nonce für `admin-post.php`.
