# Screenshots

Alle Bilder zeigen die echte Test-WordPress-Umgebung (tests/env, SQLite) mit **Demo-Daten** (nur Beispieladressen @example.test, erfundene Schlüssel, kein Netzwerkzugriff). Erzeugt mit `node bin/screenshots.js`, Daten aus `php bin/seed-demo.php`. Stand: 15.11.2026.

- `01-uebersicht.png` (15.11.2026): Übersicht im Normalzustand: Hauptweg getestet, Ersatzweg eingetragen, nicht geprüft.
- `02-uebersicht-stoerung.png` (15.11.2026): Übersicht mit unklarer Nachricht und Hinweis, dass die Warteschlange nicht abgearbeitet wird.
- `03-warteschlange.png` (15.11.2026): Warteschlange mit wartenden, laufenden, unklaren und fehlgeschlagenen Mails.
- `04-protokoll.png` (15.11.2026): Protokoll mit Filtern, Suche und Liste aller Status.
- `05-protokoll-detail.png` (15.11.2026): Protokoll-Detail einer Mail mit Versuchen (Hauptweg fehlgeschlagen, Ersatzweg) und Zustellereignissen.
- `06-einstellungen.png` (15.11.2026): Einstellungen, oberer Teil (Verbindung, Ersatzweg).
- `06-einstellungen-sicherheit.png` (15.11.2026): Einstellungen, Abschnitt Staging-Schutz weiter unten.
- `07-assistent-anbieter.png` (15.11.2026): Einrichtungsassistent, Schritt 1: Anbieter wählen.
- `08-assistent-resend-key.png` (15.11.2026): Einrichtungsassistent, Schritt 2: Resend mit API-Key verbinden.
- `09-ersatzweg-schritt-1.png` (15.11.2026): Ersatzweg einrichten, Schritt 1: Anbieter.
- `09-ersatzweg-schritt-6.png` (15.11.2026): Ersatzweg einrichten, Schritt 6: Testmail nur über den Ersatzweg.
- `10-ersatzweg-zusammenfassung-1-nicht-eingetragen.png` (15.11.2026): Zusammenfassung Ersatzweg, Zustand 1: nicht eingetragen.
- `10-ersatzweg-zusammenfassung-2-nicht-geprueft.png` (15.11.2026): Zusammenfassung Ersatzweg, Zustand 2: eingetragen, nicht geprüft.
- `10-ersatzweg-zusammenfassung-3-getestet.png` (15.11.2026): Zusammenfassung Ersatzweg, Zustand 3: getestet.
- `10-ersatzweg-zusammenfassung-4-veraltet.png` (15.11.2026): Zusammenfassung Ersatzweg, Zustand 4: erneute Prüfung nötig (nach Änderung).
- `11-staging-blockiert.png` (15.11.2026): Staging-Schutz blockiert den Versand, Hinweis oben im Admin.
- `12-uebersicht-mobil.png` (15.11.2026): Übersicht auf dem Handy (390 px).
- `13-warteschlange-mobil.png` (15.11.2026): Warteschlange auf dem Handy (390 px).
- `14-uebersicht-en.png` (15.11.2026): Übersicht auf Englisch.
- `15-uebersicht-fr.png` (15.11.2026): Übersicht auf Französisch.
- `16-uebersicht-it.png` (15.11.2026): Übersicht auf Italienisch.
