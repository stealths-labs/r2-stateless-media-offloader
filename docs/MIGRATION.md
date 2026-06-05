# Migration guide

How the migrator copies your existing media library into R2, what every status
counter means, and how to run a safe cutover from another offloader.

For day-to-day settings see [CONFIGURATION.md](CONFIGURATION.md); for the moving
parts see [ARCHITECTURE.md](ARCHITECTURE.md).

---

## What the migrator does

It walks the attachment library and, for each attachment, expands it into
**variants** — the original file plus every registered intermediate size
(thumbnail, medium, large, theme/plugin sizes, the big-image original, etc.).
Each variant is decided **independently and idempotently**.

The migrator talks to the R2 bucket directly over the **authenticated S3 API**
(account ID + bucket + keys, AWS SigV4). It does **not** go through your custom
domain — so the custom domain you serve from is irrelevant to migration; only the
**bucket** and the **object key** (`path_prefix` + the file's uploads-relative
path) matter.

### Per-variant decision

```
For each variant:  key = <path_prefix> + <uploads-relative path>
                   e.g.  uploads/2026/05/photo-300x200.jpg

         ┌─────────────────────────────────────────────┐
         │  HEAD <key> on the R2 bucket (S3 API, auth)  │   ← not the CDN domain
         └───────────────────────┬─────────────────────┘
                                  │
              ┌───────────────────┴───────────────────┐
          exists                                   not found
              │                                         │
        size matches?                                   │
        (vs the local file, or vs the size WP           │
         recorded in metadata; unknown → trust)         │
        ┌─────┴───────┐                                 │
       yes         no / cannot confirm ────────────────►│  (must upload)
        │            (or Force mode)                      │
   ADOPTED / SKIPPED                       ┌──────────────┴──────────────┐
   (already in R2, correct —          local copy?                    no local copy
    no transfer)                          │                              │
        │                             use local            fetch the current public URL
        │                                 │                (GCS via wp-stateless / S3 / local),
        │                                 │                with this plugin's rewriter suppressed
        │                                 └──────────────┬──────────────┘
        │                                                │
        │                              download → PUT to R2 → UPLOADED (new) / UPDATED (replaced)
        └─────────────────────────────┬──────────────────┘
                                       ▼
   After ALL variants, if there were no errors and at least one variant is present,
   register the attachment:
        _r2offload_synced  = 1
        _r2offload_key     = <the original's key>
        _r2offload_objects = [ every variant key ]   (the delete manifest)
```

Two things to take away:

- **"Already in R2" still registers the attachment.** Adoption writes the
  post-meta (`_r2offload_synced`, key, manifest) — that's what makes this plugin
  take over serving and deletion. It just doesn't move bytes.
- **The size check is a safety valve.** An object that exists but is the wrong
  size (truncated / partial / a botched external copy) is **not** adopted — it is
  re-uploaded. This matters because adoption authorises deleting the local copy in
  Stateless mode; adopting a corrupt object would be data loss.

---

## Status counters — what they mean

The progress line and the WP-CLI summary report outcomes **per variant**:

| Counter | Bytes moved? | WP record | Meaning |
|---|---|---|---|
| **Uploaded** | yes | registered | The object was **not** in R2 → newly copied up. |
| **Updated** | yes | registered | The object **existed but was the wrong size** (truncated/partial), or you chose **Force** → it was replaced. |
| **Adopted** | no | registered now | The correct object was **already in R2** (e.g. copied by Cloudflare Super Slurper) and this run **registers it to WordPress for the first time** — no bytes moved. |
| **Skipped** | no | unchanged | The object was already in R2 **and already registered by a previous run** → nothing to do. |
| **Errors** | — | — | The variant could not be migrated (no source URL, download/upload failure, …). The attachment is left unregistered and retried on a later pass. |

The split that matters: **Adopted vs Skipped** is decided by whether the
*attachment* was already registered (`_r2offload_synced`) before this run. So the
first migration of a Super-Slurper'd library reports **Adopted N**, and re-runs
report **Skipped N**.

`processed` counts **attachments** walked (not variants); `migrated` (the
"Migrated to R2: X / Y" line) is a library-wide count of attachments already
registered on R2, independent of the current pass.

---

## The three real-world states of a file

A given file can be in any of these before you migrate — because of an external
bulk copy (Cloudflare Super Slurper / R2 data migration), a previous partial run,
or a fresh start. The migrator handles all of them, per variant:

```
                        HEAD <path_prefix + path> on YOUR bucket
                                       │
  ┌────────────────────────────────────────────────────────────────────────┐
  │ State                                  │ HEAD result   │ Action           │
  ├────────────────────────────────────────┼───────────────┼──────────────────┤
  │ 1. In source only (local/GCS/S3),      │ not found     │ download → upload │
  │    nothing in R2                        │               │  (UPLOADED)       │
  │ 2a. In R2, same bucket+key, different   │ found (domain │ ALREADY IN R2     │
  │     CDN domain                          │  is ignored)  │  (adopt)          │
  │ 2b. In R2 but at a DIFFERENT key        │ not found at  │ re-upload at the  │
  │     (different path_prefix)             │  computed key │  new key → a      │
  │                                         │               │  DUPLICATE        │
  │ 2c. In a DIFFERENT bucket               │ not found     │ uploads into YOUR │
  │                                         │               │  bucket (≈ state 1)│
  │ 3. In R2, same bucket+key, correct size │ found+size ok │ ALREADY IN R2     │
  │                                         │               │  (adopt)          │
  └────────────────────────────────────────┴───────────────┴──────────────────┘
```

