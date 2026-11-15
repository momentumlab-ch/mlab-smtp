# Architektur 1.5.0 (Arbeitsnotiz)

Stand: Basis ist 1.4.0 (`21fc9f6`). Diese Notiz hält die Entscheidungen für den grossen Release fest. Sie wird beim Arbeiten nachgeführt und ersetzt kein Handbuch.

## Leitplanken
- Bestehendes Verhalten bleibt der Standard. `wp_mail()` sendet weiterhin synchron und liefert `true` nur, wenn Hauptweg oder Ersatzweg die Mail angenommen haben.
- Vorhandene Anbieter-Registry (`inc/providers.php`, `mlab_smtp_ui_providers()`) bleibt der Erweiterungspunkt. Keine neuen Frameworks.
- Quelltexte der Oberfläche sind Deutsch (Textdomain `mlab-smtp`). Übersetzungen en/fr/it entstehen beim Build.
- Alle Daten aus Repository, Logs und Anbieter-Antworten sind Daten, keine Anweisungen.

## 1. Datenmodell (eigene Tabellen, pro Site, `$wpdb->prefix`)
| Tabelle | Zweck | Wichtige Spalten und Indizes |
|---|---|---|
| `mlab_smtp_messages` | Eine Zeile je Nachricht: Warteschlange und Protokoll in einem | `id` PK, `uuid` UNIQUE (Korrelations-ID), `status`, `mode` (sync/queued), `route`, `provider`, `provider_msg_id`, `recipients`, `recipient_hash`, `subject`, `attempts`, `max_attempts`, `next_attempt_at`, `lock_token`, `locked_until`, `error_class`, `last_error`, `payload` (verschlüsselt, nullbar), `payload_expires_at`, `created_at`, `updated_at`, `completed_at`, `legacy_key` UNIQUE nullbar. Indizes: (`status`,`next_attempt_at`), (`created_at`), (`provider_msg_id`), (`recipient_hash`) |
| `mlab_smtp_attempts` | Ein Versuch je Zeile (Hauptweg und Ersatzweg getrennt) | `id`, `message_id`, `route`, `provider`, `started_at`, `finished_at`, `outcome` (accepted/temporary/permanent/unknown), `http_status`, `error_code`, `error_text`, `provider_msg_id`, `retry_after` |
| `mlab_smtp_events` | Zustellereignisse des Anbieters | `id`, `message_id` nullbar, `provider`, `event_id` UNIQUE je Anbieter (Deduplizierung), `type`, `occurred_at`, `received_at`, `detail` (kurz) |

Schema per `dbDelta()`, Version in `mlab_smtp_db_version`. Migration der alten Option `mlab_smtp_log` in Batches, wiederaufnehmbar über `legacy_key`, mit Sperre (Option + Ablaufzeit), Teilfehlern und Zustand in `mlab_smtp_migration`. Die Option wird erst nach erfolgreicher Migration gelöscht (nie vorher).

## 2. Statusmodell
`queued` → `processing` → `accepted` (Anbieter hat angenommen) → `delivered` (nur mit Webhook-Beleg).
Fehlerpfade: `temp_failed` (wird wiederholt), `failed` (endgültig), `unclear` (Versandstatus unklar), `blocked` (Staging-Schutz, nicht gesendet).
Ein Ereignis stuft nie zurück: Rang `accepted` < `delivered` < `bounced`/`complained`. Späte oder umsortierte Ereignisse ändern nur, was sie höher einstufen.

## 3. Versandmodi und wp_mail-Semantik
- `sync` (Standard, kompatibel): `wp_mail()` versendet sofort. `true` = vom Anbieter angenommen. Bei Fehler: Ersatzweg, sonst `false` und `wp_mail_failed`.
- `queue` (ausdrücklich gewählt): `wp_mail()` liefert `true`, sobald die Nachricht dauerhaft in der Queue liegt. Das bedeutet «angenommen», nicht «gesendet». Versand durch den Worker.
- Option «Im Hintergrund wiederholen» (nur `sync`, Standard aus): bei einem vorübergehenden Fehler beider Wege wird die Nachricht in die Queue gelegt und `wp_mail()` liefert `true`. In der Oberfläche steht die geänderte Bedeutung.
- Nachrichten mit mehr Anhängen als das Limit (Standard 10 MB gesamt) werden nie in die Queue genommen, sondern synchron versendet.

