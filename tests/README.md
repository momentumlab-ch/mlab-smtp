# Tests und Testumgebung

## Schnellstart

```bash
composer install                 # Dev-Abhängigkeiten (phpcs, WPCS, PHPMailer), vendor/ wird nicht committet
bash tests/env/setup.sh          # lokales WordPress mit SQLite, Plugin verlinkt und aktiviert
bash bin/test.sh                 # Unit- und Integrationstests
bash bin/lint.sh                 # php -l über alle Plugin-Dateien
bash bin/phpcs.sh                # WordPress Coding Standards, ohne Ausgabe wenn sauber
```

## Aufbau

| Pfad | Zweck |
| --- | --- |
| `tests/unit/*.php` | Standalone-Tests mit eigenen WordPress-Stubs (kein WordPress nötig, nur `vendor/autoload.php`). `notices.php` nutzt Dateien aus dem Core in `tests/env/wordpress` und wird ohne Umgebung übersprungen. |
| `tests/integration/*.php` | Tests gegen echtes WordPress (`$wpdb`, Optionen, REST, Nonces, `dbDelta`). |
| `tests/bootstrap-wp.php` | Lädt `wp-load.php` im CLI, das Plugin ist aktiv. Bricht mit Exit-Code 2 ab, wenn die Umgebung fehlt. |
| `tests/lib/TestCase.php` | `assert_true`, `assert_same`, `assert_contains`, `assert_throws`, `t_finish()`. |
| `tests/lib/http-mock.php` | HTTP-Mock über den Filter `pre_http_request`: `mlab_http_mock( $regex, $code, $body )`, `mlab_http_requests()`, `mlab_http_reset()`. |
| `tests/run.php` | Runner: `php tests/run.php [unit\|integration\|datei]`. Jede Datei läuft in einem eigenen Prozess, PASS/FAIL werden gezählt, Exit-Code 1 bei Fehlern. |

Eine neue Testdatei in `tests/unit/` oder `tests/integration/` wird automatisch gefunden. Integrationstests beginnen mit
`require dirname( __DIR__ ) . '/bootstrap-wp.php';` und enden mit `t_finish();`.

## Testumgebung (`tests/env/`)

`setup.sh` ist idempotent und erledigt:

1. WordPress-Core und WP-CLI per Composer (`tests/env/composer.json`, Core in `tests/env/wordpress`).
2. SQLite statt MySQL: Plugin `sqlite-database-integration` (Klon in `tests/env/.cache`) und das Drop-in `wp-content/db.php`.
3. Generiert `wp-config.php` (Host und URL folgen dem Request, daher läuft jeder Port).
4. Installiert WordPress still: Benutzer `admin`, Passwort aus `MLAB_ADMIN_PASSWORD`, sonst zufällig (wird ausgegeben und in `tests/env/admin-password.txt` abgelegt).
5. Verlinkt das Repo als `wp-content/plugins/mlab-smtp` und aktiviert es.

Weitere Variablen: `MLAB_WP_URL` (Standard `http://localhost:8888`), `MLAB_SITE_TITLE`, `MLAB_SQLITE_REF`.

```bash
bash tests/env/serve.sh          # startet den PHP-Server auf einem freien Port (PORT=8080 erzwingt einen Port), URL in tests/env/.run/url
bash tests/env/stop.sh           # stoppt ihn wieder
php -d memory_limit=512M tests/env/vendor/wp-cli/wp-cli/php/boot-fs.php --path=tests/env/wordpress --allow-root option get siteurl   # WP-CLI
```

Zurücksetzen: `rm -rf tests/env/wordpress tests/env/vendor tests/env/.cache tests/env/.run tests/env/admin-password.txt` und `setup.sh` neu ausführen.

## MySQL/MariaDB statt SQLite

Standard ist SQLite. Mit `MLAB_TEST_DB=mysql` läuft dieselbe WordPress-Installation gegen eine echte MySQL-kompatible Datenbank, damit
`dbDelta`, Indizes, `affected_rows` und Nebenläufigkeit realistisch geprüft werden:

```bash
apt-get install -y mariadb-server          # nur das Paket, der Systemdienst wird nicht benutzt
bash tests/env/mysql-start.sh              # eigene Instanz in tests/env/.run/mysql (Unix-Socket, kein TCP), Datenbank mlab_test, Zufallspasswort in tests/env/.run/mysql.php
MLAB_TEST_DB=mysql bash tests/env/setup.sh # installiert WordPress und aktiviert das Plugin in der MySQL-Datenbank
MLAB_TEST_DB=mysql php tests/run.php       # ganze Suite auf MySQL (ohne Variable: SQLite)
MLAB_TEST_DB=mysql bash tests/env/serve.sh # Webserver gegen MySQL
bash tests/env/mysql-stop.sh [--purge]     # stoppt die Instanz, --purge löscht Datadir und Zugangsdaten
```

- Beide Modi teilen sich `tests/env/wordpress`. `wp-config.php` wählt die Datenbank zur Laufzeit anhand von `MLAB_TEST_DB`; `wp-content/db.php`
  ist ein Schalter, der das SQLite-Drop-in (`db.php.sqlite`) im MySQL-Modus nie lädt. `$wpdb` ist dort die normale `wpdb`-Klasse.
- Eine fremde Datenbank (z. B. CI-Dienst) geben `MLAB_MYSQL_HOST`, `MLAB_MYSQL_NAME`, `MLAB_MYSQL_USER`, `MLAB_MYSQL_PASS` an; `mysql-start.sh` ist dann unnötig.
- `tests/integration/mysql-claim.php` läuft nur auf MySQL (sonst Hinweis „SKIP“): mehrere PHP-Prozesse (`proc_open`, Datei-Schranke) beanspruchen
  gleichzeitig dieselben Zeilen (genau ein Gewinner) und fügen gleichzeitig Nachrichten ein (keine Zeile geht verloren).
- Unterschied, den MySQL sichtbar macht: `UPDATE` meldet geänderte statt gefundene Zeilen. Ein Compare-and-Set, das nichts ändert, liefert 0.
- Die Zugangsdaten und das Datadir liegen unter `tests/env/.run/` und sind von Git ausgeschlossen.

## Hinweise

- Als root deaktiviert Composer Plugins. `bin/phpcs.sh` setzt `installed_paths` deshalb selbst, `setup.sh` setzt `COMPOSER_ALLOW_SUPERUSER=1`.
- `tests/` ist von PHPCS ausgenommen (`phpcs.xml.dist`) und steht in `.distignore`, ist also nicht im Plugin-ZIP.

## Screenshots

`bin/seed-demo.php [normal|stoerung|staging|fb-…]` setzt Demo-Daten in der Testumgebung (Beispieladressen, kein Netzwerk). `node bin/screenshots.js` meldet sich im laufenden `tests/env`-Server an und schreibt die Bilder nach `docs/screenshots/` (Übersicht siehe dort). Der Aufruf überschreibt die Daten der Testdatenbank; Sprachwechsel läuft über ein temporäres mu-plugin, das das Skript selbst anlegt und löscht.
