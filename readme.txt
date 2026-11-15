=== MomentumLab SMTP ===
Contributors: momentumlab
Tags: smtp, email, microsoft 365, gmail, resend
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress emails through SMTP, Microsoft 365, Gmail or Resend, with a delivery log, backup route and optional queue.

== Description ==

**Know whether your website is sending email — and what needs your attention.**

MomentumLab SMTP connects WordPress to your own mail server or a supported email API. It handles emails sent through WordPress, including password resets, contact-form notifications and order confirmations from plugins that use `wp_mail()`.

Choose a provider, check the connection, set the sender and send a test email. The dashboard then shows your main route, backup readiness, waiting emails and any sending problems in one place.

= Four ways to send =

* **SMTP:** Connect your own mail server using its host, port, encryption and credentials.
* **Microsoft 365:** Send through Microsoft Graph using an app registration and an authorised sender mailbox.
* **Gmail:** Connect a Google account using OAuth and send through the Gmail API.
* **Resend:** Connect an API key, check your sending domain and receive signed delivery webhooks.

You need a suitable account or mail server. Provider charges, permissions and sending limits may apply. The plugin does not supply a mailbox or an email-delivery service.

= See what happened to each email =

* Search and filter the delivery log by status, recipient or subject.
* Inspect sending attempts, the route used, provider responses and available delivery events.
* Distinguish **accepted by the provider**, **delivery confirmed**, **failed** and **unclear** outcomes. Acceptance does not prove delivery to the recipient's inbox.
* Find the next action when a connection fails, a backup route needs testing or a message needs a manual decision.

Resend delivery confirmation requires a configured webhook. No provider can guarantee inbox placement, and the plugin does not claim to bypass spam filters.

= Keep sending under control =

* **Guided backup route:** Configure a second SMTP server or Resend account and test it independently. Uncertain sending outcomes are treated conservatively to reduce duplicate-email risk.
* **Optional background queue:** Store pending messages securely and retry eligible temporary failures. Synchronous sending remains the default.
* **Staging protection:** Block or redirect email on a staging copy before it reaches real recipients.
* **Monitoring:** Check connection health, send a test email, configure failure notifications or an optional alert webhook, and export a masked support report.

= Privacy and credentials =

Passwords, API keys and tokens are encrypted at rest. Pending queue payloads are encrypted and cleared after provider acceptance or expiry. An optional `MLAB_SMTP_KEY` separates encryption from the WordPress salts; keep this key backed up.

The delivery log contains recipient information, subject, status, timestamps and diagnostic details. It does not normally store message bodies or attachments. Choose a retention period of 7, 30 or 90 days. WordPress personal-data export and erasure tools are supported.

There is no plugin telemetry, opening tracking or click tracking. Configured providers process the emails you send; see **External services** below.

= Available languages =

The interface is available in English, German, French and Italian. The screenshot shows the real English interface with demonstration data.

= Optional SMTP Pro licensing =

Only after you connect a license in Settings, the plugin sends the license key and
website URL to the HTTPS license server you specify. It uses the existing
MomentumLab catalog API to unlock technical DNS records. The key is encrypted
locally. Sending emails and using the backup route do not depend on licensing.
The operator of your configured license server provides its terms and privacy policy.

== Installation ==

1. Upload `mlab-smtp.zip` using **Plugins > Add New Plugin > Upload Plugin**, then activate it.
2. Open **Email sending** in the WordPress admin menu.
3. Start the setup wizard and choose SMTP, Microsoft 365, Gmail or Resend.
4. Enter the provider credentials, check the connection and choose your sender address.
5. Send a test email and check the result before relying on the connection.
6. Optionally configure and test a backup route. Enable background sending only if you need it.

The current distribution receives updates from GitHub Releases. A WordPress.org distribution must use WordPress.org updates instead.

= Provider requirements =

* **SMTP:** Your mail server's connection details and an authorised sender address.
* **Microsoft 365:** An Entra app registration, tenant ID, client ID and secret, Microsoft Graph application permission `Mail.Send`, and administrator consent. Restrict the application's mailbox access according to your organisation's policies.
* **Gmail:** A Google Cloud project with Gmail API enabled, a web OAuth client and the redirect URI shown in the plugin. Review Google's consent-screen and production requirements for your deployment.
* **Resend:** An API key authorised to send from your domain and the DNS records required by Resend. Configure the signed webhook separately if you want delivery events.

== Frequently Asked Questions ==

= Does this work with contact forms and WooCommerce? =

It handles messages sent through WordPress `wp_mail()`, including emails from plugins that use that function. Plugins that send through a separate service or their own mail client may bypass it.

= Does an accepted email mean it reached the inbox? =

No. Accepted means the configured provider accepted the message for processing. Delivery confirmation is shown only when supported provider evidence is available. Spam filtering and inbox placement remain outside the plugin's control.

= Are emails queued automatically? =

No. WordPress email is sent synchronously by default. Background queueing and background retries are explicitly enabled options. In queue mode, a successful `wp_mail()` result means the message was queued, not delivered.

= Can I disable or remove the backup route? =

Yes. Open Settings > Backup route. Disabling switches the backup off while retaining its credentials and test status. Removing it requires the explicit confirmation button and clears its stored server, credentials and readiness. The primary route and delivery history are retained. Settings defined in `wp-config.php` must be changed there.

= Is my sending subdomain also my SMTP server? =

Not automatically. Enter the SMTP hostname supplied by your mail provider. A custom hostname such as `send.example.com` works only if it resolves to the actual SMTP server and the server's TLS certificate covers that hostname. This field does not change your From address. Sender-domain authentication (SPF, DKIM and DMARC) is configured separately with your provider.

