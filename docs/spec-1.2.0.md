# mlab-smtp 1.2.0 – Architektur und Schnittstellen

Verbindlich für alle Beteiligten. Befund: `/mnt/project-files/mlab-smtp/befund-v1.0.2.md`. Mock: `docs/mock-1.2.0.html`.

## Grundsätze

- PHP 8.0+, WP 6.4+, WordPress Coding Standards (`phpcs.xml.dist`), Präfix `mlab_smtp_`, Textdomain `mlab-smtp`.
- UI-Texte Deutsch (Schweiz, «ss», «Sie»-Form wie im Mock). Code/Kommentare Englisch.
- Funktionen max. ~30 Zeilen. Alle Ausgaben escapen, alle Aktionen `manage_options` + Nonce.
- Keine Mailinhalte speichern (Datenschutz). Kein «Erneut senden» mit Inhalt; der Ersatzweg übernimmt.
- Keine neuen Abhängigkeiten. Kein Build-Schritt für JS/CSS (Vanilla JS, ein CSS).

## Dateien und Zuständigkeit

| Datei | Inhalt | Wer |
|---|---|---|
| `mlab-smtp.php` | Header, Konstanten, Loader | Lead |
| `inc/options.php` | Schlüssel, Defaults, get/update/save_fields, Sperre durch Konstanten | Backend |
| `inc/crypto.php` | unverändert ausser Bedarf | Backend |
| `inc/http.php` | Token, Antworten, Cache-Invalidierung | Backend |
| `inc/m365.php`, `inc/google.php` | Anbieter | Backend |
| `inc/class-mlab-smtp-mailer.php` | PHPMailer-Subklasse für ALLE Wege inkl. Ersatzweg (ersetzt `class-mlab-smtp-api-mailer.php`) | Backend |
| `inc/mailer.php` | Hooks in wp_mail, Absender, Route | Backend |
| `inc/state.php` | Versandzustand (letzter Erfolg/Fehler), Status, Statistik | Backend |
| `inc/notify.php` | Benachrichtigung bei Fehlern, Admin-Hinweis, Tages-Cron | Backend |
| `inc/health.php` | Gesundheitsprüfungen (Secret-Ablauf, SPF, DMARC, Google-Testmodus, Benachrichtigung) | Backend |
| `inc/detect.php` | Anbieter-Erkennung per MX, Hoster-Vorlagen | Backend |
| `inc/check.php` | Verbindung prüfen ohne Versand, Testmail, Fehlererklärung | Backend |
| `inc/log.php` | Protokoll | Backend |
| `inc/admin/*.php` | Seite, Tabs, Assistent, Formular-Handler, REST | UI |
| `assets/css/admin.css`, `assets/js/admin.js` | Oberfläche | UI |
| `uninstall.php` | Aufräumen inkl. Multisite, Cron, Transients | Backend |

`inc/admin.php` (alt) wird entfernt; Loader lädt `inc/admin/page.php` (lädt die übrigen Admin-Dateien selbst).

## Einstellungen (`mlab_smtp_options`, autoload=no)

```
provider            smtp|m365|google          default '' (leer = nicht eingerichtet)
setup_done          0|1                        default 0 (wird erst nach erfolgreicher Testmail im Assistenten 1)
from_email          string                     default ''
from_name           string                     default '' (UI zeigt Seitenname als Vorschlag/Fallback)
reply_to            string                     default ''
force_from          0|1                        default 1
smtp_host/port/secure/auth/user/pass            wie bisher (port 587, secure tls, auth 1)
m365_tenant, m365_client_id, m365_client_secret wie bisher
m365_secret_expires 'YYYY-MM-DD'|''            default ''
google_client_id, google_client_secret, google_refresh_token  wie bisher
google_account      string (E-Mail des verbundenen Kontos) default ''
fallback_enabled    0|1                        default 0
fb_smtp_host/fb_smtp_port/fb_smtp_secure/fb_smtp_auth/fb_smtp_user/fb_smtp_pass   wie smtp_*
notify_enabled      0|1                        default 1
notify_email        string                     default '' (leer = admin_email)
notify_mode         instant|daily              default instant
log_enabled         0|1                        default 1
log_retention       7|30|90                    default 30
```
Secrets (verschlüsselt): `smtp_pass, fb_smtp_pass, m365_client_secret, google_client_secret, google_refresh_token`.

Migration: Bestehende Installationen mit gesetztem `smtp_host` bzw. M365/Google-Daten gelten als eingerichtet (`setup_done=1`, provider bleibt). `mlab_smtp_maybe_migrate()` auf `admin_init`, gesteuert über Option `mlab_smtp_db_version`.

