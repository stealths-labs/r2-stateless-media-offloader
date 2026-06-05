<?php
/**
 * Universal media library migrator.
 *
 * Walks the `attachment` post type and copies every attachment (plus every
 * registered intermediate size) into R2. For each attachment we pick the best
 * source we can find at runtime:
 *
 *   1. A local copy on disk (`get_attached_file()` exists).
 *   2. The current public URL served by whatever offload plugin is in place
 *      today (wp-stateless on GCS, WP Offload Media on S3/Spaces, …) via
 *      `wp_get_attachment_url()`. The basename is swapped for each size so
 *      all variants come from the same host.
 *
 * The R2 key is the attachment's `_wp_attached_file` relative path (e.g.
 * `2017/03/the-kitsches.jpg`), so the key space matches what WordPress
 * already records — no separate lookup table.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

/**
 * Migrate existing media library attachments into R2.
 */
class Migrator {

	// Aliases of the canonical keys on Settings (single source of truth).
	const META_SYNCED    = Settings::META_SYNCED;
	const META_SYNCED_AT = Settings::META_SYNCED_AT;
	const META_KEY       = Settings::META_KEY;

	/** @var R2_Client */
	private $client;

	/** @var Settings */
	private $settings;

	/** @var bool */
	private $dry_run = false;

	/** @var bool */
	private $verify = false;

	/** @var bool Force re-upload: replace objects already in R2 instead of adopting them. */
	private $force = false;

	/**
	 * Per-file download timeout in seconds. Matches WordPress's own
	 * `download_url()` default so large videos / PDFs aren't truncated.
	 *
	 * @var int
	 */
	private $download_timeout = 300;

	/**
	 * Optional callback invoked before each item is processed, so a long-running
	 * batch can keep an external resource alive (the runner uses it to refresh
	 * its lock between potentially-slow per-file fetches).
	 *
	 * @var callable|null
	 */
	private $heartbeat = null;

	/**
	 * @param R2_Client|null $client
	 * @param Settings|null  $settings
	 */
	public function __construct( ?R2_Client $client = null, ?Settings $settings = null ) {
		$this->client   = $client ? $client : Plugin::instance()->client();
		$this->settings = $settings ? $settings : Plugin::instance()->settings();
	}

	/**
	 * Toggle dry-run mode: count + size without uploading.
	 *
	 * @param bool $on
	 * @return self
	 */
	public function set_dry_run( $on ) {
		$this->dry_run = (bool) $on;
		return $this;
	}

	/**
	 * Toggle verify mode: HEAD-check expected keys in R2.
	 *
	 * @param bool $on
	 * @return self
	 */
	public function set_verify( $on ) {
		$this->verify = (bool) $on;
		return $this;
	}

	/**
	 * Toggle force mode: re-upload (replace) objects already in R2 instead of
	 * adopting them. Used to repair a bucket suspected to hold stale/wrong objects.
	 *
	 * @param bool $on
	 * @return self
	 */
	public function set_force( $on ) {
		$this->force = (bool) $on;
		return $this;
	}

	/**
	 * Override the per-file download timeout (seconds). Useful for libraries
	 * that contain large media (video / hi-res PDFs).
	 *
	 * @param int $seconds
	 * @return self
	 */
	public function set_download_timeout( $seconds ) {
		$this->download_timeout = max( 1, (int) $seconds );
		return $this;
	}

	/**
	 * Set a callback fired before each item is processed (see $heartbeat).
	 *
	 * @param callable|null $cb
	 * @return self
	 */
	public function set_heartbeat( $cb ) {
		$this->heartbeat = is_callable( $cb ) ? $cb : null;
		return $this;
	}

	// -----------------------------------------------------------------
	//  Public API
	// -----------------------------------------------------------------

