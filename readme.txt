=== MomentumLab SMTP ===
Contributors: momentumlab
Tags: smtp, email, microsoft 365, gmail, resend
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Versendet WordPress-E-Mails über einen eigenen Mailserver (SMTP), Microsoft 365, Google oder Resend.

== Description ==

MomentumLab SMTP ersetzt den unzuverlässigen Standardversand von WordPress. Du wählst eine von vier Versandarten:

* **Eigener Mailserver (SMTP):** Server, Port, Verschlüsselung, Benutzername und Passwort.
* **Microsoft 365:** Versand über die Graph-API mit einer App-Registrierung, ohne Benutzer-Login und ohne SMTP-Passwort.
* **Google:** Versand über die Gmail-API. Das Konto wird einmalig im Backend verbunden.
* **Resend:** Versand über die Resend-API mit einem API-Key. Der Assistent prüft die Absender-Domain und zeigt fehlende DNS-Einträge.

Ein Assistent führt in 4 Schritten durch die Einrichtung. Dazu kommen Übersicht mit Gesundheitsprüfung, ein Ersatzweg (zweiter Mailserver oder Resend), Benachrichtigung bei Fehlern, Testmail und ein Protokoll ohne Nachrichteninhalt. Das Protokoll liegt in eigenen Datenbanktabellen, Zustellereignisse von Resend kommen über einen signierten Webhook dazu. Optional werden Mails in einer Warteschlange im Hintergrund versendet und bei vorübergehenden Fehlern wiederholt. Der Staging-Schutz verhindert, dass eine kopierte Website echte Empfänger anschreibt.

Passwörter, Secrets und Tokens werden verschlüsselt gespeichert. Alternativ lassen sich alle Werte mit Konstanten in der `wp-config.php` setzen, z. B. `MLAB_SMTP_SMTP_PASS` oder `MLAB_SMTP_M365_CLIENT_SECRET`. Mit der optionalen Konstante `MLAB_SMTP_KEY` ist die Verschlüsselung von den WordPress-Salts entkoppelt.

**Externe Dienste:** Das Plugin sendet Mails und Prüfanfragen nur an den Dienst, den du einrichtest (dein Mailserver, Microsoft Graph und login.microsoftonline.com, Google/Gmail-API, Resend) und, wenn eingerichtet, an deine Alarm-Webhook-URL. Für Updates fragt es die GitHub-Releases des Plugins ab. Es gibt kein Tracking und keine Telemetrie.

== Installation ==

1. Plugins > Installieren > Plugin hochladen: `mlab-smtp.zip` aus dem neuesten Release wählen.
2. Plugin aktivieren.
3. Einstellungen > E-Mail-Versand öffnen und «Einrichtung starten» wählen.
4. Der Assistent führt durch Anbieter, Verbindung, Absender und Testmail.
5. Auf einem Server mit `DISABLE_WP_CRON` die Warteschlange per System-Cron anstossen (siehe FAQ).

= Microsoft 365 einrichten =

1. In Microsoft Entra eine App registrieren und ein Client-Secret anlegen.
2. Unter API-Berechtigungen die Anwendungsberechtigung `Mail.Send` (Microsoft Graph) hinzufügen und den Administratorzugriff erteilen.
3. Verzeichnis-ID, Anwendungs-ID und Secret im Plugin eintragen. Als Absender ein Postfach des Tenants angeben.
4. Empfohlen: Die App mit einer Application Access Policy auf das Absender-Postfach beschränken.

= Google einrichten =

1. In der Google Cloud Console die Gmail API aktivieren und einen OAuth-Client vom Typ „Webanwendung“ anlegen.
2. Die im Plugin angezeigte Weiterleitungs-URI als autorisierte Weiterleitungs-URI eintragen.
3. Den OAuth-Zustimmungsbildschirm auf «In Produktion» stellen. Im Testmodus läuft der Zugriff nach 7 Tagen ab.
4. Client-ID und Secret im Assistenten eintragen und «Mit Google anmelden» wählen.

= Resend einrichten =

1. Bei Resend unter «Domains» die Domain hinzufügen (empfohlen: eine Subdomain wie updates.ihre-domain.ch) und die angezeigten DNS-Einträge beim Domain-Anbieter setzen.
2. Unter «API Keys» einen Key mit «Sending access» für diese Domain erstellen und kopieren.
3. Den Key im Assistenten unter «Verbinden» eintragen und die Verbindung prüfen. Der Key wird verschlüsselt gespeichert.
4. Im Schritt «Absender» zeigt das Plugin, ob die Domain verifiziert ist, und nennt fehlende DNS-Einträge. Danach die Testmail senden.

