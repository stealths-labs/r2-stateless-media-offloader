<p align="center">
  <img src="assets/cover.png" alt="R2 Stateless Media Offloader" width="100%">
</p>

<h1 align="center">R2 Stateless Media Offloader</h1>

<p align="center">
  <strong>Offload your WordPress media library to Cloudflare R2.</strong><br>
  Zero egress fees · stateless · built for ephemeral/containerized WordPress — a clean-room, open-source alternative to wp-stateless, built for R2.
</p>

<p align="center">
  <a href="https://www.gnu.org/licenses/gpl-2.0"><img alt="License: GPLv2-or-later" src="https://img.shields.io/badge/License-GPLv2--or--later-blue.svg"></a>
  <a href="https://wordpress.org/"><img alt="WordPress 6.0+" src="https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg"></a>
  <a href="https://www.php.net/"><img alt="PHP 7.4+" src="https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg"></a>
  <a href="https://developers.cloudflare.com/r2/"><img alt="Cloudflare R2" src="https://img.shields.io/badge/Cloudflare-R2-f38020.svg"></a>
</p>

---

## Overview

Cloudflare R2 charges **nothing for egress** — unlike Amazon S3 or Google Cloud Storage, which bill per GB delivered. For a media-heavy WordPress site, serving images directly from R2 through Cloudflare's edge means you pay only for storage and nothing to deliver. (See the [Cloudflare R2 pricing page](https://developers.cloudflare.com/r2/pricing/) and your other providers' current rates for exact figures.)

This plugin offloads your media library to R2 and serves it from your own custom domain. Its focus is **stateless** operation: media lives only in object storage and nothing persists on the web server, which makes it well suited to containerised and ephemeral deployments (Kubernetes, App Platform, and similar).

## Features

- **Zero-egress delivery** via Cloudflare R2 and your own custom domain.
- **Two operating modes:**
  - **CDN** — keep local copies as a fallback. The safe on-ramp.
  - **Stateless** — remove local copies; media lives only in R2.
- **Built on R2's S3-compatible API** with native AWS Signature V4 — no AWS SDK dependency, just the WordPress HTTP API. (R2-specific, not a generic multi-provider S3 client.)
- **Complete size coverage** — offloads the original plus every registered intermediate size, including theme- and plugin-defined custom sizes.
- **Non-destructive** — URLs are rewritten at render time. Your database and post content are never modified, so deactivating the plugin cleanly reverts to default behaviour.
- **Universal migrator** — move an existing library to R2 from anywhere: local disk, Google Cloud Storage (wp-stateless), or S3 (WP Offload Media).
- **Flexible credentials** — configure through the admin UI or `wp-config.php` constants.
- **WP-CLI** support for migrating large libraries without browser timeouts.

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- A Cloudflare R2 bucket and an API token with **Object Read & Write** permission

## Installation

1. Copy the plugin to `wp-content/plugins/r2-stateless-media-offloader/`.
2. Activate it from the Plugins screen.
3. Configure your R2 credentials under **Settings → R2 Offload**, or in `wp-config.php` (see below).
4. Use **Test Connection** to verify, then run the migrator to offload existing media.

## Getting R2 credentials

1. In the Cloudflare dashboard, go to **R2 → Manage R2 API Tokens**.
2. Choose **Create Account API token**.
3. Set the permission to **Object Read & Write** and scope it to your bucket.
4. Copy the **Access Key ID** and **Secret Access Key** shown on the confirmation page.

> **Note:** Use the **Access Key ID** and **Secret Access Key** — not the `cfat_…` API token value. The bucket must already exist; the token cannot create buckets.

## Configuration

Settings can be entered in the admin UI or defined as constants. Constants take precedence: when one is defined, the corresponding UI field is shown as read-only.

```php
define( 'R2OFFLOAD_ACCOUNT_ID',    'your-account-id' );
define( 'R2OFFLOAD_ACCESS_KEY',    'your-access-key-id' );
define( 'R2OFFLOAD_SECRET_KEY',    'your-secret-access-key' );
define( 'R2OFFLOAD_BUCKET',        'your-bucket-name' );
define( 'R2OFFLOAD_CUSTOM_DOMAIN', 'cdn.example.com' );  // optional
define( 'R2OFFLOAD_MODE',          'stateless' );        // 'cdn' (default) or 'stateless'
define( 'R2OFFLOAD_PATH_PREFIX',   'uploads/' );         // optional key prefix
define( 'R2OFFLOAD_CACHE_CONTROL', 'public, max-age=31536000' );
```

| Setting | Constant | Description |
|---|---|---|
| Account ID | `R2OFFLOAD_ACCOUNT_ID` | Your Cloudflare account ID. |
| Access Key ID | `R2OFFLOAD_ACCESS_KEY` | R2 API token Access Key ID. |
| Secret Access Key | `R2OFFLOAD_SECRET_KEY` | R2 API token Secret Access Key. |
| Bucket | `R2OFFLOAD_BUCKET` | Target R2 bucket name. |
| Custom Domain | `R2OFFLOAD_CUSTOM_DOMAIN` | Domain that serves the bucket. Falls back to the R2 endpoint if unset. |
| Mode | `R2OFFLOAD_MODE` | `cdn` (keep local copies) or `stateless` (remove them). |
| Path Prefix | `R2OFFLOAD_PATH_PREFIX` | Object-key prefix, e.g. `uploads/`. Affects new uploads only. |
| Cache-Control | `R2OFFLOAD_CACHE_CONTROL` | Header sent with each object. |