	/**
	 * Migrate a single attachment (original + every registered size).
	 *
	 * @param int $attachment_id
	 * @return array { uploaded:int, skipped:int, bytes:int, errors:string[] }
	 */
	public function migrate_attachment( $attachment_id ) {
		$result = array(
			'uploaded' => 0, // New objects (were not in R2).
			'updated'  => 0, // Existing objects replaced (size mismatch, or forced).
			'adopted'  => 0, // Already in R2 (correct size); registered to WP for the first time.
			'skipped'  => 0, // Already in R2 AND already registered by a prior run — no-op.
			'bytes'    => 0,
			'errors'   => array(),
		);

		// Whether this attachment was already registered before this run decides
		// whether an already-present variant counts as Adopted (first registration)
		// or Skipped (re-run no-op). Captured once, before any per-variant work.
		$was_synced = (bool) get_post_meta( (int) $attachment_id, self::META_SYNCED, true );

		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			$result['errors'][] = __( 'Invalid attachment ID.', 'r2-stateless-media-offload' );
			return $result;
		}

		$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $relative ) {
			$result['errors'][] = sprintf(
				/* translators: %d: attachment ID */
				__( 'Attachment %d has no _wp_attached_file.', 'r2-stateless-media-offload' ),
				$attachment_id
			);
			return $result;
		}
		$relative = ltrim( $relative, '/' );

		$items = $this->build_items( $attachment_id, $relative );

		foreach ( $items as $item ) {
			// Heartbeat before each (possibly slow, remote) item so the runner
			// can keep its lock alive even when one attachment has many large
			// variants that together exceed the lock TTL.
			if ( null !== $this->heartbeat ) {
				call_user_func( $this->heartbeat );
			}
			$this->migrate_item( $attachment_id, $item, $result, $was_synced );
		}

		// Mark the attachment offloaded only when EVERY variant is in R2 — each
		// item either uploaded or already present, with no errors. Keying off
		// the original alone could mark an attachment whose size is missing in
		// R2, and the URL rewriter would then emit R2 URLs for that size that
		// 404 instead of serving the current source. Requiring all variants is
		// also exactly what external bulk copies (e.g. Cloudflare Super Slurper,
		// which copies every size) satisfy. Dry-run / verify never write meta.
		if (
			! $this->dry_run
			&& ! $this->verify
			&& empty( $result['errors'] )
			&& ( $result['uploaded'] + $result['updated'] + $result['adopted'] + $result['skipped'] ) > 0
		) {
			update_post_meta( $attachment_id, self::META_SYNCED, 1 );
			// Store the original's actual R2 key (SWR-313) so readers resolve it
			// independently of the current path_prefix. base_object_key()
			// preserves an existing stored key, so a re-sync after a path_prefix
			// change never rewrites it to the new prefix (which would split it
			// from where the objects actually live and the rewriter serves).
			update_post_meta( $attachment_id, self::META_KEY, $this->base_object_key( $attachment_id, $relative ) );
			// Record every key now confirmed in R2 (uploaded or adopted) into the
			// ownership manifest so delete reaps exactly what this attachment owns
			// (SWR-333). With no errors, every built item is present in R2.
			Settings::record_objects( $attachment_id, array_keys( $items ) );
			// Preserve the original first-sync timestamp on re-runs that find
			// every item already present in R2.
			$first_synced_at = get_post_meta( $attachment_id, self::META_SYNCED_AT, true );
			if ( '' === (string) $first_synced_at ) {
				update_post_meta( $attachment_id, self::META_SYNCED_AT, time() );
			}
		}

		return $result;
	}

	/**
	 * Resumable batch migration ordered by attachment ID.
	 *
	 * @param int    $batch_size
	 * @param string $cursor Last processed attachment ID (exclusive lower bound).
	 * @param int    $max_seconds Wall-clock budget; stop after the item that
	 *                            crosses it (0 = no limit). Keeps a batch from
	 *                            outliving the runner's lock TTL when items fetch
	 *                            remotely with long per-file timeouts.
	 * @return array {
	 *     processed:int, uploaded:int, skipped:int, bytes:int,
	 *     errors:string[], next_cursor:string, done:bool
	 * }
	 */
	public function migrate_batch( $batch_size = 100, $cursor = '', $max_seconds = 0 ) {
		$batch_size = max( 1, (int) $batch_size );
		$cursor_id  = '' === $cursor ? 0 : (int) $cursor;
		$max_seconds = max( 0, (int) $max_seconds );
		$started     = microtime( true );
		$timeboxed   = false;

		global $wpdb;
		// Fetch one extra row to peek whether more attachments follow this batch,
		// so a final batch of exactly $batch_size isn't mistaken for "maybe more"
		// (which would force a trailing empty tick and leave the run on "Running"
		// until cron fires again — bad when the count is a multiple of the batch).
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset-paginated attachment scan; prepared, and intentionally uncached (cross-request migration cursor).
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
					WHERE post_type = 'attachment'
					AND post_status != 'trash'
					AND ID > %d
					ORDER BY ID ASC
					LIMIT %d",
				$cursor_id,
				$batch_size + 1
			)
		);
		$has_more = count( $ids ) > $batch_size;
		if ( $has_more ) {
			$ids = array_slice( $ids, 0, $batch_size );
		}

		$aggregate = array(
			'processed'   => 0,
			'uploaded'    => 0,
			'updated'     => 0,
			'adopted'     => 0,
			'skipped'     => 0,
			'bytes'       => 0,
			'errors'      => array(),
			'next_cursor' => (string) $cursor_id,
			'done'        => true,
		);

		if ( empty( $ids ) ) {
			return $aggregate;
		}

		// Prime the post + post-meta caches for the whole batch in two bulk
		// queries so the per-item get_post_meta()/wp_get_attachment_metadata()
		// reads below hit the object cache instead of issuing one SELECT each —
		// turning ~batch_size round-trips into a constant two for the large-library
		// CLI/cron path. Guarded: _prime_post_caches() exists since WP 3.4 but was
		// only made public API in 6.1, and it's a pure optimisation — the per-item
		// reads work without it, so degrade gracefully rather than depend on it.
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_map( 'intval', $ids ), false, true );
		}

		foreach ( $ids as $raw_id ) {
			$id  = (int) $raw_id;
			$res = $this->migrate_attachment( $id );

			$aggregate['processed']  += 1;
			$aggregate['uploaded']   += (int) $res['uploaded'];
			$aggregate['updated']    += (int) $res['updated'];
			$aggregate['adopted']    += (int) $res['adopted'];
			$aggregate['skipped']    += (int) $res['skipped'];
			$aggregate['bytes']      += (int) $res['bytes'];
			$aggregate['next_cursor'] = (string) $id;

			foreach ( $res['errors'] as $err ) {
				$aggregate['errors'][] = sprintf( '[#%d] %s', $id, $err );
			}

			// Time-box: stop after the item that crosses the budget so the batch
			// can't run long enough for the runner's lock to expire under it.
			if ( $max_seconds > 0 && ( microtime( true ) - $started ) >= $max_seconds ) {
				$timeboxed = true;
				break;
			}
		}

		// Done only when no more rows follow (the +1 peek) AND we didn't stop
		// early on the time budget (which leaves more past the cursor).
		$aggregate['done'] = empty( $timeboxed ) && ! $has_more;
		return $aggregate;
	}

	// -----------------------------------------------------------------
	//  Internals
	// -----------------------------------------------------------------

	/**
	 * Build the unique list of (key, size, filename) items for an attachment.
	 *
	 * @param int    $attachment_id
	 * @param string $relative Original `_wp_attached_file` path.
	 * @return array<string,array{key:string,size:string,filename:string}>
	 */
	private function build_items( $attachment_id, $relative ) {
		// Shared enumeration (original + every size) so the migrator and the
		// offloader can never disagree on an attachment's file set. Keys anchor
		// on the attachment's base key — its stored _r2offload_key when already
		// synced, else the current path_prefix — exactly like the offloader,
		// delete path and URL rewriter. This keeps re-sync/verify HEADing and
		// uploading the SAME keys those paths use even after a path_prefix
		// change (SWR-313), instead of duplicating objects at a new prefix.
		$metadata = wp_get_attachment_metadata( $attachment_id );

		$base_key = $this->base_object_key( $attachment_id, $relative );
		$dir      = dirname( $base_key );
		$dir      = ( '.' === $dir ) ? '' : trailingslashit( $dir );

		$items = array();
		foreach ( Settings::enumerate_files( $metadata, $relative ) as $file ) {
			$key = ( '' === $file['size'] ) ? $base_key : $dir . $file['filename'];
			if ( isset( $items[ $key ] ) ) {
				continue; // Sizes can share a filename with the original.
			}
			$items[ $key ] = array(
				'key'      => $key,
				'size'     => $file['size'],
				'filename' => $file['filename'],
				'relative' => $file['relative'], // Uploads-relative path (canonical).
				// Byte size WordPress recorded for this file (original since 6.0,
				// per-size since 6.1), or null when unknown. Used to verify an
				// already-in-R2 object during no-local adoption — for free, with no
				// extra HEAD against the source backend.
				'filesize' => $this->recorded_filesize( $metadata, $file['size'] ),
			);
		}

		return $items;
	}

	/**
	 * The byte size WordPress recorded for a file in attachment metadata, or null
	 * when unknown. WP stores `filesize` for the original (since 6.0) and per size
	 * (since 6.1); older attachments and the kept full-res `original_image` have
	 * none. Lets adoption sanity-check an already-in-R2 object without a HEAD.
	 *
	 * @param mixed  $metadata wp_get_attachment_metadata() result.
	 * @param string $size     '' for the original, 'original_image', or a size name.
	 * @return int|null
	 */
	private function recorded_filesize( $metadata, $size ) {
		if ( ! is_array( $metadata ) ) {
			return null;
		}
		if ( '' === $size ) {
			return isset( $metadata['filesize'] ) ? (int) $metadata['filesize'] : null;
		}
		if ( 'original_image' === $size ) {
			return null; // WP records no filesize for the retained full-res original.
		}
		return isset( $metadata['sizes'][ $size ]['filesize'] ) ? (int) $metadata['sizes'][ $size ]['filesize'] : null;
	}

	/**
	 * The attachment's canonical R2 key for the original: its stored
	 * `_r2offload_key` when already synced (so it survives a later path_prefix
	 * change), else derived from the current path_prefix. Mirrors the offloader
	 * so every path agrees on keys. Relies on the same invariant: META_KEY is
	 * only ever written alongside META_SYNCED and never cleared on its own.
	 *
	 * @param int    $attachment_id
	 * @param string $relative
	 * @return string
	 */
	private function base_object_key( $attachment_id, $relative ) {
		$stored = (string) get_post_meta( $attachment_id, self::META_KEY, true );
		return ( '' !== $stored ) ? $stored : $this->settings->object_key( (string) $relative );
	}

	/**
	 * Migrate one (attachment, size) item — verify, dry-run, or upload.
	 *
	 * Mutates $result in place so the per-attachment summary aggregates the
	 * outcome for every variant.
	 *
	 * @param int   $attachment_id
	 * @param array $item   { key, size, filename }
	 * @param array $result Mutated in place.
	 * @return void
	 */
	private function migrate_item( $attachment_id, array $item, array &$result, $was_synced = false ) {
		$key  = $item['key'];
		$size = $item['size'];

		if ( $this->verify ) {
			if ( $this->client->object_exists( $key ) ) {
				$result['skipped'] += 1;
			} else {
				$result['errors'][] = sprintf(
					/* translators: %s: object key */
					__( 'Missing in R2: %s', 'r2-stateless-media-offload' ),
					$key
				);
			}
			return;
		}

		$local     = $this->local_path_for( $item );
		$has_local = ( '' !== $local && is_readable( $local ) );

		// HEAD R2 to classify the variant:
		//   - present + correct size → ADOPTED (first registration) or SKIPPED
		//     (a prior run already registered this attachment); no bytes moved.
		//     This is what lets us register media copied into R2 by an external
		//     tool (e.g. Cloudflare Super Slurper) without re-uploading.
		//   - present + wrong/unconfirmable size, OR Force → UPDATED (re-upload).
		//   - absent → UPLOADED (new).
		// The size check guards against trusting a truncated/wrong object: adoption
		// marks the attachment synced and authorises deleting the local copy in
		// Stateless mode, so a bad object must re-upload, not be adopted.
		//   - With a local copy: compare against filesize(local).
		//   - Without a local copy (migrate-from-GCS/S3): compare against the size
		//     WordPress recorded in metadata when known — catches a truncated
		//     external copy without a HEAD against the source. Unknown → trust.
		$head    = $this->client->head_object( $key );
		$existed = ( null !== $head );
		if ( $existed && ! $this->force ) {
			if ( $has_local ) {
				// If the HEAD omitted Content-Length we can't confirm — re-upload.
				$size_ok = ( null !== $head['size'] && (int) filesize( $local ) === (int) $head['size'] );
			} elseif ( null !== $head['size'] && null !== $item['filesize'] ) {
				$size_ok = ( (int) $head['size'] === (int) $item['filesize'] );
			} else {
				$size_ok = true; // No comparable sizes — trust existence.
			}
			if ( $size_ok ) {
				// Already in R2 and correct: adopt (register for the first time) or
				// skip (a prior run already registered this attachment).
				$result[ $was_synced ? 'skipped' : 'adopted' ] += 1;
				return;
			}
			// else: size unverifiable/mismatched — fall through and re-upload (Updated).
		}

		if ( $this->dry_run ) {
			$bytes = $this->measure_source( $attachment_id, $size, $item, $local, $has_local, $result, $key );
			if ( null !== $bytes ) {
				$result[ $existed ? 'updated' : 'uploaded' ] += 1;
				$result['bytes']                             += $bytes;
			}
			return;
		}

		$tmp        = '';
		$local_path = '';

		if ( $has_local ) {
			$local_path = $local;
		} else {
			$url = $this->url_for( $attachment_id, $size, $item );
			if ( '' === $url ) {
				$result['errors'][] = sprintf(
					/* translators: %s: object key */
					__( '%s: no source URL', 'r2-stateless-media-offload' ),
					$key
				);
				return;
			}
			$downloaded = $this->download_to_tempfile( $url );
			if ( is_wp_error( $downloaded ) ) {
				$result['errors'][] = sprintf( '%s: %s', $key, $downloaded->get_error_message() );
				return;
			}
			$tmp        = $downloaded; // Narrowed to string by the is_wp_error() guard above.
			$local_path = $tmp;
		}

		// $local_path is readable at this point (either checked via is_readable()
		// for the local case or by download_to_tempfile() for the remote case).
		$size_bytes = (int) filesize( $local_path );
		$headers    = array();
		$cc         = $this->settings->get( 'cache_control' );
		if ( '' !== $cc ) {
			$headers['Cache-Control'] = $cc;
		}

		$uploaded = $this->client->upload_file(
			$local_path,
			$key,
			$this->guess_content_type( $item['filename'] ),
			$headers
		);

		if ( '' !== $tmp ) {
			wp_delete_file( $tmp );
		}

		if ( is_wp_error( $uploaded ) ) {
			$result['errors'][] = sprintf( '%s: %s', $key, $uploaded->get_error_message() );
			return;
		}

		$result[ $existed ? 'updated' : 'uploaded' ] += 1;
		$result['bytes']                             += $size_bytes;
	}

	/**
	 * Dry-run measurement: report bytes without downloading.
	 *
	 * Uses filesize() for local copies and HEAD for remote URLs. Returns null
	 * if the source can't be measured (an error is appended to $result).
	 *
	 * @param int    $attachment_id
	 * @param string $size
	 * @param array  $item
	 * @param string $local
	 * @param bool   $has_local
	 * @param array  $result
	 * @param string $key
	 * @return int|null
	 */
	private function measure_source( $attachment_id, $size, array $item, $local, $has_local, array &$result, $key ) {
		if ( $has_local ) {
			$bytes = filesize( $local ); // Caller has already checked is_readable().
			return false === $bytes ? 0 : (int) $bytes;
		}
		$url = $this->url_for( $attachment_id, $size, $item );
		if ( '' === $url ) {
			$result['errors'][] = sprintf(
				/* translators: %s: object key */
				__( '%s: no source URL', 'r2-stateless-media-offload' ),
				$key
			);
			return null;
		}
		$head = wp_remote_head(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 5,
				'sslverify'   => true,
				// For cross-origin sources, validate each redirect hop so a 302
				// can't be followed into a private address. Same-origin fetches
				// (the site's own host, which may be a private IP on k8s/Docker)
				// keep redirects unrestricted.
				'reject_unsafe_urls' => ! $this->is_same_origin( $url, home_url() ),
			)
		);
		if ( is_wp_error( $head ) ) {
			$result['errors'][] = sprintf( '%s: %s', $key, $head->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $head );
		if ( $code < 200 || $code >= 300 ) {
			$result['errors'][] = sprintf(
				/* translators: 1: object key, 2: HTTP status */
				__( '%1$s: HEAD returned HTTP %2$d', 'r2-stateless-media-offload' ),
				$key,
				$code
			);
			return null;
		}
		$len = wp_remote_retrieve_header( $head, 'content-length' );
		return is_string( $len ) ? (int) $len : 0;
	}

	/**
	 * Resolve a local disk path for an item (original or size), if any exists.
	 *
	 * @param array $item Item with an uploads-relative `relative` path.
	 * @return string Absolute path, or '' when not derivable.
	 */
	private function local_path_for( array $item ) {
		// Resolve against the canonical uploads dir using the item's known
		// uploads-relative path. Deliberately NOT get_attached_file(): in
		// Stateless mode the Local_Fallback filter rewrites that to a temp
		// restore path, which would send every variant (and original_image)
		// under the temp dir instead of wp-content/uploads.
		if ( empty( $item['relative'] ) ) {
			return '';
		}
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		return trailingslashit( $uploads['basedir'] ) . ltrim( (string) $item['relative'], '/' );
	}

	/**
	 * Resolve the current public URL for an attachment/size by swapping the
	 * basename of the original URL — keeps every variant on the same host as
	 * whatever offload plugin is serving today.
	 *
	 * @param int    $attachment_id
	 * @param string $size
	 * @param array  $item
	 * @return string URL or '' if none derivable.
	 */
	private function url_for( $attachment_id, $size, array $item ) {
		// Suppress our own URL rewriter so we resolve the attachment's *source*
		// URL (local / GCS / S3), not an R2 URL that may not exist yet. Other
		// plugins' filters (e.g. wp-stateless serving from GCS) still apply.
		// try/finally so a throwing filter can't strand the suppress counter and
		// leave rewriting disabled for the rest of the request.
		URL_Rewriter::suppress( true );
		try {
			$base = wp_get_attachment_url( $attachment_id );
			// For a registered intermediate size, let the active offloader compute
			// the per-size source URL — it may map sizes to a different path/host
			// than a plain basename swap. Only trust it when it actually resolved
			// THIS file (basename match): a deregistered size makes
			// wp_get_attachment_image_src fall back to another size / the full
			// image, which we must NOT fetch as this size's source.
			$size_url = '';
			if ( '' !== $size && 'original_image' !== $size ) {
				$src = wp_get_attachment_image_src( $attachment_id, $size );
				if ( is_array( $src ) && ! empty( $src[0] ) && $this->url_basename( $src[0] ) === $item['filename'] ) {
					$size_url = (string) $src[0];
				}
			}
		} finally {
			URL_Rewriter::suppress( false );
		}
		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}
		if ( '' !== $size_url ) {
			$url = $size_url;
		} elseif ( '' === $size ) {
			$url = $base;
		} else {
			// Fallback: assume the size is a sibling basename in the original's
			// directory — true for local, wp-stateless (GCS) and WP Offload Media
			// (S3) default layouts.
			$pos = strrpos( $base, '/' );
			$url = ( false === $pos ) ? $base : substr( $base, 0, $pos + 1 ) . $item['filename'];
		}

		// SSRF guard for REMOTE sources: wp_http_validate_url() rejects
		// loopback / private / reserved-IP targets, so a crafted attachment URL
		// (or a compromised upstream URL filter) can't make the server fetch an
		// internal endpoint. But the site's OWN media URL is same-origin and
		// legitimately resolves to a private IP on staging / Docker / k8s — so
		// pass same-origin URLs through (fetching the site itself isn't SSRF),
		// and only validate cross-origin URLs. Same-origin requires scheme,
		// host AND port to match — a host-only match would let an attacker reach
		// a different service on the same hostname via an alternate port/scheme.
		if ( $this->is_same_origin( $url, home_url() ) ) {
			return $url;
		}
		return wp_http_validate_url( $url ) ? $url : '';
	}

	/**
	 * Basename of a URL's path, with any query string / fragment stripped — so a
	 * cache-buster (?ver=) can't pollute the comparison. Mirrors the URL rewriter.
	 *
	 * @param string $url
	 * @return string
	 */
	private function url_basename( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		return wp_basename( is_string( $path ) && '' !== $path ? $path : (string) $url );
	}

	/**
	 * Whether two URLs share the same origin (scheme + host + effective port).
	 *
	 * @param string $a
	 * @param string $b
	 * @return bool
	 */
	private function is_same_origin( $a, $b ) {
		$pa = wp_parse_url( (string) $a );
		$pb = wp_parse_url( (string) $b );
		if ( empty( $pa['host'] ) || empty( $pb['host'] ) ) {
			return false;
		}
		$scheme_a = strtolower( $pa['scheme'] ?? '' );
		$scheme_b = strtolower( $pb['scheme'] ?? '' );
		$default  = array(
			'http'  => 80,
			'https' => 443,
		);
		$port_a = isset( $pa['port'] ) ? (int) $pa['port'] : ( $default[ $scheme_a ] ?? 0 );
		$port_b = isset( $pb['port'] ) ? (int) $pb['port'] : ( $default[ $scheme_b ] ?? 0 );

		return $scheme_a === $scheme_b
			&& strtolower( $pa['host'] ) === strtolower( $pb['host'] )
			&& $port_a === $port_b;
	}

	/**
	 * Download a URL to a temp file via the WordPress HTTP API.
	 *
	 * @param string $url
	 * @return string|\WP_Error Tempfile path on success.
	 */
	private function download_to_tempfile( $url ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		// download_url() takes no reject_unsafe_urls arg, so add it via a scoped
		// http_request_args filter for cross-origin sources only — making WP
		// validate each redirect hop (no 302 into a private address). Same-origin
		// downloads keep redirects unrestricted (private-IP host on k8s/Docker).
		$cross_origin = ! $this->is_same_origin( $url, home_url() );
		if ( $cross_origin ) {
			add_filter( 'http_request_args', array( $this, 'reject_unsafe_redirects' ) );
		}
		// try/finally so the scoped filter is always removed even if download_url()
		// (or a hook it fires) throws — a leaked filter would reject redirects for
		// every later HTTP request in the process. Mirrors url_for().
		try {
			$tmp = download_url( $url, $this->download_timeout );
		} finally {
			if ( $cross_origin ) {
				remove_filter( 'http_request_args', array( $this, 'reject_unsafe_redirects' ) );
			}
		}
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		if ( ! is_string( $tmp ) || ! is_readable( $tmp ) ) {
			// Defensive: download_url() normally returns a readable path or WP_Error,
			// but if it ever hands back an unreadable path, delete it so it doesn't leak.
			if ( is_string( $tmp ) && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return new \WP_Error(
				'r2offload_download_failed',
				__( 'Downloaded tempfile is not readable.', 'r2-stateless-media-offload' )
			);
		}
		return $tmp;
	}

	/**
	 * http_request_args filter (scoped to cross-origin downloads) that turns on
	 * reject_unsafe_urls so WP validates every redirect hop.
	 *
	 * @param array $args
	 * @return array
	 */
	public function reject_unsafe_redirects( $args ) {
		$args['reject_unsafe_urls'] = true;
		return $args;
	}

	/**
	 * @param string $filename_or_path
	 * @return string MIME type, falling back to application/octet-stream.
	 */
	private function guess_content_type( $filename_or_path ) {
		$ft = wp_check_filetype( $filename_or_path );
		return ! empty( $ft['type'] ) ? (string) $ft['type'] : 'application/octet-stream';
	}
}