## Backend-API (Signaturen sind Vertrag)

options.php
- `mlab_smtp_defaults(): array`, `mlab_smtp_secret_keys(): array`
- `mlab_smtp_get(string $key)` – Konstante gewinnt, Secrets entschlüsselt.
- `mlab_smtp_get_all(): array` – roh, Secrets verschlüsselt.
- `mlab_smtp_update(string $key, $value): void` – OHNE sanitize-Filter (kein register_setting mehr!).
- `mlab_smtp_save_fields(array $input, array $keys): string[]` – sanitisiert NUR `$keys`; Checkbox-Schlüssel, die fehlen → 0; leere Secrets behalten den alten Wert; Secrets NICHT trimmen; ungültige E-Mail → Wert bleibt alt + Fehlermeldung im Rückgabe-Array (deutsch). Ändern sich Zugangsdaten eines Anbieters, Token-Transient löschen. Gesperrte Schlüssel (Konstante) werden ignoriert.
- `mlab_smtp_is_locked(string $key): bool`
- `mlab_smtp_checkbox_keys(): array`

detect.php
- `mlab_smtp_detect_provider(string $domain = ''): array{provider:string, mx:string}` – leere Domain = Domain von `admin_email`; `outlook.com`/`protection.outlook.com` → m365, `google.com`/`googlemail.com` → google, sonst smtp. Ergebnis 1 Tag als Transient.
- `mlab_smtp_smtp_presets(): array<string, array{label:string, host:string, port:int, secure:string}>` – infomaniak (mail.infomaniak.com), hostpoint (asmtp.mail.hostpoint.ch), cyon (mail.cyon.ch), metanet (smtp.metanet.ch), other.

check.php
- `mlab_smtp_check_connection(string $route = 'primary'): true|WP_Error` – smtp: verbinden + anmelden, schliessen; m365: frisches Token (Cache umgehen); google: frisches Access-Token aus Refresh-Token. `$route` = primary|fallback.
- `mlab_smtp_run_test(string $to): array{sent:bool, error:string, hint:string, debug:string, via:string}` – Fehler, wenn nicht eingerichtet (kein stilles PHP mail()).
- `mlab_smtp_explain_error(string $error): string` – Klartext-Hinweis (leer, wenn unbekannt): authenticate, connect/timeout, invalid_grant, 401/403 M365 (Admin-Zustimmung Mail.Send / Postfach), 404 M365 (Postfach existiert nicht), 413/size (zu gross, Ersatzweg), invalid_client (Secret falsch/abgelaufen), sender/from rejected.

class-mlab-smtp-mailer.php: `Mlab_Smtp_Mailer extends PHPMailer`
- `postSend()`: Hauptweg (SMTP via `parent::postSend()` oder API via `mlab_smtp_dispatch()`); bei Fehler und aktivem Ersatzweg: SMTP mit `fb_*` konfigurieren, `smtpSend()`; Route und Hauptfehler werden in `mlab_smtp_route_result()` vermerkt; Ersatzweg-Fehler → Exception mit beiden Meldungen.
- Exception-Texte NICHT escapen (phpcs:ignore mit Begründung).

mailer.php
- Plugin greift nur ein, wenn `mlab_smtp_is_configured()`. Bypass-Flag `mlab_smtp_bypass(bool|null $set = null): bool` für Benachrichtigungen ohne funktionierenden Weg.
- Absender: bei m365/google Pflicht; fehlt er → Fehler vor dem Versand mit Klartext.
- Reply-To setzen, wenn konfiguriert und der Aufrufer keinen gesetzt hat.
- `mlab_smtp_route_result(?array $set = null): array{via:string, fallback:bool, primary_error:string}` – letzte Route dieser Anfrage.

state.php (Option `mlab_smtp_state`, autoload=no)
- `mlab_smtp_record(bool $ok, string $error, bool $fallback): void` – zählt; speichert last_success, last_failure, failing_since, failures_since (Hauptweg-Fehler auch dann, wenn Ersatzweg zustellte).
- `mlab_smtp_is_configured(): bool` – setup_done=1 und Pflichtfelder des Anbieters vorhanden.
- `mlab_smtp_status(): array{state:'none'|'ok'|'warn'|'bad', title:string, text:string, since:int, failures:int}` – bad = Hauptweg scheitert und kein Ersatzweg stellte zu; warn = Hauptweg gestört, Ersatzweg stellt zu.
- `mlab_smtp_stats(int $days = 14): array{sent:int, failed:int, fallback:int, last:int, daily:int[], daily_failed:int[]}` – aus dem Protokoll (sent/failed/fallback über 7 Tage, daily über $days).