## 4. Worker, Sperren, Wiederholung
- Aufruf: WP-Cron-Ereignis `mlab_smtp_process_queue` (Intervall 1 Minute) und nach dem Einreihen per `spawn_cron()`. Ein echter System-Cron wird in der Hilfe empfohlen.
- Atomare Übernahme: ein einzelnes `UPDATE … SET status='processing', lock_token=<zufällig>, locked_until=<jetzt+Lease> WHERE status IN ('queued','temp_failed') AND next_attempt_at <= jetzt AND (locked_until IS NULL OR locked_until < jetzt) ORDER BY next_attempt_at LIMIT n`, danach `SELECT … WHERE lock_token = …`. Zeilensperren der DB machen die Übernahme exklusiv.
- Abschluss nur mit passendem `lock_token`, sonst verworfen (der Worker hat die Lease verloren).
- Worker-Abbruch: Zeilen mit abgelaufener Lease und Status `processing` gelten als **unklar**, weil die Annahme beim Anbieter nicht ausgeschlossen ist.
- Wiederholung: Basis 60 s, Verdopplung je Versuch, Obergrenze 6 h, ±20 % Jitter, höchstens 6 Versuche. `Retry-After` hat Vorrang (gedeckelt auf 24 h). Bei 429 pausiert der Worker für diesen Anbieter bis zum genannten Zeitpunkt.
- Fehlerklassen: **temporär** (Verbindungsfehler vor dem Senden, HTTP 408/425/429/5xx ausser 501, SMTP 4xx), **dauerhaft** (HTTP 400/401/403/404/413/422, SMTP 5xx), **unklar** (Zeitüberschreitung oder Verbindungsabbruch, nachdem die Anfrage gesendet wurde).

## 5. Unklare Ergebnisse und Doppelversand
- Unklar heisst nie «nicht angenommen». Eine unklare Nachricht wird **nicht automatisch erneut gesendet**, ausser der Anbieter bietet nachweisbare Idempotenz: Resend mit Idempotency-Key innerhalb von 24 h (gleicher Key, gleiche Nachricht). Dann ist die Wiederholung sicher und erlaubt.
- Bei M365, Google und SMTP gibt es keine Idempotenz. Unklare Nachrichten landen in der Liste «Versandstatus unklar». Aktionen: «Als zugestellt markieren» oder «Erneut senden (Doppelversand möglich)».
- Ersatzweg nach einem unklaren Hauptweg: bleibt wie bisher aktiv (Verlust ist schlimmer als ein seltenes Duplikat), wird aber als `fallback_after_unclear` protokolliert und in der Oberfläche benannt. Eine Einstellung «Bei unklarem Ergebnis nicht auf den Ersatzweg wechseln» ist verfügbar.
- Anbieterübergreifende Idempotenz wird nicht behauptet.

## 6. Datenschutz
- Standard: weder Nachrichteninhalt noch Anhänge im Protokoll. Das Protokoll hält Empfänger, Betreff, Status, Fehler (als sensibel behandelt).
- Queue-Nutzlast (nur bei `queue` oder Hintergrund-Wiederholung): mit libsodium verschlüsselt in der Tabelle, Anhänge im selben Feld. Keine Dateikopie im Dateisystem, also nichts öffentlich Erreichbares. Löschung sofort nach Annahme; bei endgültigem Fehler nach Frist (Standard 7 Tage, höchstens 14), bleibt manuell wiederholbar bis dahin.
- Aufbewahrung des Protokolls 7/30/90 Tage, tägliche Bereinigung in Batches. Eingebunden: WordPress-Datenschutz-Exporter und -Eraser (Suche über `recipient_hash`), Textvorschlag für die Datenschutzerklärung.
- Fehlertexte, Empfänger und Betreff laufen durch die zentrale Maskierung, bevor sie in Supportberichte, Alarmierung oder REST-Antworten gehen.

