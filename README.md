# R2 Stateless Media Offload

> Offload your WordPress media library to **Cloudflare R2** — zero egress fees, S3-compatible, stateless. A clean-room, open-source alternative to wp-stateless built for R2.

[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

Created by [**wiiiimm**](https://github.com/wiiiimm), shipped by [**stealths-labs**](https://github.com/stealths-labs).

---

## Why

Cloudflare R2 has **zero egress fees** — unlike AWS S3 ($0.09/GB) or Google Cloud Storage. For a media-heavy WordPress site, serving images straight from R2 through Cloudflare's edge means you pay only for storage (~$0.015/GB-month) and nothing to serve.

This plugin offloads your media library to R2 and serves it from your own custom domain, with a focus on **stateless** operation — nothing persists on the server, which is ideal for containerised / ephemeral deployments (Kubernetes, App Platform, etc.).

## Features

- **Zero egress** media serving via Cloudflare R2 + your custom domain
- **Two modes:**
  - **CDN** — keep local copies as a fallback (safe on-ramp)
  - **Stateless** — remove local copies; media lives only in R2 (container-native)
- **S3-compatible**, AWS Signature V4 — no AWS SDK dependency, just the WordPress HTTP API
- **Catches every image size**, including theme/plugin-registered custom sizes
- **Non-destructive** — URLs rewritten at render time; your database and post content are never modified. Deactivate and everything reverts.
- **Universal migrator** — move an existing library to R2 from **anywhere**: local disk, GCS (wp-stateless), or S3 (WP Offload Media)
- **Dual credentials** — configure via the admin UI *or* `wp-config.php` constants
- **WP-CLI** support for large-library migrations

## Status

🚧 **Early development.** Building toward a first release. See the [project board](https://github.com/stealths-labs/r2-stateless-media-offload/issues).

## Configuration

Credentials resolve from `wp-config.php` constants first, falling back to the admin settings UI:

```php
define( 'R2OFFLOAD_ACCOUNT_ID',   '...' );
define( 'R2OFFLOAD_ACCESS_KEY',   '...' );
define( 'R2OFFLOAD_SECRET_KEY',   '...' );
define( 'R2OFFLOAD_BUCKET',       'my-media-bucket' );
define( 'R2OFFLOAD_CUSTOM_DOMAIN','cdn.example.com' );
define( 'R2OFFLOAD_MODE',         'stateless' ); // or 'cdn'
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

*A clean-room implementation — owes its code to no other plugin. Built while optimising a production WordPress deployment.*
