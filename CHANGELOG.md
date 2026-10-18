# Changelog

## 1.0.1

- Behoben: Beim ersten Speichern wurden Passwörter und Secrets doppelt verschlüsselt, dadurch schlug der Versand (auch die Testmail) fehl.
- Der Plugin-Name lautet jetzt „MomentumLab SMTP“.

## 1.0.0

- Erste Version.
- Versand über einen eigenen Mailserver (SMTP), Microsoft 365 (Graph-API) oder Google (Gmail-API).
- Testmail und Protokoll der letzten 50 Nachrichten, ohne Inhalt.
- Passwörter, Secrets und Tokens werden verschlüsselt gespeichert oder per Konstante in der wp-config.php gesetzt.
- Updates über GitHub Releases.