notify.php
- Bei Fehlschlag (`mlab_smtp_record` mit ok=false): wenn notify_enabled & instant → höchstens 1×/Stunde Mail an notify_email; Versand über Ersatzweg wenn aktiv, sonst Bypass (PHP mail()). Daily-Modus: Cron `mlab_smtp_daily` fasst zusammen.
- `mlab_smtp_activate(): void` / `mlab_smtp_deactivate(): void` (in notify.php, im Hauptfile registriert): Cron planen bzw. entfernen.
- Cron `mlab_smtp_daily` (registrieren bei Aktivierung bzw. falls fehlend): Protokoll kürzen (Aufbewahrung), Secret-Ablauf ≤30 Tage → Benachrichtigung (1× pro Schwelle 30/7/1 Tage).
- Admin-Hinweis (`admin_notices`, nur `manage_options`, nicht auf der Plugin-Seite): nicht eingerichtet (nur auf Dashboard und Plugins-Seite, wegklickbar) / Störung (überall).

health.php
- `mlab_smtp_health_checks(): array<int, array{level:'ok'|'warn'|'bad', title:string, text:string, action_label:string, action_url:string}>` – Verbindung (letzter Status), M365-Secret-Ablauf, SPF (TXT der Absender-Domain enthält `include:spf.protection.outlook.com` bzw. `_spf.google.com` bzw. irgendein SPF bei smtp), DMARC (`_dmarc.<domain>` vorhanden), Benachrichtigung aktiv, Ersatzweg. DNS-Ergebnisse 1 Tag gecacht. `dns_get_record` mit `@` und Fehlerbehandlung.

log.php (Option `mlab_smtp_log`, autoload=no, max 500 Einträge + Aufbewahrung)
- Eintrag: `{id:string, time:int, to:string, subject:string, ok:bool, fallback:bool, via:string, error:string}`
- `mlab_smtp_log_get(array $args = []): array` – args: status all|ok|bad (bad = !ok ODER fallback), search (to/subject), limit.
- `mlab_smtp_log_find(string $id): ?array`, `mlab_smtp_log_clear(): void`, `mlab_smtp_log_purge(): void`.

google.php
- Scopes: `gmail.send openid email`; Konto-E-Mail aus `id_token` (Payload base64url, ohne Signaturprüfung, da direkt vom Token-Endpunkt) in `google_account`.
- `state` als Einmal-Token (Transient 10 Min, an User gebunden), nicht wp_create_nonce.
- `mlab_smtp_google_connect_url(): string` (admin-post-URL mit Nonce).
- Nach Callback zurück in den Assistenten: `mlab_smtp_page_url(['view'=>'wizard','step'=>2])`.
- `invalid_grant` beim Token-Refresh → Refresh-Token NICHT löschen, aber Status bad mit Hinweis (über record).

http.php
- Bei 401 von Graph/Gmail: Token-Transient löschen, einmal neu versuchen.
- `mlab_smtp_cached_token(..., bool $fresh = false)`.

## Admin-UI (inc/admin)

URL: `options-general.php?page=mlab-smtp`, Menütitel «E-Mail-Versand». `mlab_smtp_page_url(array $args = []): string` in `inc/admin/page.php`.
Ansichten über `view` = overview (Standard) | log | settings | wizard (`step` 1–4, `provider`).
- Nicht eingerichtet → Übersicht zeigt Einstiegs-Karte (Mock «Nicht eingerichtet»).
- Assistent: Schritt 1 Anbieter (Vorschlag aus detect), 2 Verbinden (Anleitung je Anbieter, «Verbindung prüfen» Pflicht vor Weiter), 3 Absender (vorausgefüllt), 4 Testmail (Abschluss erst nach Erfolg → setup_done=1). Speichern je Schritt per admin-post (`mlab_smtp_wizard`), Prüfen und Testmail per REST (fetch, Fortschrittsanzeige), funktioniert ohne JS über admin-post-Fallback nicht zwingend.
- REST `mlab-smtp/v1`: `POST /check {route}`, `POST /test {to}`; `permission_callback` = `current_user_can('manage_options')`, Nonce `wp_rest`.
- Einstellungen: Abschnitte wie Mock; Speichern per admin-post `mlab_smtp_save_settings` → `mlab_smtp_save_fields`.
- Protokoll: Filter/Suche per GET, Detail per `entry=<id>`, Leeren mit Bestätigung in der Seite (kein confirm()).
- Mock-Copy übernehmen, aber Fakten an die echte Logik anpassen (kein «nachsenden»).
- Gesperrte Felder (Konstante) zeigen Schloss + «Gesetzt in wp-config.php».
- Mobile tauglich (WP-Admin ab 782px Breakpoint).
