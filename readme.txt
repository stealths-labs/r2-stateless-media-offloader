=== R2 Stateless Media Offload ===
Contributors: wiiiimm
Tags: cloudflare, r2, media offload, s3, cdn
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload your WordPress media library to Cloudflare R2 — zero egress fees, S3-compatible, stateless. A clean-room alternative to wp-stateless built for R2.

== Description ==

Offload and serve your WordPress media from **Cloudflare R2** with **zero egress fees**. Unlike AWS S3 or Google Cloud Storage, R2 never charges to serve your files — you pay only for storage (~$0.015/GB-month).

Built for **stateless** operation: nothing persists on the server, making it ideal for containerised and ephemeral deployments (Kubernetes, App Platform).

**Features**

* Zero-egress media serving via Cloudflare R2 and your own custom domain
* Two modes — CDN (keep local copies as fallback) and Stateless (media lives only in R2)
* S3-compatible, AWS Signature V4 — no AWS SDK dependency
* Catches every registered image size, including theme/plugin custom sizes
* Non-destructive — URLs are rewritten at render time; your database and post content are never modified
* Universal migrator — move existing media to R2 from local disk, GCS (wp-stateless), or S3 (WP Offload Media)
* Dual credentials — configure via the admin UI or wp-config.php constants
* WP-CLI support for large-library migrations

== Installation ==

1. Upload the plugin to `/wp-content/plugins/r2-stateless-media-offload/`.
2. Activate it through the Plugins menu.
3. Configure your R2 credentials in **Settings → R2 Offload**, or via `wp-config.php` constants.
4. Use **Test Connection** to verify, then run the migrator to offload existing media.

== Frequently Asked Questions ==

= Does the bucket name need to match my domain? =

No. Unlike GCS, R2 lets you name the bucket anything and attach a custom domain separately in the Cloudflare dashboard.

= Will my media break if I deactivate the plugin? =

In CDN mode, local copies remain and WordPress serves them normally. URLs are rewritten at render time, so deactivating reverts to default behaviour with no database changes.

= Can I migrate from wp-stateless or WP Offload Media? =

Yes. The migrator pulls each attachment from wherever it currently lives — local, GCS, or S3 — and uploads it to R2.

= I already copied my media into R2 with another tool. Do I have to upload again? =

No. If your media is already in R2 (for example, copied from Google Cloud Storage using Cloudflare's R2 data migration, also known as Super Slurper), just run the migration. Files already present in R2 are detected and registered without re-uploading — nothing is copied twice — and the plugin starts serving them from R2.

== Changelog ==

= 0.2.0 =
* Background migration UI (Media → Migrate to R2): resumable WP-Cron-driven runs with live progress, Pause/Resume/Stop, activity log, and a "How does the background job work?" explainer.
* Secret Access Key is now stored as plaintext in the database (industry standard; survives WordPress updates and salt rotation). A database value overrides the `R2OFFLOAD_SECRET_KEY` constant; legacy encrypted values are migrated automatically on the next settings save.
* New WP-CLI commands for safe deactivation: `wp r2offload pull` (restore all offloaded files to local uploads, then clear registration) and `wp r2offload reset` (clear registration only).
* Per-error Retry button, Retry All, and Clear All Errors in the migration errors panel; Start asks for confirmation when unresolved errors exist.
* Migration runs a single pass (no automatic re-scans on large libraries); failed items are retried via the errors panel or by re-running sync.
* Fixed: a partial migration in Stateless mode no longer un-registers an attachment whose other variants exist only in R2.
* Fixed: missing source files (404 at origin) are counted as skipped rather than errors, and connection loss auto-pauses the run.

= 0.1.0 =
* Initial development release: SigV4 R2 client, dual-credential settings store, WP-CLI validation command.