## Migrating an existing library

The migrator copies each attachment — original and every size — into R2, reading from a local copy when present or fetching from the current public URL otherwise. This means you can migrate from wp-stateless (GCS), WP Offload Media (S3), or a plain local setup.

```bash
# Preview what would be migrated, including total size — uploads nothing.
wp r2offload sync --dry-run

# Run the migration in batches.
wp r2offload sync --batch=250

# Confirm every expected object exists in R2.
wp r2offload sync --verify

# Re-upload (replace) everything already in R2 — repairs a stale/wrong bucket.
wp r2offload sync --force
```

Each file is reported as **Uploaded** (new), **Updated** (replaced), **Adopted** (already in R2, registered for the first time — e.g. a Super Slurper copy), or **Skipped** (already registered by a prior run). See [docs/MIGRATION.md](docs/MIGRATION.md) for the full per-file decision flow.

Migrations are resumable and batched, so large libraries can be processed without timeouts.

### Already migrated with another tool?

If your media is already in R2 — for example, copied straight from Google Cloud Storage using Cloudflare's [R2 data migration](https://developers.cloudflare.com/r2/data-migration/) (Super Slurper) — just run the migration as normal. Files already present in R2 are **detected and registered without re-uploading** (nothing is copied twice), and the plugin starts serving them from R2. This works from both the WP-CLI command and the admin **Media → Migrate to R2** page.

## How it works

The plugin records each attachment's R2 object key in post metadata and rewrites media URLs at render time. The WordPress database and post content are never altered. Because the path prefix is captured per attachment at upload time, changing it later affects only new uploads — existing media continues to resolve correctly.

## Design & compatibility

A few deliberate choices set this plugin apart from other offloaders:

- **Hook-based, not a stream wrapper.** Some offloaders replace the uploads
  directory with a virtual `s3://`-style path. That breaks any plugin or theme
  that touches files directly — `realpath()`, `ZipArchive`, shelling out to a
  binary, string-built paths. This plugin intercepts WordPress's standard media
  hooks instead: files are real files on disk while WordPress works on them, so
  **every plugin and theme behaves exactly as it would on stock WordPress**.
- **Zero R2 traffic during image processing.** Thumbnail generation runs
  uninterrupted — no network calls interleaved between resizes. The upload to
  R2 happens once, as a single batched pass, after WordPress has finished all
  image work. Fast hosts don't notice; constrained hosts (small containers,
  shared CPU) notice a lot.
- **A URL never precedes its object.** Media URLs only switch to the CDN after
  *every* file — the original and each generated size — is confirmed present in
  R2. A fresh upload can never emit a CDN URL that 404s (and get that 404
  edge-cached against the very URL it will serve from).
- **Failure degrades safely, per mode.** In CDN mode, an incomplete offload
  falls back to serving local copies until coverage completes. In Stateless
  mode, the attachment keeps serving from R2 and the missing piece retries —
  and local cleanup is deferred until the upload is *proven* complete, keeping
  regeneration sources (image/PDF originals) on disk exactly as long as
  WordPress might still need them.
- **A clean exit.** `wp r2offload pull` restores every offloaded file to local
  uploads and clears the registration — deactivating never has to mean broken
  media.

## Documentation

- **[docs/CONFIGURATION.md](docs/CONFIGURATION.md)** — every setting and `wp-config` constant, the custom-domain requirement, the path-prefix gotcha, and CDN vs Stateless modes.
- **[docs/MIGRATION.md](docs/MIGRATION.md)** — how the migrator decides per file (Uploaded / Updated / Adopted / Skipped), the real-world states, Pause/Resume/Stop, large-library/WP-CLI tips, and a cutover runbook from another offloader.
- **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** — the components, the per-attachment metadata, and the request lifecycle.
- **[docs/HOOKS.md](docs/HOOKS.md)** — the filters the plugin exposes for developers (`r2offload_offload_on_upload`, `r2offload_mirror_deletes`, `r2offload_restore_to_uploads`, `r2offload_max_upload_bytes`).
- **[docs/CLI.md](docs/CLI.md)** — the `wp r2offload` command reference (`test` / `sync` / `pull` / `reset`) with every flag.
- **[docs/RELEASING.md](docs/RELEASING.md)** — contribution workflow: semantic PR titles, squash merges, and the batched release-PR model.

## Development

This repository includes a Docker-based development environment.

```bash
cp .env.example .env   # add your R2 test bucket credentials
./bin/dev-setup.sh     # starts WordPress, activates the plugin, runs the connection test
```

WordPress is then available at `http://localhost:8765/wp-admin` (`admin` / `admin`).

## License

The source code is licensed under GPL-2.0-or-later. See [LICENSE](LICENSE).

The project name, brand, and logos are trademarks of stealth-src and are not
covered by the GPL. See [TRADEMARK.md](TRADEMARK.md).

---

Created by [wiiiimm](https://github.com/wiiiimm) and maintained by [stealth-src](https://github.com/stealth-src). A clean-room implementation that owes its code to no other plugin.
