=== R2 Stateless Media Offload ===
Contributors: wiiiimm
Tags: cloudflare, r2, media, offload, s3, object-storage, cdn, stateless
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
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

No. If your media is already in R2 (for example, copied from Google Cloud Storage with Cloudflare Super Slurper), just run the migration. Files already present in R2 are detected and registered without re-uploading — nothing is copied twice — and the plugin starts serving them from R2.

== Changelog ==

= 0.1.0 =
* Initial development release: SigV4 R2 client, dual-credential settings store, WP-CLI validation command.