- **Custom/CDN domain never affects migration** — the HEAD hits the bucket via the
  S3 API. A different domain pointing at the *same bucket* is a non-issue.
- **Path prefix is critical.** Adoption only works when `path_prefix + relative`
  equals where the object actually sits in the bucket. A mismatched prefix makes
  the HEAD look in the wrong place and re-uploads to the new key — leaving a
  duplicate at the old prefix. Match the prefix to your existing layout.

Because every variant is checked independently, **mixed and partial states are
safe**: a re-run adopts what's already correct and uploads only what's missing.
The migration is idempotent — running it again never re-transfers correct objects.

---

## Modes (upload / dry-run / verify)

| Mode | Behaviour |
|---|---|
| **Migrate (upload)** | The full flow above — adopt what's present, upload what's missing, register attachments. |
| **Force re-upload** | Like Migrate, but **never adopts** — every object is re-uploaded (reported as **Updated**), replacing whatever is in R2. Use to repair a bucket you suspect holds stale/wrong objects. Re-sends bytes (R2 Class A ops + source egress), so use deliberately. Single pass (no retry loop). |
| **Dry run** | Counts and measures only — no uploads, no meta writes. Reports what *would* upload/update vs what's already in R2, including total bytes. |
| **Verify** | HEADs every expected key; present → counted; **missing → error**. A read-only audit that nothing is broken. Writes nothing. |

---

## Pause, Resume, Stop — does it start over?

The migration runs in the background (cron-driven) and survives leaving the page.

```
  RUNNING ──Pause──► PAUSED ──Resume──► RUNNING        Resume CONTINUES from the cursor
     │                  │                              (no re-scan)
     │                  └──Stop──► STOPPED (terminal)
     └──Stop──► STOPPED
  STOPPED / DONE / IDLE ──Start──► RUNNING             Start RE-SCANS from the top, but every
                                                       already-migrated variant is HEAD-skipped,
                                                       so nothing is re-uploaded (a fast re-walk)
```

- **Pause → Resume** continues exactly where it left off (the cursor is
  preserved). No work is repeated.
- **Stop** is *terminal* (not resumable). The next **Start** re-scans from the
  beginning — but already-migrated variants are adopted via a single HEAD each, so
  **nothing is re-uploaded**. You never lose real work; the cost of "starting
  over" is cheap re-verification, not re-transfer.
- **Retry passes:** within a run, a pass that ends with errors automatically
  re-scans (up to a bounded number of passes) to retry the failed items, skipping
  the done ones.

---

## Large libraries: use WP-CLI

For big libraries, WP-CLI avoids browser timeouts and is the recommended path:

```bash
# Preview (counts + total size, uploads nothing)
wp r2offload sync --dry-run

# Migrate in batches
wp r2offload sync --batch=250

# Audit that every expected object exists in R2
wp r2offload sync --verify
```

The CLI and the admin **Media → Migrate to R2** page share the same engine and
state, and the CLI refuses to run while a background (admin) run holds the lock,
so they can't double-process.

---

## Already copied your media to R2 another way?

If you've already bulk-copied your media into R2 — e.g. with Cloudflare's
[R2 data migration](https://developers.cloudflare.com/r2/data-migration/) (Super
Slurper) — just run Migrate. Every variant already present (and correct size) is
adopted (registered, not re-uploaded). Make sure your **Path Prefix matches the
keys Super Slurper wrote**, or the HEADs won't find them and you'll get duplicates.

---

## Cutover from another offloader (e.g. wp-stateless / GCS)

When you're replacing a live offloader whose media this plugin will read **and**
serve from the same domain, ordering matters — the migrator reads the *source*
through the current public URL, so the source must stay reachable until the copy
is done.

1. **Leave DNS as-is** — the source domain keeps pointing at the old store, and
   the old offloader stays **active** (the migrator needs it to resolve source
   URLs).
2. **Configure this plugin** — credentials + bucket. Set **Path Prefix to match
   the existing object keys**. Leave **Custom Domain blank** and stay in **CDN
   mode** so this plugin doesn't take over serving yet.
3. **Migrate** (CLI or admin). It copies source → R2; the site keeps serving from
   the old store.
4. **Verify** — counts match; spot-check keys exist in R2 (`--verify`).
5. **Cutover (together):** repoint the domain to the R2 bucket, set this plugin's
   **Custom Domain**, and **deactivate the old offloader**.
6. **Verify** media serves from R2.
7. Optionally switch to **Stateless** mode once confident.
8. Keep the old store ~1 month as a fallback before decommissioning.

> If the domain/keys are identical before and after, existing hard-coded URLs in
> post content keep resolving across the cutover; dynamically generated URLs
> (srcset, featured images, REST, theme calls) are produced by this plugin's
> rewriter, which only acts on attachments registered by the migration — another
> reason adoption (the "already in R2" path) still does essential work.