= What happens when the queue is not running? =

Background processing uses WP-Cron. Low traffic, blocked loopback requests or `DISABLE_WP_CRON` can delay it. Configure a real server cron job when necessary, for example once per minute:

`* * * * * cd /path/to/wordpress && php wp-cron.php`

Check your hosting provider's instructions and avoid duplicate cron schedules.

= Can I use the plugin on a staging website? =

Yes. Staging protection can block or redirect outgoing email. Review this setting after cloning or moving a website and explicitly confirm production sending when appropriate.

= What happens if I change the WordPress salts or lose my encryption key? =

Stored credentials may become unreadable. Restore the original key or re-enter the credentials. The plugin does not silently generate a replacement key. Back up `MLAB_SMTP_KEY` separately if you use it.

= Can I use more than one SMTP plugin? =

Use one plugin to control WordPress email sending. Multiple plugins that replace the mailer can conflict. Check the active configuration before sending a test email.

= What happens when I upgrade from 1.4.0? =

The old log is migrated to dedicated database tables in resumable batches. Settings and credentials remain in place. Back up the database before updating and check the migration status afterward. See the upgrade and recovery guide in the source repository.

== Screenshots ==

1. English dashboard with demonstration data: main and backup routes, connection checks, queue status and clear actions for failed or uncertain emails.

== External services ==

The selected email provider receives the sender, recipients, message content and attachments when you send an email. Connection checks and authentication also contact the configured provider. The plugin does not send mail to every provider; only the route you configure is used, with an optional configured backup route.

= Your SMTP server =

The plugin connects to the server you enter during setup for connection checks and email delivery. Its operator's terms and privacy policy apply. No default third-party SMTP server is supplied.

= Microsoft 365 / Microsoft Graph =

When selected, authentication requests go to `login.microsoftonline.com` and email delivery requests go to `graph.microsoft.com`. Microsoft processes credentials or access tokens and message data needed for those operations.

[Microsoft product terms](https://www.microsoft.com/licensing/terms/) | [Microsoft privacy](https://privacy.microsoft.com/privacystatement)

= Google / Gmail =

When you connect a Google account, authorisation uses `accounts.google.com` and token requests use `oauth2.googleapis.com`. Sending uses `gmail.googleapis.com`. Google processes authorisation information and message data needed to send email.

[Google terms](https://policies.google.com/terms) | [Google privacy](https://policies.google.com/privacy)

= Resend =

When selected, the plugin contacts `api.resend.com` for sending and configured domain or connection checks. Resend processes the message data and API credentials needed for those operations. If enabled, Resend sends signed delivery events to your website's webhook endpoint.

[Resend terms](https://resend.com/legal/terms-of-service) | [Resend privacy](https://resend.com/legal/privacy-policy)

= Optional alert webhook =

If you configure an alert URL, the plugin sends masked operational alerts to that endpoint. Email bodies and attachments are not included. The endpoint is chosen by the site administrator; its operator's terms and privacy policy apply.

= GitHub update service =

In the current GitHub distribution, WordPress contacts GitHub to check the plugin's releases and download updates. Those requests include standard connection information such as your server's IP address. Mail content and provider credentials are not sent to GitHub. The plugin details screenshot is bundled locally and does not require an external image request.

[GitHub terms](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service) | [GitHub privacy](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement)

== Changelog ==

= 1.5.3 =
- Added: Separate backup sender address, name and reply address for SMTP and Resend, including a route-specific envelope sender.
- Added: Independent primary and backup SPF, DKIM and DMARC warnings, help and recheck controls. DNS warnings never block setup completion.
- Added: DMARC organizational-domain inheritance using the Public Suffix List; DKIM selector and bounded CNAME checks.
- Added: Opt-in connection to the existing MomentumLab license server using the mlab-smtp-pro product. Pro unlocks technical DNS records; delivery stays independent of licensing.
- Improved: Resend verification status uses the key and sender domain belonging to each route; restricted keys show unknown status.


= 1.5.2 =
* Improved: Setup errors stay inside the current card and receive focus after a redirect.
* Improved: Encryption changes adjust standard SMTP ports for both routes while preserving custom and locked ports.
* Added: Explicit disable and confirmed removal actions for the backup route.
* Added: Corporate mail logo with Montserrat wordmark, plugin icons and detail banners.
* Fixed: Duplicate manual update links in the plugin list.
* Improved: Resend console links now appear below their matching setup instruction.
* Fixed: Untested backup routes can no longer skip the test step; the summary links directly to the required test and back to settings.

= 1.5.1 =
* New: Clear English plugin description, installation steps, FAQ and external-service disclosures.
* New: A real English dashboard screenshot in the plugin details window and a numbered screenshot caption for the directory listing.
* Improved: English plugin summary and an updated WordPress.org submission checklist.

= 1.5.0 =
* Added a persistent optional queue, controlled retries and clear accepted, confirmed-delivery and uncertain statuses.
* Added database-backed delivery logs, privacy export and erasure, retention and secret masking.
* Added guided backup setup, signed Resend webhooks, staging protection, alert webhooks and support reports.
* Added English, German, French and Italian interfaces.
* Hardened encryption-key handling, SSRF protection and message-specific action nonces.
* See CHANGELOG.md and the upgrade and recovery guide in the repository for full details and older changes.

== Upgrade Notice ==

= 1.5.1 =
English plugin details and a dashboard screenshot. Existing mail settings and delivery behaviour remain unchanged.

= 1.5.0 =
Back up your database before updating from 1.4.0. Check the resumable log migration and send a test email after the update.
