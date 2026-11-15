# SMTP Pro and sender domains

Release 1.5.3 keeps delivery independent of licensing. Main and backup routes each
have their own sender identity, DNS report, selector and recheck control. Empty
backup identity fields inherit primary settings for existing installations.
An explicit backup Reply-To overrides contact-form replies; leaving it empty
preserves message replies. The backup envelope sender uses its effective From.

DNS reports never alter setup completion, test success, or fallback activation.
A current successful test remains required to activate a backup. DNS cache
rechecks cannot bypass upstream resolver/authoritative propagation delays.
SPF presence is not a full SPF evaluation. DKIM requires a selector and checks
key presence only, not actual message signing. DMARC organizational-domain
fallback uses the bundled Public Suffix List. Resend SPF/DKIM status is obtained
with the key belonging to the route; sending-only keys produce unknown status.

## Existing license server

In the license server backend create an active product with slug `mlab-smtp-pro`.
Use the existing unlock/product mechanism and grant it to a license, directly or
through a package. No ZIP is needed for an unlock-only product. The SMTP plugin
reads `products[].slug` and strict boolean `unlocked` from:

`GET <configured WordPress base URL>/wp-json/mlab-ls/v1/catalog`

Headers: `X-MLAB-License`, `X-MLAB-Site`. The key is encrypted locally with the
existing plugin secret mechanism. No license-server signing secret belongs in
the client: HMAC download tokens are a different server-side concern.
The settings form requires an explicit server URL and key; no production endpoint
is assumed. Keys are never in request URLs and redirects are disabled.

The initial Pro feature is an expandable technical DNS record view. Basic DNS
warnings, help, rechecks and independent backup senders remain available without
Pro. Periodic domain monitoring and email alerts are not included in this release.

The client refreshes on the settings page at most daily (hourly retry backoff).
Transport failures retain a previous grant for at most seven days after its last
successful check. A 401/403 or an authoritative catalog without the product
revokes the grant. Cache binding includes the exact key, server and website.
No license calls occur during email delivery. Disconnect removes the local
connection; site activation management stays in the existing server backend.
