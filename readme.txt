=== MomentumLab SMTP ===
Contributors: momentumlab
Tags: smtp, email, microsoft 365, gmail, mail
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Versendet WordPress-E-Mails über einen eigenen Mailserver (SMTP), Microsoft 365 oder Google.

== Description ==

MomentumLab SMTP ersetzt den unzuverlässigen Standardversand von WordPress. Du wählst eine von drei Versandarten:

* **Eigener Mailserver (SMTP):** Server, Port, Verschlüsselung, Benutzername und Passwort.
* **Microsoft 365:** Versand über die Graph-API mit einer App-Registrierung, ohne Benutzer-Login und ohne SMTP-Passwort.
* **Google:** Versand über die Gmail-API. Das Konto wird einmalig im Backend verbunden.

Dazu kommen Testmail, Protokoll der letzten 50 Nachrichten (ohne Inhalt) und ein erzwungener Absender.

Passwörter, Secrets und Tokens werden verschlüsselt gespeichert. Alternativ lassen sich alle Werte mit Konstanten in der `wp-config.php` setzen, z. B. `MLAB_SMTP_SMTP_PASS` oder `MLAB_SMTP_M365_CLIENT_SECRET`.

== Installation ==

1. Plugins > Installieren > Plugin hochladen: `mlab-smtp.zip` aus dem neuesten Release wählen.
2. Plugin aktivieren.
3. Einstellungen > MomentumLab SMTP öffnen, Versandart wählen und speichern.
4. Testmail senden.

= Microsoft 365 einrichten =

1. In Microsoft Entra eine App registrieren und ein Client-Secret anlegen.
2. Unter API-Berechtigungen die Anwendungsberechtigung `Mail.Send` (Microsoft Graph) hinzufügen und den Administratorzugriff erteilen.
3. Verzeichnis-ID, Anwendungs-ID und Secret im Plugin eintragen. Als Absender ein Postfach des Tenants angeben.
4. Empfohlen: Die App mit einer Application Access Policy auf das Absender-Postfach beschränken.

= Google einrichten =

1. In der Google Cloud Console die Gmail API aktivieren und einen OAuth-Client vom Typ „Webanwendung“ anlegen.
2. Die im Plugin angezeigte Weiterleitungs-URI als autorisierte Weiterleitungs-URI eintragen.
3. Client-ID und Secret im Plugin speichern und auf „Mit Google verbinden“ klicken.

== Changelog ==

= 1.0.1 =
* Behoben: Beim ersten Speichern wurden Passwörter und Secrets doppelt verschlüsselt, dadurch schlug der Versand (auch die Testmail) fehl.
* Der Plugin-Name lautet jetzt „MomentumLab SMTP“.

= 1.0.0 =
* Erste Version: Versand über SMTP, Microsoft 365 und Google, Testmail, Protokoll.
