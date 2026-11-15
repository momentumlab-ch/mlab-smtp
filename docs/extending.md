# Erweiterungspunkte

Für Zusatzplugins (zum Beispiel eine spätere Pro-Version) und für Anpassungen auf einer Website. Der Kernpfad des Versands enthält **keine Lizenzabfrage**: Eine abgelaufene oder fehlende Lizenz eines Zusatzplugins darf den bestehenden Versand nie berühren. Ein Test prüft das (`tests/integration/extensions.php`).

Alle Namen beginnen mit `mlab_smtp_`. Filter geben immer den Wert zurück, den sie bekommen haben, wenn sie nichts ändern wollen.

## Anbieter und Wege

- `mlab_smtp_providers` (`array $extra`): Weitere Versandwege ergänzen. Rückgabe: `id => Definition` mit allen Feldern aus `inc/providers.php` (`api`, `required`, `group`, `secrets`, `credentials`, `token`, `check`, `send`, `spf`, `health`, `sender_block`, `fb_required`, `fb_check`, `fb_send`). Eingebaute Wege lassen sich nicht ersetzen, unvollständige Einträge werden ignoriert. Funktionsnamen müssen aufrufbar sein.
- `mlab_smtp_ui_providers` (`array $extra`): Anzeigedaten dazu (`name`, `color`, `letter`, `card`, `connect`, `success`, `sender`).
- `mlab_smtp_route_candidates` (`array $routes`): Wege einer Nachricht in der Reihenfolge, in der sie versucht werden (Routing-Regeln, mehrere Verbindungen). Jeder Eintrag: `array( 'route' => 'primary'|'fallback', 'provider' => string )`. Ungültige Einträge werden ignoriert, eine leere Liste ergibt den Standard.

## Berechtigungen und Änderungsprotokoll

- `mlab_smtp_capability` (`string`, Standard `manage_options`): Berechtigung für alle Bereiche, Aktionen und REST-Routen. Eine Rollenverwaltung kann hier eine eigene Berechtigung einsetzen. Ungültige Rückgabewerte fallen auf `manage_options` zurück.
- Aktion `mlab_smtp_settings_changed` (`string[] $names`, `int $user_id`): Nach dem Speichern. **Nur Namen** der geänderten Einstellungen, nie Werte (Secrets bleiben geheim). Grundlage für ein Änderungsprotokoll.

## Zustellmotor

- `mlab_smtp_queue_before_send` (`bool|WP_Error $proceed`, `array $row`): Der Worker fragt vor dem Senden einer eingereihten Nachricht. `false` oder ein `WP_Error` stellt sie um fünf Minuten zurück.
- `mlab_smtp_worker_run_phpmailer_init` (`bool`, Standard `false`): `phpmailer_init` auch im Worker ausführen.
- `mlab_smtp_queue_max_bytes` (`int`, Standard 10 MB): Obergrenze der codierten Nachricht für die Warteschlange.
- `mlab_smtp_failed_payload_days` (`int`): Aufbewahrung des Inhalts endgültig fehlgeschlagener Nachrichten (höchstens 14 Tage).
- `mlab_smtp_engine_spawn` (`bool`, Standard `true`): Worker nach dem Einreihen per `spawn_cron()` wecken.
- Aktion `mlab_smtp_message_status_changed` (`int $id`, `string $status`, `string $old`, `string $source`): Der Motor hat den Status geändert. `$source`: `sync`, `worker`, `manual` oder `enqueue`.
- Aktionen `mlab_smtp_message_accepted` und `mlab_smtp_message_failed` (Array mit `id`, `uuid`, `status`, `class`, `mode`, `via`, `fallback`, `provider_msg_id`, `error`): Der Anbieter hat angenommen, beziehungsweise die Nachricht ist endgültig fehlgeschlagen, unklar oder teilweise angenommen.
- Aktion `mlab_smtp_failure` (`string $error`, `bool $fallback`): Der Hauptweg ist ausgefallen.

## Oberfläche, Berichte, Alarmierung

- `mlab_smtp_admin_tabs` (`array $tabs`, `slug => Bezeichnung`): Eigene Bereiche in der Tab-Leiste. Der Inhalt kommt über die Aktion `mlab_smtp_render_tab_<slug>`.
- `mlab_smtp_support_report_sections` (`array $sections`): Weitere Abschnitte im maskierten Supportbericht. Der fertige Bericht läuft immer noch einmal durch die Maskierung.
- `mlab_smtp_support_cron_hooks` (`string[]`): Cron-Ereignisse, die der Bericht zeigt.
- `mlab_smtp_alert_throttle` (`int` Sekunden, Standard 3600): Mindestabstand je Alarmtyp. `mlab_smtp_alert_stalled_after`: ab wann eine stehende Warteschlange alarmiert.
- `mlab_smtp_alert_dispatch( $event, $data )` (Funktion): einen eigenen Alarm über denselben unabhängigen Webhook senden. So lässt sich später eine zentrale Überwachung mehrerer Websites anbinden.

## Sicherheit und Umgebung

- `mlab_smtp_allowed_url_hosts` / `mlab_smtp_allowed_url_ports`: ausdrücklich erlaubte Hosts (zum Beispiel ein internes Gateway) und Ports für ausgehende Webhook-Adressen. Standard: nur öffentliche Server, Ports 443 und 8443.
- `mlab_smtp_can_change_host` (`bool`, `string $key`): wer auf einer Multisite Host, Port und Alarm-Adresse ändern darf (Standard: Netzwerk-Administratoren). Erlaubte interne SMTP-Server lassen sich so freigeben.
- `mlab_smtp_environment_type` (`string`): Umgebungstyp für den Staging-Schutz (Tests, besondere Umgebungen).
- Konstanten in der `wp-config.php`: `MLAB_SMTP_<SCHLÜSSEL>` setzt eine Einstellung fest, `MLAB_SMTP_KEY` den Verschlüsselungsschlüssel, `MLAB_SMTP_STAGING` den Staging-Modus.

## Nützliche Funktionen

- `mlab_smtp_engine_summary()`: Zähler (wartend, unklar, endgültig fehlgeschlagen), Wartezeit, Cron-Zustand und Text.
- `mlab_smtp_route_state( 'primary'|'fallback' )`: Bereitschaft eines Wegs (eingetragen, geprüft, getestet, veraltet).
- `mlab_smtp_store_list()`, `mlab_smtp_store_counts()`: Nachrichten und Zähler aus den eigenen Tabellen (für erweiterte Berichte).
- `mlab_smtp_engine_manual_retry( $id )`, `mlab_smtp_engine_manual_resend( $id )`, `mlab_smtp_engine_mark_delivered( $id )`: manuelle Aktionen, Rückgabe `true` oder `WP_Error`. Über `admin-post.php` gilt je Aktion und Nachricht ein eigener Nonce (`mlab_smtp_message_nonce_action( $id, $aktion )`).
