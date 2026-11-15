# Veröffentlichung im WordPress.org-Plugin-Verzeichnis

Stand 1.5.0: **Es wird nichts davon umgesetzt.** Das Plugin wird über GitHub-Releases ausgeliefert. Diese Liste hält fest, was für eine Einreichung zu ändern wäre. `bin/plugin-check.sh` meldet die verzeichnisrelevanten Funde unter `MLAB_PLUGIN_CHECK_DIRECTORY=1` mit.

## Muss geändert werden
| Thema | Heute | Für das Verzeichnis |
| --- | --- | --- |
| Update-Checker | `lib/plugin-update-checker`, Header `Update URI`, `inc/updater.php` | Entfernen, auch den Header `Update URI`. Das Verzeichnis liefert Updates aus (Plugin Check: `plugin_updater_detected`). Die GitHub-Variante bräuchte einen eigenen Build ohne Updater. |
| Sprache von `readme.txt` | Deutsch | Englisch, Standardsprache des Verzeichnisses (Plugin Check: `readme_*_non_official_language`). Deutsch, Französisch, Italienisch kommen über translate.wordpress.org. |
| Quelltext-Sprache | Deutsche Quelltexte, Übersetzungen en_US/fr_FR/it_IT als Dateien | Englische Quelltexte (`__( 'English', 'mlab-smtp' )`), Übersetzungen über translate.wordpress.org. Eigene `.po/.mo` sind dann nur noch Ersatz. |
| i18n-Laden | `load_plugin_textdomain()` auf `init` | Ab WordPress 4.6 lädt das Verzeichnis Übersetzungen selbst. Der Aufruf kann entfallen (Warnung `load_plugin_textdomainFound`), wenn `Requires at least` entsprechend steht. |
| Datenbankabfragen | `$wpdb` mit Tabellennamen aus `mlab_smtp_table()` | Plugin Check meldet `UnescapedDBParameter` (Warnungen, Tabellennamen sind konstant). Mit `$wpdb->prepare()` und `%i` (WP 6.2+) oder `esc_sql` und begründeten `phpcs:ignore` ausräumen. |
| Hooknamen | `wp_mail_failed`, `phpmailer_init`, `wp_mail_succeeded` werden ausgelöst | Warnung `NonPrefixedHooknameFound`. Bewusst, weil WordPress-Core-Hooks. Als `phpcs:ignore` mit Begründung festhalten. |
| Tags | höchstens 5 | erledigt |
| Marke | «MomentumLab SMTP», Slug `mlab-smtp` | Der Name enthält keine fremde Marke im Anfang (Microsoft, Google, Resend kommen nur als Beschreibung vor: «Versand über …»). Logos, Namen und Farben dieser Firmen im Plugin nicht verwenden. Slug und Name sind frei, sofern kein Dritter sie hält. |
| Lizenz | GPL-2.0-or-later, MIT-Code in `lib/` | GPL-kompatibel, Details in `docs/licenses.md`. Mit dem Updater entfällt das meiste. |
| Assets | keine Verzeichnisbilder | Icon (128/256), Banner (772x250, 1544x500) und Screenshots in `/assets` des SVN-Repositorys, nicht im Plugin-ZIP. Screenshots in `readme.txt` beschreiben. |
| Sicherheit | Capability- und Nonce-Prüfung, Escaping | Plugin Check mit allen Kategorien (`security`) sauber halten. |

## Offenlegung externer Dienste (readme.txt, Abschnitt «External services»)
Das Verzeichnis verlangt für jeden Dienst: was gesendet wird, wann, und Links zu Nutzungsbedingungen und Datenschutz. Zu nennen:
- **Resend** (`api.resend.com`): Mailinhalt, Absender, Empfänger, Anhänge beim Versand; Domain-Abfragen bei der Prüfung; Zustellereignisse kommen als Webhook zurück. Nur wenn Resend gewählt ist.
- **Microsoft** (`login.microsoftonline.com`, `graph.microsoft.com`): Token-Anfrage und Mailversand über Graph, nur bei Anbieter Microsoft 365.
- **Google** (`accounts.google.com`, `oauth2.googleapis.com`, `gmail.googleapis.com`): OAuth-Anmeldung und Mailversand, nur bei Anbieter Google.
- **Eigener Mailserver (SMTP):** kein Drittdienst des Plugins, die Zieladresse legt der Betreiber fest.
- **Alarm-Webhook:** frei konfigurierte URL des Betreibers, empfängt Statusmeldungen (ohne Nachrichteninhalt).
- **GitHub** (`api.github.com`): heute der Update-Kanal. Entfällt mit dem Updater.
Es gibt weder Telemetrie noch Tracking von Öffnen und Klicks.

## Datenschutz
- Im Protokoll stehen Empfänger, Betreff, Status und Fehler, kein Inhalt. Aufbewahrung 7/30/90 Tage, Bereinigung täglich.
- Die Warteschlange speichert die Nutzlast verschlüsselt und löscht sie nach Annahme.
- WordPress-Datenschutz-Exporter und -Eraser sowie ein Textvorschlag für die Datenschutzerklärung sind eingebunden (`inc/privacy.php`). Der Vorschlag muss die externen Dienste nennen.
- `uninstall.php` entfernt Optionen, Tabellen und Transients.

## Ablauf der Einreichung
1. Verzeichnis-Build ohne Updater und mit englischem readme erzeugen (eigener Zweig oder Build-Schritt).
2. `bash bin/plugin-check.sh` mit `MLAB_PLUGIN_CHECK_DIRECTORY=1` und `MLAB_PLUGIN_CHECK_STRICT=1`, bis keine ERROR-Funde bleiben.
3. ZIP auf https://wordpress.org/plugins/developers/add/ einreichen, Rückfragen des Review-Teams abwarten, danach SVN.
