# Lizenzen und mitgelieferter Fremdcode

Das Plugin steht unter **GPL-2.0-or-later** (`LICENSE`, Header `License:`).

## Im Plugin-ZIP enthalten
| Komponente | Pfad | Version | Lizenz |
| --- | --- | --- | --- |
| Plugin Update Checker (Janis Elsts) | `lib/plugin-update-checker/` | 5.7 | MIT, Text in `lib/plugin-update-checker/license.txt` |
| Parsedown (Emanuil Rusev), von Plugin Update Checker für `readme.txt` benutzt | `lib/plugin-update-checker/vendor/ParsedownModern.php` | 1.6.0 | MIT (Hinweis im Dateikopf, eigene Lizenzdatei liegt nicht bei) |
| Readme-Parser (nach Mark Jaquith, angepasst) | `lib/plugin-update-checker/vendor/PucReadmeParser.php` | wie PUC | GPL-kompatibel, Teil von Plugin Update Checker |
| Übersetzungen von Plugin Update Checker | `lib/plugin-update-checker/languages/` | wie PUC | wie PUC |

MIT ist GPL-2.0-or-later-kompatibel. Der Lizenztext und der Copyright-Hinweis von Plugin Update Checker bleiben im ZIP. Der Update-Checker wird nur im GitHub-Kanal gebraucht und entfiele bei einer Verzeichnis-Einreichung (`docs/directory-submission.md`).

## Nicht mitgeliefert
- **PHPMailer** und **sodium_compat** kommen aus dem WordPress-Core (`wp-includes/PHPMailer`, `wp-includes/sodium_compat`) und sind nicht im ZIP. Für die Verschlüsselung wird die PHP-Erweiterung libsodium oder `sodium_compat` aus dem Core benutzt.
- **Keine Composer-Laufzeitabhängigkeiten.** `composer.json` enthält nur Entwicklungswerkzeuge (PHPCS, WPCS, PHPMailer für Tests). Sie sind nicht im ZIP (`vendor/`, `composer.*` stehen in `.distignore`, `bin/verify-zip.sh` prüft das).
- Keine eingebetteten Schriften, Bilder oder JavaScript-Bibliotheken Dritter. `assets/` enthält nur eigenen Code. Die Oberfläche nutzt WordPress-Dashicons und -Styles.

## Pflege
Beim Aktualisieren von `lib/plugin-update-checker` Version und Lizenzdatei prüfen, diese Tabelle anpassen und `bash bin/check.sh` ausführen.

## Public Suffix List

`assets/public-suffix-list.dat` is the Public Suffix List from
https://publicsuffix.org/list/public_suffix_list.dat (retrieved 2026-11-15),
licensed under Mozilla Public License 2.0 as stated in its header. Its source
and copyright notices are included verbatim. Used for DMARC organizational-domain
inheritance, including multi-label suffixes, wildcards and exceptions.
