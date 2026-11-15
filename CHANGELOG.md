# Changelog

## 1.5.1

- Improved: English plugin details, installation instructions, FAQ and external-service disclosures.
- Added: Bundled English dashboard screenshot in the plugin details and a directory screenshot caption.
- Updated: WordPress.org submission checklist; the GitHub update channel remains in place.

## 1.5.0

Grosser Release: dauerhafte Warteschlange, nachvollziehbare Zustellung, Datenschutz, Sicherheitshärtung, vier Sprachen. Bestehende Einrichtungen funktionieren unverändert weiter (SMTP, Microsoft 365, Google, Resend). Anleitung zum Update und zur Wiederherstellung: `docs/upgrade-and-recovery.md`.

### Zuverlässiger Versand
- Neu: Eigene Datenbanktabellen für Nachrichten, Versuche, Ereignisse und Empfänger. Das Protokoll liegt nicht mehr in einer Option, ist durchsuchbar, seitenweise und hat begrenzte Aufbewahrung. Das alte Protokoll wird verlustarm und wiederaufnehmbar übernommen (die alte Option bleibt für eine Version erhalten).
- Neu: Dauerhafte Warteschlange mit atomarer Übernahme, Sperren und Wiederaufnahme nach Abbruch, begrenzten Wiederholungen mit wachsenden Wartezeiten und Zufallsanteil, Beachtung von `Retry-After` und Anbieterlimits, Trennung vorübergehender und dauerhafter Fehler.
- Neu: Statusmodell: wartend, in Verarbeitung, vom Anbieter angenommen, Zustellung bestätigt, vorübergehend fehlgeschlagen, endgültig fehlgeschlagen, teilweise angenommen, Versandstatus unklar, nicht gesendet (Staging-Schutz).
- Neu: Unklare Ergebnisse (zum Beispiel Zeitüberschreitung nach dem Senden) gelten nie als «nicht angenommen». Sie werden nicht automatisch erneut gesendet, ausser bei Resend innerhalb des Idempotenz-Fensters. Manuelle Aktionen: erneut versuchen, als angenommen markieren, erneut senden (Doppelversand möglich).
- Unverändert als Standard: `wp_mail()` sendet synchron und meldet `true` nur, wenn Hauptweg oder Ersatzweg die Mail angenommen haben. Die Warteschlange (`wp_mail()` meldet «angenommen») und die Wiederholung im Hintergrund sind ausdrücklich zuschaltbar, siehe `docs/delivery-modes.md`.
- Neu: Resend-Webhooks (Signatur und Zeitprüfung, Schutz vor Wiederholung und Doppelverarbeitung, Zuordnung auch bei verspäteten oder umsortierten Ereignissen). Zustellung wird nur als bestätigt angezeigt, wenn der Anbieter sie bestätigt hat.

### Hauptweg und Ersatzweg
- Neu: Geführter Ablauf in sieben Schritten für den Ersatzweg (Mailserver oder Resend, eigener Backup-Key, «Sending access» wird unterstützt und erklärt, Domainstatus mit dem Key der richtigen Route, Testmail ausschliesslich über den Ersatzweg, Zusammenfassung und Aktivieren).
- Neu: Bereitschaft statt ausgefüllter Felder: «eingetragen», «geprüft», «getestet», «erneute Prüfung nötig». Eine Änderung an Key, Host, Absender oder Domain macht frühere Ergebnisse ungültig.

### Sicherheit und Datenschutz
- Neu: Zentrale Maskierung von Secrets und Token in Protokoll, Meldungen, Alarmierung und Supportbericht. Secrets erscheinen weder in Formularen noch in REST-Antworten.
- Neu: Verschlüsselung mit Schlüssel-Kennung. Optional `MLAB_SMTP_KEY` in der `wp-config.php`, damit die Zugangsdaten nicht von den Salts abhängen. Es wird nie automatisch ein neuer Schlüssel erzeugt. Unlesbare Secrets werden erkannt und zur Neueingabe angeboten, lesbare lassen sich mit dem aktuellen Schlüssel neu verschlüsseln. Fehlt libsodium, gibt es eine verständliche Meldung statt eines Fatal Errors.
- Neu: Schutz vor Zugriffen auf interne Adressen (SSRF) für den Alarm-Webhook, mit festgeschriebener Adresse und erweiterten IPv6-Bereichen. Unverschlüsseltes SMTP wird deutlich gekennzeichnet. Die TLS-Prüfung wird nie abgeschaltet.
- Neu: Nonces der Nachrichtenaktionen sind an Aktion und Nachricht gebunden.
- Neu: Datenschutz: Empfänger, Betreff und Fehlertexte gelten als sensibel. Aufbewahrung 7, 30 oder 90 Tage mit automatischer Bereinigung, Exporter und Eraser für personenbezogene Daten, Textvorschlag für die Datenschutzerklärung. Standardmässig werden weder Inhalte noch Anhänge im Protokoll gespeichert. Wartende Nachrichten liegen verschlüsselt in der Datenbank und werden nach der Annahme gelöscht.
- Neu: Staging-Schutz: Versand blockieren oder an eine Testadresse umleiten, automatische Erkennung einer Website-Kopie. Auch Nachrichten, die aus einem Produktiv-Klon in der Warteschlange mitkamen, werden nicht gesendet.
- Behoben: Die Staging-Umleitung nannte die ursprünglichen Empfänger im Klartext in der Mail.

