# Architecture

A high-level map of the moving parts. For usage see
[CONFIGURATION.md](CONFIGURATION.md) and [MIGRATION.md](MIGRATION.md).

The plugin is **non-destructive**: it stores a little post-meta per attachment and
rewrites URLs at render time. The WordPress database and post content are never
altered, so deactivating cleanly reverts to default local URLs.

---

## Components (`includes/`)

| Class | Role |
|---|---|
| `Settings` | Resolves config (wp-config constant → DB option → default). Encrypts/decrypts the secret. Builds object keys and the public base URL. |
| `R2_Client` | S3-compatible R2 access with native AWS SigV4 over the WordPress HTTP API (no AWS SDK): `upload`, `download`, `head`, `list`, `delete`. |
| `Offloader` | On new uploads, pushes the original + every size to R2 and records the meta; on `delete_attachment`, reaps the attachment's R2 objects via the manifest. |
| `URL_Rewriter` | At render time, rewrites media URLs (attachment URL, `src`, `srcset`, original-image, thumbnail) to the custom domain — only for offloaded attachments. |
| `Local_Fallback` | Stateless read path: restores a file from R2 on demand for image edits / regeneration, and guards attachment metadata against temp-path corruption. |
| `Migrator` + `Migration_Runner` | Bulk-migrate the existing library: per-variant adopt/upload, batched and resumable, driven by WP-Cron with an option-based lock + compare-and-swap state. |
| `Admin_Settings` / `Admin_Migration` | The Settings and Media → Migrate to R2 screens (+ AJAX). |
| `CLI` | `wp r2offload sync` (and friends) for large libraries. |

---

## Per-attachment metadata

| Meta key | Meaning |
|---|---|
| `_r2offload_synced` | `1` once the attachment is fully on R2 (presence = "migrated"). |
| `_r2offload_key` | The original's actual R2 key, captured at offload — so resolution is stable even if `path_prefix` changes later. |
| `_r2offload_objects` | The **ownership manifest**: every R2 key this attachment owns, so deletion reaps exactly those and no more. |
| `_r2offload_synced_at` | First-sync timestamp. |

These are the only persistent traces; uninstall removes them (never the media).

---

## Lifecycle

```
  Upload ──► Offloader ──► R2 bucket  (original + all sizes)
                │
                └─► writes _r2offload_synced / _r2offload_key / _r2offload_objects

  Page render ──► URL_Rewriter ──► https://<custom-domain>/<key>   (offloaded attachments only)

  Stateless image edit / regenerate ──► Local_Fallback restores the file from R2
                                        ──► WordPress edits it ──► Offloader re-offloads

  Delete attachment ──► Offloader.delete() ──► removes the manifest's R2 objects

  Existing library ──► Migrator (CLI or admin) ──► adopt-or-upload each variant ──► register
```

---

## Design choices worth knowing

- **S3 API for bucket operations; custom domain for serving only.** Offload,
  migration, adoption, and deletion all authenticate to the bucket directly
  (`<account>.r2.cloudflarestorage.com`). The custom domain is used purely to build
  browser-facing URLs. (This is why the CDN domain never affects migration.)
- **Keys are stored, not recomputed.** Each attachment's `_r2offload_key` is fixed
  at offload time, so a later `path_prefix` change can't split an attachment from
  where its bytes live.
- **Idempotent migration.** Each variant is independently existence-checked
  (with a size guard), so external copies (Super Slurper), partial runs, and
  stop/restart all converge without re-transferring correct objects.
- **Zero egress by design.** R2 charges nothing for egress; served through a
  Cloudflare custom domain, delivery is free and only storage is billed.
- **Render-time, reversible rewriting.** No database rewrite, no content
  mutation — deactivate and you're back to stock WordPress URLs.
