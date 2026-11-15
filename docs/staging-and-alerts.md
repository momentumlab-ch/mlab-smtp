# Staging-Schutz, Alarmierung und Supportbericht

## Staging-Schutz
Modi: `aus`, `blockieren`, `umleiten` (an eine Testadresse), `automatisch` (Standard). Die Konstante `MLAB_SMTP_STAGING` (`'off'`, `'block'`, `'redirect'`) in der `wp-config.php` übersteuert die Einstellung.

Automatisch blockiert, wenn die Adresse der Website (`home_url()`) nicht mehr zur gespeicherten passt oder WordPress eine Umgebung ausser `production` meldet. Ein Administrator bestätigt mit «Das ist die produktive Website» oder wählt «umleiten». Ändern sich nur Pfad, Datenbank-Host oder Datenbankname, gibt es nur eine Warnung, der Versand läuft weiter.

- Blockiert: kein Provider-Aufruf, `wp_mail()` liefert `false`, `wp_mail_failed` wird ausgelöst, das Protokoll zeigt den Status `blocked`.
- Umgeleitet: Empfänger wird die Testadresse, Cc und Bcc entfallen, Betreff beginnt mit `[Staging] `, am Ende steht die Liste der ursprünglichen Empfänger, **nur maskiert** (`k***@e***.ch`), damit echte Adressen nicht zum Anbieter gelangen. Die Umleitung hat das letzte Wort: Auch Filter anderer Plugins, die später Cc, Bcc oder Empfänger ergänzen, ändern nichts mehr.
- Warteschlange: Der Worker prüft den Staging-Schutz vor jedem Senden. Eine Nachricht, die aus einer anderen Umgebung stammt (zum Beispiel aus einem Produktiv-Klon, erkennbar an der Adresse der Website beim Einreihen), wird nicht gesendet. Sie bekommt den Status `blocked`, ihr Inhalt wird gelöscht, und auch «erneut versuchen» ändert daran nichts. Im Modus `blockieren` gilt das für jede wartende Nachricht, auch für eigene, die vor dem Umschalten eingereiht wurden. Im Modus `umleiten` laufen nur Nachrichten dieser Umgebung weiter, die bereits umgeleitet eingereiht wurden. Im Modus `aus` wird gesendet.
- Ein Hinweis erscheint auf allen Admin-Seiten, solange blockiert, umgeleitet oder unbestätigt.

Optionen: `mlab_smtp_staging` (kein Autoload). Filter `mlab_smtp_environment_type` überschreibt den Umgebungstyp.

## Alarm-Webhook
Eine https-Adresse (wird beim Speichern und beim Senden mit `mlab_smtp_safe_url()` geprüft, keine Weiterleitungen), optional ein Signatur-Secret (verschlüsselt gespeichert, wird nie angezeigt). Header: `X-Mlab-Signature: sha256=<HMAC-SHA256 des Bodys>` und `X-Mlab-Timestamp`.

Ereignisse: `primary_failing`, `fallback_active`, `queue_stalled`, `final_failures`. Pro Ereignis höchstens einmal pro 60 Minuten (Filter `mlab_smtp_alert_throttle`). Der Inhalt enthält Host, Ereignis, Zeit, Anzahl und einen kurzen maskierten Text, nie Empfänger, Betreffe oder Secrets. Ein Fehler beim Senden wird gemerkt (letztes Ergebnis, Zeit, HTTP-Code) und wirft nie. Der Testknopf sendet ein deutlich markiertes Testereignis. Optionen: `mlab_smtp_alerts`, `mlab_smtp_alerts_state`.

## Supportbericht
Wird bei Bedarf erzeugt (`admin-post.php?action=mlab_smtp_support_report`, Nonce und `manage_options`) und nie gespeichert. Er enthält Versionen, Wege, Gesundheitszeilen, Statuszähler, Cron, gesetzte Konstanten (nur Namen) und die letzten 20 Einträge mit maskierten Adressen ohne Betreff. Erweiterung über den Filter `mlab_smtp_support_report_sections`.