## 7. Sicherheit
- Zentrale Hilfen: `mlab_smtp_redact()` (Secrets und Muster), `mlab_smtp_safe_url()` (nur https, keine privaten, Loopback-, Link-Local- und Metadaten-Adressen nach DNS-Auflösung, keine Weiterleitungen), `mlab_smtp_require_admin()` (Capability + Nonce), Feld-Allowlists für REST.
- Sodium-Prüfung vor jeder Nutzung; fehlt es, gibt es eine verständliche Meldung und der Versand läuft nicht mit halben Daten.
- Schlüssel: bisher aus `wp_salt('auth')`. Neu optional `MLAB_SMTP_KEY` in der `wp-config.php` (entkoppelt von den Salts). Chiffretext-Format mit Schlüssel-Kennung (`enc2:`), `enc:` bleibt lesbar. Es wird nie automatisch ein neuer Schlüssel erzeugt. Unlesbare Secrets bleiben als solche markiert und werden zur Neueingabe angeboten (Wiederherstellungsanleitung in der Doku).
- TLS-Prüfung wird nie abgeschaltet. SMTP ohne Verschlüsselung ist deutlich gekennzeichnet (Warnbanner, Gesundheitszeile).
- Multisite: Host und Port ändern nur Netzwerk-Administratoren (vorhanden), erlaubte interne SMTP-Hosts über Filter `mlab_smtp_allowed_hosts` und Netzwerk-Einstellung.

## 8. Ersatzweg-Ablauf und Bereitschaft
Sieben Schritte (Anbieter wählen, Voraussetzungen und Links, Zugangsdaten prüfen, tatsächlichen Absender zeigen, Domainstatus, Testmail ausschliesslich über den Ersatzweg, Zusammenfassung und Aktivieren).
Bereitschaft je Weg mit Zuständen **eingetragen**, **geprüft**, **Test erfolgreich**, **erneute Prüfung nötig**. Jedes Ergebnis trägt einen Fingerabdruck (Anbieter, Key, Host, Absender, Domain). Ändert sich etwas davon, wird der Zustand «erneute Prüfung nötig». Resend: eigener Backup-Key, «Sending access» wird erklärt und unterstützt, Domainstatus mit dem Key der jeweiligen Route.

## 9. Webhooks (Resend, Svix-Signatur)
- Route `POST /wp-json/mlab-smtp/v1/webhooks/resend`. Berechtigung `__return_true`, die Echtheit entscheidet allein die Signatur.
- Prüfung: Header `svix-id`, `svix-timestamp`, `svix-signature`; Signatur = Base64(HMAC-SHA256(`<id>.<timestamp>.<body>`, Base64-dekodiertes Secret ohne Präfix `whsec_`)), Vergleich in konstanter Zeit, Toleranz ±5 Minuten, Body-Grösse begrenzt.
- Replay und Duplikate: `event_id` (svix-id) ist je Anbieter eindeutig, Wiederholung wird mit 200 bestätigt und nicht erneut verarbeitet.
- Zuordnung: über `provider_msg_id` (`data.email_id`) und das Tag `mlab_id` (UUID). Trifft ein Ereignis vor dem gespeicherten Anbieter-ID ein, wird es ohne `message_id` gespeichert und beim Speichern der ID nachgezogen.
- Unbekannte Ereignistypen werden bestätigt und ignoriert. Kein Tracking von Öffnen und Klicks.
- Secret verschlüsselt gespeichert, nie in Formularen oder Antworten.

