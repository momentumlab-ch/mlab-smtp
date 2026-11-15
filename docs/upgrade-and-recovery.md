# Update, Rückstufung und Wiederherstellung

Gilt für das Update von 1.4.0 auf 1.5.0. Vor jedem Update ein Backup der Datenbank und von `wp-config.php` anlegen.

## Update von 1.4.0

**Was die Migration macht**
- Beim Aktivieren oder beim ersten Aufruf des Backends legt das Plugin vier Tabellen an (`{prefix}mlab_smtp_messages`, `_attempts`, `_events`, `_recipients`). Bei Multisite pro Site.
- Das alte Protokoll (Option `mlab_smtp_log`) wird in Teilen zu 100 Einträgen in `mlab_smtp_messages` übernommen. Jeder Eintrag trägt einen eindeutigen `legacy_key`, deshalb ist die Migration wiederaufnehmbar und erzeugt keine Doppelten. Eine Sperre mit Ablaufzeit verhindert parallele Läufe. Fortschritt und Fehler stehen in der Option `mlab_smtp_migration` und im Backend-Hinweis.
- Bricht sie ab (Timeout, Fehler), läuft sie beim nächsten Backend-Aufruf weiter. Einzelne defekte Einträge werden gezählt (`failed`), blockieren den Rest nicht.
- Die alte Option `mlab_smtp_log` wird weder gelöscht noch weiter beschrieben. Sie bleibt eine Release lang für eine Rückstufung. `uninstall.php` entfernt sie.

**Was bleibt, wie es war**
- Einstellungen (`mlab_smtp_options`), Zugangsdaten, Anbieter, Ersatzweg und Absender. Der Versand bleibt synchron (`wp_mail()` sendet sofort). Warteschlange, Staging-Schutz und Alarmierung sind neue Einstellungen mit konservativen Standardwerten.
- Verschlüsselte Secrets bleiben lesbar (`enc:` und `enc2:`).

**Nach dem Update prüfen:** Übersicht öffnen (Gesundheitsprüfung grün), Testmail senden, Hinweis zur Migration muss «abgeschlossen» zeigen.

## Rückstufung auf 1.4.0

1. Backup der Datenbank.
2. In den Einstellungen den Versandmodus auf «sofort» stellen und warten, bis die Warteschlange leer ist (Übersicht: keine Nachrichten mit Status «wartet» oder «Wiederholung»). Nachrichten «unklar» einzeln entscheiden.
3. Plugin deaktivieren, 1.4.0 hochladen (Ordner `mlab-smtp` ersetzen), aktivieren.

Folgen:
- 1.4.0 kennt die Tabellen nicht und ignoriert sie. Sie bleiben unverändert in der Datenbank (kein Datenverlust, spätere Rückkehr zu 1.5.0 setzt dort fort).
- **Mails, die noch in der Warteschlange liegen, werden von 1.4.0 nicht versendet.** Sie bleiben liegen, bis 1.5.0 wieder aktiv ist. Deshalb zuerst die Warteschlange leeren oder die Empfänger anders informieren.
- Das Protokoll von 1.4.0 zeigt nur den Stand bis zur Migration. Was seit dem Update protokolliert wurde, steht nur in den Tabellen.
- Neue Optionen (Warteschlange, Staging, Alarmierung, Webhook-Secret) ignoriert 1.4.0. Webhooks von Resend werden von 1.4.0 nicht verarbeitet.
- Mit `MLAB_SMTP_KEY` verschlüsselte Secrets (`enc2:`) kann 1.4.0 nicht lesen. Vor der Rückstufung die Konstante entfernen und Secrets neu eintragen, oder bei 1.5.0 bleiben.

## Wiederherstellung