### Betrieb und Oberfläche
- Neu: Die Übersicht beantwortet auf einen Blick, ob der Versand funktioniert, welcher Hauptweg aktiv ist, ob der Ersatzweg getestet ist, ob Nachrichten warten und was zu tun ist. Neuer Bereich «Warteschlange», Protokoll mit Seiten, Statusfiltern und Detailansicht (Versuche je Weg, Anbieter-Ereignisse).
- Neu: Alarmierung über einen frei konfigurierbaren Webhook, unabhängig vom Mailweg, mit Testknopf. Maskierter Supportbericht zum Herunterladen.
- Neu: Gesundheitszeilen für eine stehende Warteschlange (inklusive Hinweis auf `DISABLE_WP_CRON` und System-Cron), unklare und endgültig fehlgeschlagene Nachrichten.
- Neu: Vollständige Oberflächentexte auf Deutsch, Englisch, Französisch und Italienisch. Die Sprache folgt der WordPress-Sprache des Benutzers, nicht unterstützte Sprachen erhalten Englisch.
- Hinweise: Mitteilungen anderer Plugins bleiben sichtbar (eingeklappt unter der Seite).
- Verbessert: Texte behaupten keine Zustellung mehr, wo nur die Annahme belegt ist.

### Für Entwickler
- Erweiterungspunkte für Routing, Anbieter, Tabs, Berichte und Berechtigungen, siehe `docs/extending.md`. Der Kernpfad enthält keine Lizenzabfrage.
- Neu: Testumgebung mit echtem WordPress (SQLite und MySQL/MariaDB), Prüfskript `bin/check.sh`, Prüfung und Bau des Release-ZIPs, Plugin Check ohne Befund im eigenen Code, CI.

## 1.4.0

Ersatzweg über Resend, eigener Menüpunkt und klarere Texte im Assistenten.

- Neu: Der Ersatzweg kann auch Resend sein, nicht nur ein Mailserver. Er hat einen eigenen API-Key, wird in den Einstellungen mit «Verbindung prüfen» getestet und sendet mit der hinterlegten Absender-Adresse (deren Domain muss bei Resend verifiziert sein). Bestehende Einrichtungen bleiben beim Mailserver.
- Neu: Eigener Menüpunkt «E-Mail-Versand» mit Papierflieger-Symbol in der Hauptnavigation (vorher unter Einstellungen). Alte Links unter Einstellungen leiten weiter.
- Verbessert: Texte und Schritte im Assistenten sind klarer. Die Schritte heissen wie die Punkte der Übersicht (Anbieter, Verbindung, Absender, Testmail), die Vorbereitung bei Microsoft, Google und Resend zählt nicht mehr eigene «Schritte», und bei jedem Schritt steht, was zu tun ist.
- Beim Update ändert sich an bestehenden Einrichtungen nichts. Der Ersatzweg bleibt beim Mailserver, bis Resend gewählt wird.

## 1.3.0

Neu: Versand über Resend, mit geführter Einrichtung und Fehleranzeige.

- Neu: überarbeitete Oberfläche in allen Ansichten (Übersicht mit Statusbanner, Kennzahlen und Verlauf, Protokoll, Einstellungen mit Speicherleiste, Assistent mit Anbieterliste), einheitliche Bausteine und bedienbar auf dem Smartphone.
- Neu: Resend als vierter Versandweg (REST-API). Der API-Key wird im Assistenten eingegeben und verschlüsselt gespeichert, es muss keine Datei bearbeitet werden.
- Neu: Der Assistent prüft den Key. Ein Key mit «Sending access» (empfohlen) gilt als gültig, die Domain-Prüfung entfällt dann und die Testmail bestätigt sie.
- Neu: Im Schritt «Absender» zeigt der Assistent den Stand der Absender-Domain bei Resend (verifiziert, ausstehend, fehlgeschlagen oder nicht angelegt). Fehlende DNS-Einträge stehen mit Kopierknopf da, «Nochmals prüfen» startet die Prüfung bei Resend. Ist die Domain noch nicht verifiziert, bleibt der Assistent hier stehen und nennt den Weg, statt eine Testmail scheitern zu lassen.
- Neu: Als Absender wird eine Adresse der ersten verifizierten Domain vorgeschlagen.
- Neu: Die Übersicht zeigt den Domain-Status von Resend. Der SPF-Eintrag der Hauptdomain wird bei Resend nicht geprüft, Resend legt ihn auf einer eigenen Subdomain ab.
- Neu: Fehler von Resend erscheinen in Klartext mit Anleitung, im Assistenten, in der Übersicht und im Protokoll: ungültiger oder gesperrter Key, Domain nicht verifiziert, Testadresse resend.dev, Tages- und Monatslimit, zu viele Anfragen, Störung bei Resend, Resend nicht erreichbar, ungültige Angaben. Die technische Meldung bleibt aufklappbar.
- Neu: Mails mit mehr als 50 Empfängern oder über 40 MB Anhängen scheitern vor dem Versand mit einer klaren Meldung und gehen über den Ersatzweg.
- Sicherer: Ein Idempotenz-Schlüssel verhindert, dass eine wiederholte Anfrage dieselbe Mail doppelt versendet.
- Neu: Mitteilungen anderer Plugins erscheinen nicht mehr mitten in der Oberfläche dieses Plugins. Auf der Plugin-Seite liegen sie eingeklappt unterhalb («Mitteilungen anderer Plugins», mit Anzahl und Fehler-Hinweis).
- Neu: Anbieter-Logos (Microsoft, Google, Mailserver) als Symbole statt Buchstaben.
- Intern: Alle Versandwege stehen in einer zentralen Registry (`inc/providers.php` für die Logik, `mlab_smtp_ui_providers()` für die Oberfläche). Ein neuer Anbieter braucht dort je einen Eintrag statt Änderungen an einem Dutzend Stellen. Das Verhalten von SMTP, Microsoft 365 und Google ist unverändert.
- Beim Update ändert sich an bestehenden Einrichtungen nichts.