## 10. Betrieb
- Staging-Schutz: Modus `aus` / `blockieren` / `umleiten an Testadresse` / `automatisch`. Automatisch erkennt eine Kopie (andere `home_url` als beim Einrichten gespeichert, oder `wp_get_environment_type()` ≠ production) und blockiert, bis ein Administrator den Betrieb ausdrücklich bestätigt. Blockierte Mails sind `blocked` im Protokoll und `wp_mail()` liefert `false`.
- Alarmierung: Webhook-URL (https, `mlab_smtp_safe_url()`), optional HMAC-Signatur, Ereignisse: Hauptweg gestört, Ersatzweg aktiv, Queue staut, endgültige Fehler. Eigener Testknopf, unabhängig vom Mailweg.
- Supportbericht: maskiert, wird bei Bedarf erzeugt und als Download ausgeliefert, nie gespeichert.
- Hinweise anderer Plugins: bleiben unten eingeklappt, Fehler sind sichtbar markiert (vorhanden). Wichtige Meldungen werden nicht versteckt.

## 11. Erweiterungspunkte für Pro (ohne Lizenzlogik)
Filter und Aktionen (dokumentiert in `docs/extending.md`): `mlab_smtp_providers`, `mlab_smtp_route_candidates` (Routing), `mlab_smtp_queue_before_send`, `mlab_smtp_message_status_changed`, `mlab_smtp_admin_tabs`, `mlab_smtp_support_report_sections`, `mlab_smtp_capabilities`. Eine abgelaufene Lizenz darf den Versand nie berühren: Kernpfad enthält keine Lizenzabfrage.

## 12. Qualität und Auslieferung
- Tests im Repo (`tests/`): Unit-Tests mit Stubs und Integrationstests gegen echtes WordPress (SQLite) mit simulierten Anbietern. Ein Aufruf `bin/check.sh` führt Lint, phpcs, Tests, POT-Aktualität, Versionskonsistenz, Plugin Check (wenn verfügbar) und ZIP-Prüfung aus. Der Release-Workflow bricht bei Fehlern ab.
- Übersetzungen: POT aus dem Code (WP-CLI i18n), `.po`/`.mo` für en_US, fr_FR, it_IT; Platzhalter-, Plural- und HTML-Prüfung per Skript.
- Updatekanal (GitHub-Release) bleibt. Notizen für eine Veröffentlichung im WordPress.org-Verzeichnis stehen getrennt in `docs/directory-submission.md`.

## 13. Verbindliche Änderungen nach dem Architektur-Review
Dieser Abschnitt hat Vorrang vor den Abschnitten 1 bis 12, wo er ihnen widerspricht.

**Claim (ersetzt Abschnitt 4, Übernahme).** Kein `UPDATE … ORDER BY … LIMIT` (SQLite und Replikation). Stattdessen: `SELECT id` der Kandidaten, dann je Zeile `UPDATE … SET status='processing', lock_token=%s, locked_until=%s WHERE id=%d AND status IN ('queued','temp_failed') AND (locked_until IS NULL OR locked_until < %s)`. `affected_rows === 1` heisst übernommen. Die Prüfung abgelaufener Leases nutzt dieselbe Compare-and-set-Abfrage.

**Lease und «unklar».** Lease mindestens 5 Minuten, beginnt direkt vor dem Senden, Worker ruft `set_time_limit()`. Vor jedem Netzwerkzugriff wird ein Versuch mit `request_sent_at` geschrieben. Stirbt ein Worker davor, geht die Nachricht zurück in die Queue. Stirbt er danach, ist sie **unklar**.

**Idempotenz.** Je Nachricht und Weg wird `idem_key` (aus UUID und Weg) und die `Message-ID` gespeichert und bei jeder Wiederholung wiederverwendet (`$mailer->MessageID` aus dem gespeicherten Wert). Resend antwortet bei gleichem Key und anderem Inhalt mit 409: gespeichert wird deshalb der endgültig gebaute Inhalt, und 409 gilt als «unklar», nicht als dauerhafter Fehler. Kein automatisches Wiederholen unklarer Nachrichten später als 23 Stunden nach dem ersten Versuch, auch nicht bei grossem `Retry-After`.

**Ersatzweg nach unklar.** Im Modus `sync` unverändert (Kompatibilität, mit Protokollvermerk). Für Queue-Nachrichten Standard «kein Wechsel», weil die Liste «unklar» sie zeigt. SMTP gilt nur als unklar bei Zeitüberschreitung nach dem Daten-Abschluss. Abgelehnte Empfänger bei SMTP sind eine **teilweise Annahme** (`partial`).