== Frequently Asked Questions ==

= Was passiert beim Update von 1.4.0? =

Das alte Protokoll wird beim ersten Aufruf des Backends in die neuen Tabellen übernommen (in Teilen, bei Abbruch wird es fortgesetzt). Die alte Option bleibt für eine Rückstufung erhalten. Einstellungen und Zugangsdaten ändern sich nicht. Details: `docs/upgrade-and-recovery.md` im Repository.

= Die Zugangsdaten sind nach einem Umzug oder Salt-Wechsel leer. =

Secrets sind mit einem Schlüssel aus den WordPress-Salts oder aus `MLAB_SMTP_KEY` verschlüsselt. Ändern sich die Salts oder die Konstante, sind sie nicht mehr lesbar. Das Plugin erzeugt nie automatisch einen neuen Schlüssel: Secret im Backend neu eintragen. Um das zu vermeiden, `MLAB_SMTP_KEY` einmal setzen und sichern.

= Die Warteschlange läuft nicht. =

Die Warteschlange läuft über WP-Cron. Ist `DISABLE_WP_CRON` gesetzt oder erreicht sich die Website nicht selbst, muss ein System-Cron `wp-cron.php` aufrufen, z. B. jede Minute: `* * * * * cd /pfad/zur/website && php wp-cron.php`. Die Übersicht zeigt, wenn fällige Mails zu lange warten.

= Auf einer Staging-Kopie werden keine Mails gesendet. =

Das ist gewollt (Staging-Schutz). Im Backend lässt sich der Modus ändern oder der Betrieb ausdrücklich bestätigen.

== Changelog ==

= 1.5.0 =
* Neu: Dauerhafte Warteschlange mit Wiederholung, Statusmodell und ehrlicher Anzeige (angenommen, Zustellung bestätigt, unklar).
* Neu: Protokoll in eigenen Datenbanktabellen, Datenschutz-Exporter und -Eraser, Aufbewahrung, Maskierung von Secrets.
* Neu: Geführter Ersatzweg mit Bereitschaftszuständen, Resend-Webhooks, Staging-Schutz, Alarm-Webhook, Supportbericht.
* Neu: Oberfläche auf Deutsch, Englisch, Französisch und Italienisch.
* Sicherheit: Schlüsselverwaltung mit Kennung (`MLAB_SMTP_KEY`), Schutz vor SSRF, Nonces je Aktion und Nachricht.
* Details und Update-Anleitung: CHANGELOG.md und docs/upgrade-and-recovery.md.

= 1.4.0 =
* Neu: Der Ersatzweg kann auch Resend sein, nicht nur ein Mailserver.
* Neu: Eigener Menüpunkt «E-Mail-Versand» mit Symbol in der Hauptnavigation.
* Verbessert: Klarere Texte und Schritte im Assistenten.

= 1.3.0 =
* Neu: Resend als Versandweg, mit geführter Einrichtung, Prüfung der Absender-Domain und Fehlermeldungen in Klartext (siehe CHANGELOG.md).

= 1.2.0 =
* Neue Oberfläche mit Einrichtungs-Assistent, Übersicht, Protokoll und Einstellungen.
* Neu: Ersatzweg, Benachrichtigung bei Fehlern, Gesundheitsprüfung (SPF, DMARC, Schlüssel-Ablauf), Verbindung prüfen, Antwortadresse.
* Behoben: Google-Verbindung wurde nie gespeichert, stiller Versand ohne eingerichteten Server, Token-Cache nach Änderungen und weitere Fehler (siehe CHANGELOG.md).

= 1.0.2 =
* Behoben: Antwortete der Mailserver nicht (falscher Port, falsche Verschlüsselung, falsches Passwort), hing die Testmail bis zum Fehler 504. Jeder Schritt bricht jetzt nach 10 Sekunden ab.
* Die Testmail zeigt bei einem Fehler den Grund, einen Hinweis zur Behebung und den Verlauf der Verbindung (ohne Passwort).
* Der Knopf „Testmail senden“ zeigt an, dass gesendet wird, und lässt sich nicht doppelt klicken.

= 1.0.1 =
* Behoben: Beim ersten Speichern wurden Passwörter und Secrets doppelt verschlüsselt, dadurch schlug der Versand (auch die Testmail) fehl.
* Der Plugin-Name lautet jetzt „MomentumLab SMTP“.

= 1.0.0 =
* Erste Version: Versand über SMTP, Microsoft 365 und Google, Testmail, Protokoll.
