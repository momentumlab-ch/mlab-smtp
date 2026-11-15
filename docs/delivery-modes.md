# Versandarten und Zustellmotor (1.5.0)

## Was `wp_mail()` zurückgibt

| Einstellung | `wp_mail()` liefert `true`, wenn … | Bedeutung von `true` |
|---|---|---|
| **Sofort senden** (Standard) | Hauptweg oder Ersatzweg die Mail angenommen haben | vom Anbieter angenommen |
| **Warteschlange** | die Nachricht samt verschlüsseltem Schnappschuss gespeichert ist | nur «zum Versand angenommen», nicht «gesendet» |
| Sofort senden + **Im Hintergrund wiederholen** | zusätzlich: bei einem vorübergehenden Fehler aller Wege die Nachricht in die Warteschlange gelegt wurde | in diesem Fall «zum Versand angenommen» |

Mails mit mehr als 10 MB (Grösse der Base64-Daten) kommen nie in die Warteschlange und werden synchron gesendet. Fehlen die Tabellen oder die Verschlüsselung, sendet `wp_mail()` synchron ohne Protokoll.

## Abweichung bei `wp_mail_succeeded`

WordPress löst `wp_mail_succeeded` aus, sobald `send()` `true` liefert, also auch beim Einreihen. Das lässt sich aus einem Plugin nicht verhindern. Das Plugin geht so damit um:

- Protokoll und Zähler ignorieren das Ereignis beim Einreihen (Flag `quiet`). Eine eingereihte Mail zählt nicht als zugestellt.
- Der Worker löst `wp_mail_succeeded` erneut aus, wenn der Anbieter die Mail wirklich angenommen hat. `mail_data` trägt dann `mlab_context = worker` und `mlab_uuid`. Protokoll und Zähler ignorieren auch dieses Ereignis, der Worker schreibt sie selbst.
- Eigene Aktionen des Plugins: `mlab_smtp_message_accepted` (Anbieter hat angenommen, auch im Worker) und `mlab_smtp_message_failed` (endgültig fehlgeschlagen, unklar oder teilweise). Argument: Array mit `id`, `uuid`, `status`, `class`, `mode`, `via`, `fallback`, `provider_msg_id`, `error`.
- Andere Plugins, die an `wp_mail_succeeded` hängen, sehen das Ereignis beim Einreihen. Sie sollten für verlässliche Aussagen `mlab_smtp_message_accepted` nutzen.

## Fehlerklassen

- **temporär**: HTTP 408, 425, 429, 5xx ausser 501, Verbindungsfehler vor dem Senden, SMTP 4xx. Wird wiederholt.
- **dauerhaft**: HTTP 400, 401, 403, 404, 413, 422 (und andere 4xx, 501), SMTP 5xx, fehlende Zugangsdaten.
- **unklar**: Zeitüberschreitung oder Abbruch, nachdem die Anfrage gesendet wurde; SMTP-Zeitüberschreitung nach dem Daten-Abschluss; Resend 409 (gleicher Idempotency-Key, anderer Inhalt).
- **teilweise** (`partial`): SMTP hat einzelne Empfänger abgelehnt, die übrigen haben die Mail erhalten.

## Wiederholung

Abstand 60 s, doppelt je Versuch, höchstens 6 h, ±20 % Jitter; `Retry-After` hat Vorrang (höchstens 24 h) und pausiert den Anbieter bis zum genannten Zeitpunkt. Höchstzahl der Versuche: Einstellung (Standard 6). Je Nachricht und Weg gibt es einen stabilen Idempotenz-Schlüssel (aus UUID und Weg) und eine stabile Message-ID. Beide werden bei jeder Wiederholung wiederverwendet.

## Unklare Nachrichten

Unklar heisst nie «nicht angenommen». Eine unklare Nachricht wird nicht automatisch erneut gesendet, ausser bei Resend innerhalb von 23 Stunden nach dem ersten Versuch (gleicher Schlüssel, daher kein Duplikat). Bei M365, Google und SMTP entscheidet der Administrator: «Als zugestellt markieren» oder «Erneut senden (Doppelversand möglich)».

Ersatzweg nach unklar: im Modus «Sofort senden» wie bisher aktiv (Einstellung `unclear_failover`, Standard an; der Versuch trägt den Vermerk `fallback_after_unclear`). Für Nachrichten aus der Warteschlange Standard aus (`queue_unclear_failover`).

## Grenzen

- Einstellungen, die ein anderes Plugin nur am PHPMailer-Objekt setzt (zum Beispiel DKIM-Schlüssel), gelten für Nachrichten aus der Warteschlange nicht: `phpmailer_init` läuft im Worker nicht erneut (Filter `mlab_smtp_worker_run_phpmailer_init`, Standard `false`).
- Die Warteschlange läuft über WP-Cron (Ereignis `mlab_smtp_process_queue`, jede Minute). Mit `DISABLE_WP_CRON` oder blockiertem Loopback läuft sie nicht. Empfohlen: ein System-Cron, der jede Minute `wp cron event run --due-now` oder `wp-cron.php` aufruft. Die Gesundheitsanzeige warnt, sobald die älteste fällige Nachricht länger als 10 Minuten wartet.
- Der Inhalt eingereihter und fehlgeschlagener Mails liegt verschlüsselt in der Tabelle. Er wird nach der Annahme sofort gelöscht, bei endgültigem Fehler nach der eingestellten Frist (Standard 7 Tage, höchstens 14).