**Queue-Inhalt (ersetzt Abschnitt 3 und 6, Nutzlast).** Gespeichert wird ein Schnappschuss der fertig gebauten Nachricht nach `preSend` (Absender, Empfänger, Cc, Bcc, Antwortadresse, Betreff, HTML, Text, eigene Header, Anhänge als Base64 mit Name, Typ, Inhalt, CID, Message-ID), nicht die `wp_mail()`-Argumente. Der Worker baut daraus einen neuen Mailer, führt `phpmailer_init` nicht erneut aus (Filter `mlab_smtp_worker_run_phpmailer_init`, Standard aus) und beendet mit `wp_mail_succeeded` im Worker mit Kontext-Kennzeichen, nie beim Einreihen. Obergrenze 10 MB bezieht sich auf die Base64-Grösse, Spalte `LONGBLOB`, verschlüsselt. Pfade von Anhängen werden beim Einreihen gelesen. Grenze: Einstellungen, die `phpmailer_init` nur am PHPMailer-Objekt setzt (zum Beispiel DKIM-Schlüssel eines anderen Plugins), gelten für Queue-Nachrichten nicht. Das steht in der Hilfe.

**Empfänger-Index (ersetzt `recipient_hash`).** Tabelle `mlab_smtp_recipients(message_id, hmac)` mit HMAC-SHA256 der kleingeschriebenen, getrimmten Adresse. Der Schlüssel ist zufällig, pro Site in einer Option (`mlab_smtp_hmac_key`), nicht aus den Salts abgeleitet. Der Eraser löscht Nachricht, Versuche (`error_text`), Ereignisse (`detail`) und Index. Ein Salt-Wechsel macht verschlüsselte Queue-Nutzlasten unlesbar: diese Nachrichten werden `failed` mit genannter Ursache, nicht `unclear`.

**Webhooks (ergänzt Abschnitt 9).** `svix-signature` kann mehrere durch Leerzeichen getrennte `v1,<signatur>` enthalten: ein Treffer genügt. Geprüft wird der rohe Body. Schlägt die Verarbeitung fehl, wird die Deduplizierungszeile wieder entfernt, damit die Wiederzustellung nicht verschluckt wird. Zuordnung zuerst über das Tag `mlab_id`, dann über `provider_msg_id`. Verwaiste Ereignisse werden nach 7 Tagen entfernt. `email.failed` und `email.delivery_delayed` sind im Rang. Ein Ereignis löst den Status «unklar» auf (`delivered`, `bounced`, `complained` endgültig, `sent` führt zu `accepted`). Haupt- und Ersatz-Konto haben getrennte Endpunkte (`/webhooks/resend` und `/webhooks/resend-fallback`) und Secrets.

**Multisite und Migration.** Tabellen werden pro Site angelegt, jede Site prüft beim Zugriff ihre `db_version` (billiger Optionswert) und rüstet lazy auf. `wp_initialize_site` legt Tabellen für neue Sites an, `wpmu_drop_tables` entfernt sie. `wp_mail()` funktioniert auch bei fehlenden Tabellen (synchron, ohne Protokoll). Migrationssperre per `add_option()` (atomar). Nach erfolgreicher Migration wird die alte Option nicht mehr geschrieben und eine Release lang behalten (Rückstufung bleibt lesbar), `uninstall.php` löscht sie und die Tabellen.

**Ergänzungen.**
- Ein synchroner Versand schreibt seine Zeile **vor** dem Senden (Status `processing`), damit ein Fatal Error mitten im Versand eine Spur hinterlässt. Eine Aufräumroutine setzt verwaiste `processing`-Zeilen nach Ablauf der Lease auf `unclear`.
- Mit `DISABLE_WP_CRON` oder blockiertem Loopback läuft die Queue nicht: Gesundheitszeile und Hinweis, sobald die älteste fällige Nachricht länger als 10 Minuten wartet, dazu Anzeige des Cron-Status und Empfehlung für System-Cron.