**Secrets unlesbar (Salts oder `MLAB_SMTP_KEY` geändert)**
Secrets sind mit einem Schlüssel aus den WordPress-Salts (`AUTH_SALT`, `AUTH_KEY` usw.) oder, falls gesetzt, aus `MLAB_SMTP_KEY` verschlüsselt. Ändert sich einer davon (Umzug ohne `wp-config.php`, Salts erneuert, Konstante geändert), lassen sich die Werte nicht mehr entschlüsseln. Das Plugin
- markiert sie als unlesbar und bietet die Neueingabe an,
- sendet nicht mit halben Daten und erzeugt **nie automatisch** einen neuen Schlüssel.

Vorgehen: alte `wp-config.php`-Werte (Salts oder `MLAB_SMTP_KEY`) wieder einsetzen, falls vorhanden. Sonst im Backend Passwort, Client-Secret, API-Key und Webhook-Secret neu eintragen. Die Zugangsdaten stehen beim Anbieter (Resend, Entra, Google Cloud) oder beim Mailserver bereit und lassen sich dort neu erzeugen.

**`MLAB_SMTP_KEY`**
- Optionale Konstante in `wp-config.php`: `define( 'MLAB_SMTP_KEY', '<langer zufälliger Text>' );`. Sie entkoppelt die Verschlüsselung von den Salts. Neu gespeicherte Secrets werden mit diesem Schlüssel verschlüsselt (`enc2:` mit Schlüssel-Kennung).
- Werte, die vorher mit dem Salt-Schlüssel verschlüsselt wurden, bleiben lesbar: der Salt-Schlüssel dient als Ersatzschlüssel.
- Den Wert wie ein Passwort sichern (Passwortmanager, Backup von `wp-config.php`). Ohne ihn sind die damit verschlüsselten Werte verloren. Er gehört nicht ins Repository.
- Auch die Queue-Nachrichten (Nutzlast) sind verschlüsselt: ändert sich der Schlüssel, können wartende Nachrichten nicht mehr gesendet werden und müssen neu ausgelöst werden.

**Aus Backup wiederherstellen**
- Datenbank und `wp-config.php` gemeinsam zurückspielen (gleiche Salts). Wird nur die Datenbank auf einer anderen Installation eingespielt, sind Secrets unlesbar (siehe oben).
- Nach dem Einspielen: Übersicht prüfen, Testmail senden. Die Warteschlange kann Mails enthalten, die zum Backup-Zeitpunkt noch offen waren und inzwischen anders versendet wurden. Vor dem Aktivieren des Versands die Liste ansehen und Überholtes löschen.
- Das Plugin erkennt eine geänderte `home_url` und schaltet den Staging-Schutz ein (siehe unten).

**WP-Cron und System-Cron**
Die Warteschlange und die tägliche Bereinigung laufen über WP-Cron (`mlab_smtp_process_queue` jede Minute, `mlab_smtp_daily`). Ist `DISABLE_WP_CRON` gesetzt oder erreicht sich die Website nicht selbst (Loopback gesperrt, Basic-Auth), läuft nichts. Die Übersicht warnt, wenn fällige Mails länger als 10 Minuten warten. Einrichtung:
1. In `wp-config.php`: `define( 'DISABLE_WP_CRON', true );`
2. System-Cron jede Minute: `* * * * * cd /pfad/zur/website && php wp-cron.php >/dev/null 2>&1` oder mit WP-CLI `* * * * * wp --path=/pfad/zur/website cron event run --due-now`.
3. Prüfen: `wp cron event list` zeigt `mlab_smtp_process_queue` mit einer nahen Zeit.

**Staging-Kopien**
Eine Kopie mit anderer `home_url` oder `WP_ENVIRONMENT_TYPE` ungleich `production` blockiert den Versand (Status «blockiert», `wp_mail()` liefert `false`), bis ein Administrator den Betrieb bestätigt. Auf der Kopie nicht bestätigen. Zusätzlich `MLAB_SMTP_STAGING` auf `'block'` oder `'redirect'` setzen. Bei einer echten Umstellung (Domainwechsel) im Backend «Betrieb bestätigen». Webhook- und Alarm-URL der Kopie auf die Kopie anpassen oder leeren, damit sie nicht an Produktivsysteme melden. Siehe `docs/staging-and-alerts.md`.
