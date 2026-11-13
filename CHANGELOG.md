# Changelog

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
