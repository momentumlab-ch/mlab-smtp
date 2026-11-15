# Release-Ablauf

Ein Release entsteht aus `main`. Der Workflow `.github/workflows/release.yml` baut und veröffentlicht nur, wenn alle Prüfungen bestehen.

## Lokal vorbereiten
1. Version erhöhen, überall gleich: Plugin-Header `Version:`, Konstante `MLAB_SMTP_VERSION` in `mlab-smtp.php`, `Stable tag` und oberster Changelog-Eintrag in `readme.txt`, oberster Eintrag in `CHANGELOG.md`.
2. Übersetzungen aktualisieren (POT und `.po/.mo` für en_US, fr_FR, it_IT).
3. Alles prüfen:
   ```bash
   composer install && bash tests/env/setup.sh     # einmalig
   MLAB_REQUIRE_LOCALES=1 bash bin/check.sh v1.5.0  # Version als Argument gegen den geplanten Tag prüfen
   ```
   Die Schlusstabelle zeigt je Schritt PASS, FAIL oder SKIPPED. SKIPPED heisst «nicht ausgeführt» und ist kein Bestehen. Vor dem Release darf nichts übersprungen sein (`MLAB_CHECK_STRICT=1` macht das zum Fehler).
4. Das fertige ZIP liegt in `dist/` (`mlab-smtp-<version>.zip` und `mlab-smtp.zip`). Auf einer Testseite hochladen und durchklicken.

## Einzelne Skripte
| Skript | Zweck |
| --- | --- |
| `bin/lint.sh`, `bin/phpcs.sh` | Syntax und WordPress Coding Standards |
| `bin/check-version.sh [version\|tag]` | Version in Header, Konstante, readme (Stable tag, Changelog) und `CHANGELOG.md` identisch, optional gegen Tag |
| `php tests/run.php` | Unit- und Integrationstests (`bin/test.sh`) |
| `bin/i18n-check.sh` | POT und Übersetzungen aktuell (sobald vorhanden) |
| `bin/plugin-check.sh [zip]` | WordPress Plugin Check auf dem ZIP-Inhalt, Exit 3 wenn nicht installierbar |
| `bin/build-zip.sh [ordner]` | reproduzierbares ZIP nach `.distignore` |
| `bin/verify-zip.sh <zip>` | Struktur, verbotene Pfade, `php -l`, Secret-Scan (`bin/secret-scan-allow.txt`), Sprachdateien, Installationstest |
| `bin/check.sh` | alle der Reihe nach, mit Schlusstabelle |

`bin/verify-zip.sh` installiert das ZIP in einer frischen WordPress-Kopie (SQLite), aktiviert es mit WP-CLI, prüft Tabellen und einen `wp_mail()`-Aufruf mit gemocktem HTTP, deaktiviert und deinstalliert es und prüft, dass Tabellen, Optionen und Cron-Ereignis weg sind. Exit 3 bedeutet: Testumgebung fehlt, Installationstest nicht ausgeführt.

## Veröffentlichen
1. Auf `main` pushen (oder Tag `vX.Y.Z` setzen). Der Workflow liest die Version aus dem Header, bricht bei Tag/Version-Abweichung ab und überspringt, wenn das Release das Asset schon hat.
2. Er führt `bin/check.sh` mit `MLAB_CHECK_STRICT=1` und `MLAB_REQUIRE_LOCALES=1` aus. Schlägt etwas fehl oder wird etwas übersprungen, gibt es **kein** Release und kein Asset.
3. Sonst legt er das Release `vX.Y.Z` an (Notizen aus `CHANGELOG.md`) und hängt das geprüfte `dist/mlab-smtp.zip` an. Der Update-Checker der installierten Plugins findet es darüber.
4. Nach dem Release: auf einer Testseite das Update über den normalen Update-Weg einspielen.

## Reproduzierbarkeit
Das ZIP hat sortierte Einträge, feste Dateizeit (Commit-Zeit) und feste Modi (0644). Gleicher Stand ergibt gleiche Prüfsumme (`sha256sum dist/mlab-smtp.zip`).

## Rückruf
Ein fehlerhaftes Release nicht überschreiben: Release als Entwurf zurückziehen, Fix als nächste Patch-Version veröffentlichen (Updater vergleicht Versionen). Hinweise zur Rückstufung bei Kundinnen und Kunden: `docs/upgrade-and-recovery.md`.
