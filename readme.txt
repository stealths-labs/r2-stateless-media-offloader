=== R2 Stateless Media Offloader ===
Contributors: wiiiimm
Tags: cloudflare, r2, media offload, s3, cdn
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload your WordPress media to Cloudflare R2 — zero egress fees, stateless. A clean-room, open-source alternative to wp-stateless.

== Description ==

Offload and serve your WordPress media from **Cloudflare R2** with **zero egress fees**. Unlike AWS S3 or Google Cloud Storage, R2 never charges to serve your files — you pay only for storage (~$0.015/GB-month).

Built for **stateless** operation: nothing persists on the server, making it ideal for containerised and ephemeral deployments (Kubernetes, App Platform).

**Features**

* Zero-egress media serving via Cloudflare R2 and your own custom domain
* Two modes — CDN (keep local copies as fallback) and Stateless (media lives only in R2)
* Built on R2's S3-compatible API (AWS Signature V4) — no AWS SDK dependency (R2-specific, not a generic multi-provider client)
* Catches every registered image size, including theme/plugin custom sizes
* Non-destructive — URLs are rewritten at render time; your database and post content are never modified
* Universal migrator — move existing media to R2 from local disk, GCS (wp-stateless), or S3 (WP Offload Media)
* Dual credentials — configure via the admin UI or wp-config.php constants
* WP-CLI support for large-library migrations
* Hook-based, not a stream wrapper — files stay real files while WordPress works on them, so every plugin and theme behaves exactly as on stock WordPress
* Zero R2 traffic during image processing — thumbnails generate uninterrupted, then one batched upload ships everything
* A URL never precedes its object — media URLs only switch to the CDN once every file is confirmed in R2
* Safe exit — `wp r2offload pull` restores everything to local uploads before you deactivate

== Installation ==

1. Upload the plugin to `/wp-content/plugins/r2-stateless-media-offloader/`.
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

= How is this different from other offload plugins? =

Three design choices: it hooks WordPress's standard media events instead of replacing the uploads directory with a virtual stream-wrapper path (so plugins and themes that touch files directly keep working); it never talks to R2 while WordPress is generating thumbnails (one batched upload afterwards — much friendlier to small servers); and it only switches an attachment's URLs to the CDN after every file is confirmed present in R2, so a fresh upload can never render a broken CDN URL.

= I already copied my media into R2 with another tool. Do I have to upload again? =

No. If your media is already in R2 (for example, copied from Google Cloud Storage using Cloudflare's R2 data migration, also known as Super Slurper), just run the migration. Files already present in R2 are detected and registered without re-uploading — nothing is copied twice — and the plugin starts serving them from R2.

== Changelog ==

= 0.3.0 =
* New uploads now offload in a single batched pass after WordPress finishes generating thumbnails — no R2 traffic interleaved with image processing. Much faster and more reliable on constrained hosts.
* Media URLs only switch to R2 once every file is confirmed in the bucket — a fresh upload can never render a CDN URL that 404s (and get it edge-cached as broken).
* Interrupted uploads self-heal: WordPress's post-process resume is fully supported via a shutdown backstop, and metadata-less programmatic inserts are offloaded too.
* Stateless local cleanup is decided at request end against final state, is multisite blog-aware, and retains regeneration sources (image and PDF originals) until the upload is proven complete.

= 0.2.1 =
* Fixed: `wp r2offload reset` aborts with a clear error if a database delete fails mid-run (re-run to resume) instead of reporting success.
* Fixed: `wp r2offload pull` accepts files already on disk when validating legacy registrations (CDN mode / partial-restore re-runs) instead of erroring, and its counts no longer include files that needed no download.
* Fixed: Retry All button is restored when every retry fails, rather than staying stuck disabled.
* Fixed: the Start confirmation now works even when clicked before the first status poll completes.

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
