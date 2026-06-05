# Configuration

Every setting can be entered under **Settings → R2 Offload** or defined as a
`wp-config.php` constant. **Constants win** — when one is defined, its UI field is
shown read-only (locked), and the value is never written to the database.

See also: [MIGRATION.md](MIGRATION.md) and [ARCHITECTURE.md](ARCHITECTURE.md).

---

## Settings reference

| Field | Constant | Required | Notes |
|---|---|---|---|
| **Account ID** | `R2OFFLOAD_ACCOUNT_ID` | yes | Your Cloudflare account ID — also the subdomain of your R2 S3 endpoint (`<account>.r2.cloudflarestorage.com`). |
| **Access Key ID** | `R2OFFLOAD_ACCESS_KEY` | yes | The **Access Key ID** of an R2 API token (Object Read & Write). |
| **Secret Access Key** | `R2OFFLOAD_SECRET_KEY` | yes | The **Secret Access Key** of that token. Stored encrypted at rest (AES-256-GCM); never shown back or logged. |
| **Bucket** | `R2OFFLOAD_BUCKET` | yes | The target R2 bucket name. It must already exist — the token cannot create buckets. |
| **Custom Domain** | `R2OFFLOAD_CUSTOM_DOMAIN` | for serving | The domain that serves the bucket, e.g. `cdn.example.com`. **Required to serve media publicly** (see below). |
| **Cache-Control** | `R2OFFLOAD_CACHE_CONTROL` | no | Header sent with each uploaded object. Default `public, max-age=31536000` (1 year) — appropriate for immutable media. |
| **Path Prefix** | `R2OFFLOAD_PATH_PREFIX` | no | Object-key prefix, e.g. `uploads/`. Default empty. See the gotcha below. |
| **Mode** | `R2OFFLOAD_MODE` | no | `cdn` (default, keep local copies) or `stateless` (remove them). See Modes. |

```php
// wp-config.php
define( 'R2OFFLOAD_ACCOUNT_ID',    'your-account-id' );
define( 'R2OFFLOAD_ACCESS_KEY',    'your-access-key-id' );
define( 'R2OFFLOAD_SECRET_KEY',    'your-secret-access-key' );
define( 'R2OFFLOAD_BUCKET',        'your-bucket-name' );
define( 'R2OFFLOAD_CUSTOM_DOMAIN', 'cdn.example.com' );
define( 'R2OFFLOAD_MODE',          'cdn' );                    // 'cdn' | 'stateless'
define( 'R2OFFLOAD_PATH_PREFIX',   'uploads/' );
define( 'R2OFFLOAD_CACHE_CONTROL', 'public, max-age=31536000' );
```

### Credentials: use the Access Key, not the API token

In the Cloudflare dashboard, **R2 → Manage R2 API Tokens → Create Account API
token**, permission **Object Read & Write**, scoped to your bucket. Copy the
**Access Key ID** and **Secret Access Key** from the confirmation page — *not* the
`cfat_…` API token string.

### Test Connection

The **Test Connection** button validates the credentials currently in the form —
**including unsaved edits** — by listing the bucket. You don't have to save first.
The Secret field can be left blank to test against the already-saved secret.

---

## Custom Domain — why it's needed to serve

Media is served from R2 only through a **Custom Domain** attached to the bucket
(Cloudflare R2 → your bucket → Custom Domains). Without one, the only public R2
URL is the authenticated S3 endpoint, which returns 403 to the unauthenticated
requests browsers make — so the URL rewriter intentionally **stays off** until a
custom domain is set, and the site keeps emitting normal local URLs. An admin
notice flags this.

> The custom domain affects **serving only**. It plays no part in offload or
> migration, which authenticate to the bucket directly. See
> [MIGRATION.md](MIGRATION.md).

---

## Path Prefix — match it to your keys

The R2 object key for a file is `path_prefix` + the file's WordPress
uploads-relative path:

```
path_prefix = ''         →  2026/05/photo.jpg
path_prefix = 'uploads/' →  uploads/2026/05/photo.jpg
```

- Default is empty (keys mirror the uploads layout at the bucket root — the most
  predictable mapping).
- The prefix is **captured per attachment at offload time** (`_r2offload_key`), so
  **changing it later only affects new uploads** — existing media keeps resolving
  at its stored key.
- **When adopting media already in R2** (an external copy, or a re-run), the
  prefix must match where the objects actually are, or the existence check looks at
  the wrong key and re-uploads — leaving a duplicate. Pick the prefix to match your
  existing layout before the first migration.

Use a trailing slash (`uploads/`), no leading slash.

---

## Modes: CDN vs Stateless

| | **CDN mode** | **Stateless mode** |
|---|---|---|
| New uploads | pushed to R2, **local copy kept** | pushed to R2, **local copy removed** |
| Serving | from R2 (custom domain) | from R2 (custom domain) |
| Local disk | grows with the library | stays empty (media lives only in R2) |
| Image edit / regenerate | uses local files | files are **restored from R2 on demand**, then re-offloaded |
| Risk profile | non-destructive — safe on-ramp | removes locals; relies on the R2 read path |

**Recommended path:** start in **CDN mode**, migrate, verify media serves from R2,
then switch to **Stateless** for the disk-light end state. CDN is the safe on-ramp
because it never deletes a local file; Stateless is the committed mode.

Notes on Stateless:
- Local copies are only removed once the object is confirmed in R2 **and** a custom
  domain is configured to serve it (so deletion can't 404 the media).
- For `wp media regenerate` / the image editor, the plugin restores the needed
  file from R2 to the uploads directory (atomically) so WordPress can work on it,
  then the result is re-offloaded; on a read-only uploads directory it falls back
  to a temporary location and guards against corrupting the attachment's metadata.

---

## Deactivation & uninstall

- **Deactivate** — the URL rewriter stops; WordPress reverts to default (local)
  URLs immediately. Nothing in R2 or the database is changed. (In Stateless mode,
  switch back to CDN and pull media local *before* deactivating, since without the
  plugin nothing rewrites URLs to R2.)
- **Uninstall (delete)** — removes only this plugin's options and post-meta
  (including the encrypted secret). It **never deletes media** — your R2 objects and
  any local files are left exactly as they are.