## 1.2.0

Neue Oberfläche unter *Einstellungen › E-Mail-Versand* und viele Fehlerbehebungen.

- Neu: Einrichtungs-Assistent in 4 Schritten (Anbieter, Verbinden, Absender, Testmail). Der Anbieter wird anhand der Domain vorgeschlagen, Schweizer Hoster sind als Vorlage hinterlegt.
- Neu: Übersicht mit Status, Kennzahlen und Gesundheitsprüfung (Verbindung, SPF, DMARC, Ablauf des Microsoft-Schlüssels, Ersatzweg, Benachrichtigung).
- Neu: Ersatzweg. Fällt die Hauptverbindung aus oder lehnt sie eine Mail ab, geht die Mail über einen zweiten Mailserver.
- Neu: Benachrichtigung bei Fehlern (sofort, höchstens 1× pro Stunde, oder täglich) und Erinnerung, bevor der Microsoft-Schlüssel abläuft.
- Neu: Verbindung prüfen, ohne eine Mail zu senden.
- Neu: Protokoll mit Filter, Suche, Fehlererklärung in Klartext und einstellbarer Aufbewahrung (7, 30 oder 90 Tage).
- Neu: Antwortadresse (Reply-To). Werte aus der wp-config.php werden im GUI als gesperrt angezeigt.
- Behoben: Die Google-Verbindung wurde nie gespeichert, «Trennen» wirkte nicht.
- Behoben: Ohne eingetragenen Mailserver wurde still über den Webserver versendet und die Testmail meldete Erfolg.
- Behoben: Fehlende Absender-Adresse bei Microsoft 365 und Google führte zu unverständlichen Fehlern.
- Behoben: Geänderte Zugangsdaten wirkten erst nach bis zu einer Stunde (Token-Cache), abgelehnte Tokens werden jetzt neu geholt.
- Behoben: Mails über 4 MB bei Microsoft 365 scheitern nicht mehr still, sie gehen über den Ersatzweg.
- Behoben: Nicht mehr lesbare Zugangsdaten (nach Wechsel der WordPress-Salts) werden erkannt und angezeigt.
- Behoben: Doppelt maskierte Zeichen in Fehlermeldungen, gekürzte Passwörter mit Leerzeichen, ungültige Absender-Adresse ohne Meldung.
- Behoben: Der Update-Prüfer brach mit einem Fehler ab (fehlende Bibliothek Parsedown im Release-Paket).
- Sicherer: Google-Anmeldung mit Einmal-Token und PKCE. Beim Löschen werden die Daten aller Seiten einer Multisite entfernt.

## 1.0.2

- Behoben: Antwortete der Mailserver nicht (falscher Port, falsche Verschlüsselung, falsches Passwort), hing die Testmail bis zum Fehler 504. Jeder Schritt bricht jetzt nach 10 Sekunden ab.
- Die Testmail zeigt bei einem Fehler den Grund, einen Hinweis zur Behebung und den Verlauf der Verbindung (ohne Passwort).
- Der Knopf „Testmail senden“ zeigt an, dass gesendet wird, und lässt sich nicht doppelt klicken.

## 1.0.1

- Behoben: Beim ersten Speichern wurden Passwörter und Secrets doppelt verschlüsselt, dadurch schlug der Versand (auch die Testmail) fehl.
- Der Plugin-Name lautet jetzt „MomentumLab SMTP“.

## 1.0.0

- Erste Version.
- Versand über einen eigenen Mailserver (SMTP), Microsoft 365 (Graph-API) oder Google (Gmail-API).
- Testmail und Protokoll der letzten 50 Nachrichten, ohne Inhalt.
- Passwörter, Secrets und Tokens werden verschlüsselt gespeichert oder per Konstante in der wp-config.php gesetzt.
- Updates über GitHub Releases.
