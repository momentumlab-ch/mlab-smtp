=== MomentumLab SMTP ===
Contributors: momentumlab
Tags: smtp, email, microsoft 365, gmail, mail
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Versendet WordPress-E-Mails über einen eigenen Mailserver (SMTP), Microsoft 365 oder Google.

== Description ==

MomentumLab SMTP ersetzt den unzuverlässigen Standardversand von WordPress. Du wählst eine von drei Versandarten:

* **Eigener Mailserver (SMTP):** Server, Port, Verschlüsselung, Benutzername und Passwort.
* **Microsoft 365:** Versand über die Graph-API mit einer App-Registrierung, ohne Benutzer-Login und ohne SMTP-Passwort.
* **Google:** Versand über die Gmail-API. Das Konto wird einmalig im Backend verbunden.

Ein Assistent führt in 4 Schritten durch die Einrichtung. Dazu kommen Übersicht mit Gesundheitsprüfung, Ersatzweg über einen zweiten Mailserver, Benachrichtigung bei Fehlern, Testmail und Protokoll (ohne Inhalt).

Passwörter, Secrets und Tokens werden verschlüsselt gespeichert. Alternativ lassen sich alle Werte mit Konstanten in der `wp-config.php` setzen, z. B. `MLAB_SMTP_SMTP_PASS` oder `MLAB_SMTP_M365_CLIENT_SECRET`.

== Installation ==

1. Plugins > Installieren > Plugin hochladen: `mlab-smtp.zip` aus dem neuesten Release wählen.
2. Plugin aktivieren.
3. Einstellungen > E-Mail-Versand öffnen und «Einrichtung starten» wählen.
4. Der Assistent führt durch Anbieter, Verbindung, Absender und Testmail.

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

== Changelog ==

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
