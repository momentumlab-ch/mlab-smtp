# MomentumLab SMTP mail identity

Selected direction: the mail symbol, using MomentumLab's black/white and gold identity with Montserrat.

- `assets/icon.svg`, `icon-128x128.png`, `icon-256x256.png`: directory/update icons.
- `assets/brand/logo.svg`, `logo-dark.svg`: full wordmark for light/dark backgrounds. The admin header uses the light version.
- `assets/brand/banner.svg`, `assets/banner-772x250.png`, `banner-1544x500.png`: vector master and the two directory/detail banner sizes. The details filter explicitly maps low/high density correctly.
- The WordPress admin menu uses a small monochrome version of the same mail symbol.

The wordmark uses actual Montserrat Light (300) and Medium (500) glyphs converted to SVG paths. It requires no font download or external rendering service at runtime. The font's SIL Open Font License is included in `assets/brand/OFL-Montserrat.txt`. Font source: https://github.com/google/fonts/tree/main/ofl/montserrat.

For WordPress.org copy the numbered screenshots and named icons/banners to the top-level SVN `assets` directory. GitHub distributions discover the local `assets/icon*` and banner files through the bundled updater. No source mockup board is shipped in the plugin.
