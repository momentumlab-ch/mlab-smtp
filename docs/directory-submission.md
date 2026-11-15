# WordPress.org submission preparation

Status for 1.5.1: the English listing content is prepared. The plugin is still distributed through GitHub Releases. This change does not submit, publish or approve a WordPress.org listing.

## Prepared

- English `readme.txt`: description, four supported sending routes, installation, FAQ, screenshots, external services, changelog and upgrade notices.
- English main-file description; existing English, French and Italian catalogs remain consistent.
- Real English dashboard screenshot with demonstration data, bundled at `assets/screenshots/screenshot-1.png`. The GitHub plugin-details modal shows the image locally, including in the Description tab.
- An honest distinction between queued, provider-accepted and confirmed-delivery states; no inbox-placement guarantee.
- Disclosure of provider data flows, optional alerts and the current GitHub update service, with provider terms and privacy links.

## Remaining before submission

| Item | Required action |
| --- | --- |
| Update channel | Build a separate WordPress.org distribution without `inc/updater.php`, its module-loader reference, `lib/plugin-update-checker` and the `Update URI` header. Do not disable updates for existing GitHub installations. WordPress.org supplies updates for its distribution. |
| External-service disclosure | In the directory build remove the GitHub update-service subsection and the GitHub-distribution installation paragraph, because that build must not contact GitHub for updates. Preserve disclosures for the actual sending and alert services. |
| Screenshot asset | Copy `assets/screenshots/screenshot-1.png` to the **top-level SVN** `assets/screenshot-1.png`, alongside `trunk/` and `tags/`. The numbered caption in `readme.txt` corresponds to this asset. Do not use a remote GitHub image URL in the directory listing. |
| Icons and banner | Prepare original MomentumLab branding if desired: `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png` and `banner-1544x500.png` in the SVN assets directory. No third-party provider logos as the plugin's own branding. |
| Contributor account | Verify that the `Contributors` value is the intended **WordPress.org username**. A GitHub login or display name is not proof that the WordPress.org account exists. |
| Translation workflow | Review compatibility with WordPress.org language packs. English source strings are recommended for the wider translation community; the current admin source language remains German with bundled translations. Do not claim that this task converted the entire UI source language. |
| Directory validation | Run Plugin Check against the actual directory build with directory rules enabled, without the current exceptions for the external updater. Address review findings in the distributed code, including bundled dependencies. |
| Submission | Validate the readme, submit the directory ZIP for review, respond to the review team, then upload code and assets to the assigned SVN repository after approval. Do not reuse the GitHub release ZIP unchanged. |

## Privacy and verification

The normal delivery log contains recipient information, subject, status, times and diagnostics, not message bodies or attachments. Optional pending queue payloads are encrypted and cleared after acceptance or expiry. Retention, WordPress data export/erasure and the privacy-policy suggestion remain available. There is no plugin telemetry or opening/click tracking.

A listing is not a guarantee of email delivery, provider approval or WordPress.org acceptance. Run the release checks on the exact artifact to be submitted.

## References

- [Readme format](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
- [Plugin assets and screenshot naming](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/)
- [Detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [Common submission issues, including external updaters](https://developer.wordpress.org/plugins/wordpress-org/common-issues/)
