<?php
/**
 * URL rewriter — serve offloaded media from R2 / the custom domain.
 *
 * Rewrites attachment URLs at render time. The WordPress database and post
 * content are never modified, so deactivating the plugin cleanly reverts to
 * default local URLs.
 *
 * Keys are resolved from the stored `_r2offload_key` (the original's actual R2
 * key captured at offload time), so rewriting stays correct even if the
 * path_prefix setting changes later (see SWR-313).
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

class URL_Rewriter {

	// Run the render-time URL filters late so that, when another offload plugin
	// (e.g. wp-stateless during a GCS->R2 migration) also filters these hooks at
	// the default priority 10, this plugin's rewrite deterministically wins for
	// the attachments it has adopted (synced) instead of depending on plugin
	// load order. Un-synced attachments pass through untouched, so the other
	// plugin's result still stands for those.
	const FILTER_PRIORITY = 99;

	/** @var R2_Client */
	private $client;

	/** @var Settings */
	private $settings;

	/**
	 * Within-request cache of attachment_id => original R2 key (or false).
	 *
	 * @var array<int,string|false>
	 */
	private $key_cache = array();

	/**
	 * Re-entrant suppression depth. While > 0 the render-time filters pass
	 * URLs through untouched. The migrator uses this to resolve an
	 * attachment's *source* URL (local / GCS / S3) without our own rewrite
	 * short-circuiting it to a not-yet-existing R2 object.
	 *
	 * @var int
	 */
	private static $suppress = 0;

	/**
	 * Toggle URL rewriting off (true) or back on (false). Calls nest, so a
	 * caller must pair every suppress( true ) with a suppress( false ).
	 *
	 * @param bool $on
	 */
	public static function suppress( $on ) {
		if ( $on ) {
			++self::$suppress;
		} elseif ( self::$suppress > 0 ) {
			--self::$suppress;
		}
	}

	/**
	 * Whether rewriting is currently suppressed.
	 *
	 * @return bool
	 */
	private static function is_suppressed() {
		return self::$suppress > 0;
	}

	/**
	 * @param R2_Client $client
	 * @param Settings  $settings
	 */
	public function __construct( R2_Client $client, Settings $settings ) {
		$this->client   = $client;
		$this->settings = $settings;
	}

	/**
	 * Hook the render-time URL filters.
	 */
	public function register() {
		// Only rewrite when offloaded media can actually be served publicly —
		// i.e. a custom domain is configured. Without one the only R2 URL we
		// could emit is the authenticated S3 API endpoint, which 403s the
		// unauthenticated requests browsers make, breaking every image. Leaving
		// the origin URLs untouched keeps the site working; an admin notice
		// (see Plugin) flags the misconfiguration.
		if ( ! $this->settings->serves_public_url() ) {
			return;
		}
		add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), self::FILTER_PRIORITY, 2 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_image_src' ), self::FILTER_PRIORITY, 4 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset' ), self::FILTER_PRIORITY, 5 );
		// Big-image full-resolution original — served via its own filter, not
		// wp_get_attachment_url, so it needs hooking too or it 404s in Stateless
		// mode once the local copy is removed.
		add_filter( 'wp_get_original_image_url', array( $this, 'filter_original_image_url' ), self::FILTER_PRIORITY, 2 );
		// Legacy thumbnail URL — wp_get_attachment_thumb_url() applies its own
		// filter on a sibling (same-directory) file and does NOT route through
		// wp_get_attachment_image_src, so themes/plugins calling it directly would
		// otherwise 404 the thumb in Stateless mode.
		add_filter( 'wp_get_attachment_thumb_url', array( $this, 'filter_thumb_url' ), self::FILTER_PRIORITY, 2 );
		// Images inserted into post content are stored in the DB as literal
		// /uploads/ URLs and never pass through the attachment filters above, so
		// in Stateless mode (locals deleted) their src would 404. wp_content_img_tag
		// (WP 6.0+) fires per content <img> with the attachment id core parsed from
		// the wp-image-{id} class, letting us rewrite precisely and only for synced
		// media — no DB-wide string replacement.
		add_filter( 'wp_content_img_tag', array( $this, 'filter_content_img_tag' ), self::FILTER_PRIORITY, 3 );
	}

	/**
	 * Rewrite the URL of a big-image upload's full-resolution original.
	 *
	 * @param string|false $url
	 * @param int          $attachment_id
	 * @return string|false
	 */
	public function filter_original_image_url( $url, $attachment_id ) {
		return $this->filter_sibling_url( $url, $attachment_id );
	}

	/**
	 * Rewrite a legacy thumbnail URL (wp_get_attachment_thumb_url). Same shape as
	 * the original-image filter: a single same-directory file.
	 *
	 * @param string|false $url
	 * @param int          $attachment_id
	 * @return string|false
	 */
	public function filter_thumb_url( $url, $attachment_id ) {
		return $this->filter_sibling_url( $url, $attachment_id );
	}

	/**
	 * Rewrite a single same-directory sibling URL (the original or a size) to its
	 * R2 equivalent, leaving it untouched when the attachment isn't offloaded or
	 * WordPress produced no URL.
	 *
	 * @param string|false $url
	 * @param int          $attachment_id
	 * @return string|false
	 */
	private function filter_sibling_url( $url, $attachment_id ) {
		if ( self::is_suppressed() || ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		$rewritten = $this->rewrite_same_dir( (int) $attachment_id, $url );
		return ( null !== $rewritten ) ? $rewritten : $url;
	}

	/**
	 * Rewrite the URL of an attachment's original file.
	 *
	 * @param string $url
	 * @param int    $attachment_id
	 * @return string
	 */
	public function filter_attachment_url( $url, $attachment_id ) {
		// Don't fabricate an R2 URL when WordPress produced none (false/empty), and
		// stay consistent with the other render filters' input guards.
		if ( self::is_suppressed() || ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		$key = $this->original_key( (int) $attachment_id );
		if ( false === $key ) {
			return $url;
		}
		return $this->client->get_object_url( $key );
	}

	/**
	 * Rewrite the URL inside an image src tuple ([url, width, height, …]).
	 *
	 * @param array|false $image
	 * @param int         $attachment_id
	 * @param string|int[] $size
	 * @param bool        $icon
	 * @return array|false
	 */
	public function filter_image_src( $image, $attachment_id, $size, $icon ) {
		if ( self::is_suppressed() || ! is_array( $image ) || empty( $image[0] ) ) {
			return $image;
		}
		// When $icon is requested for a NON-image attachment (PDF/zip/…),
		// WordPress returns a generic mime-type icon from wp-includes, not an
		// uploads file. rewrite_same_dir() would turn that icon's basename into
		// an R2 key that doesn't exist → a broken icon. (We can't just test the
		// uploads base URL here: with another offload plugin active the src may
		// legitimately be a remote URL.) Leave such icons untouched.
		if ( $icon && ! wp_attachment_is_image( (int) $attachment_id ) ) {
			return $image;
		}
		$rewritten = $this->rewrite_same_dir( (int) $attachment_id, $image[0] );
		if ( null !== $rewritten ) {
			$image[0] = $rewritten;
		}
		return $image;
	}

	/**
	 * Rewrite the src of an image embedded in post content.
	 *
	 * WordPress runs this filter (6.0+) for each <img> in the_content, passing the
	 * attachment id it resolved from the tag's `wp-image-{id}` class. Both the src
	 * AND any srcset are rewritten: a core-generated srcset already points at R2
	 * (via filter_srcset) and re-rewriting it is an idempotent no-op, but content
	 * stored with a LITERAL srcset (classic editor, imports, page builders) would
	 * otherwise keep its candidates on /uploads and 404 in Stateless mode. Images
	 * core can't tie to an attachment ($attachment_id === 0) or that aren't
	 * offloaded are left on their local URL.
	 *
	 * @param string $filtered_image The <img> tag HTML.
	 * @param string $context        Filter context (unused).
	 * @param int    $attachment_id  Attachment id from the wp-image-{id} class, or 0.
	 * @return string
	 */
	public function filter_content_img_tag( $filtered_image, $context, $attachment_id ) {
		if ( self::is_suppressed() || ! is_string( $filtered_image ) || '' === $filtered_image ) {
			return $filtered_image;
		}
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || false === $this->original_key( $attachment_id ) ) {
			return $filtered_image;
		}
		// Match the src attribute only — the leading space means `data-src` and
		// other *-src attributes are left untouched.
		$result = preg_replace_callback(
			'/(\ssrc=)(["\'])(.*?)\2/i',
			function ( $matches ) use ( $attachment_id ) {
				$rewritten = $this->rewrite_same_dir( $attachment_id, $matches[3] );
				if ( null === $rewritten ) {
					return $matches[0];
				}
				return $matches[1] . $matches[2] . esc_url( $rewritten ) . $matches[2];
			},
			$filtered_image,
			1
		);
		// preg_replace_callback returns null on a PCRE error (e.g. backtrack limit);
		// fall back to the unmodified tag so a render never emits an empty <img>.
		if ( null === $result ) {
			return $filtered_image;
		}
		// Rewrite a literal srcset attribute too (see method doc). Idempotent on an
		// already-R2 srcset, so running it on a core-generated one is harmless.
		$with_srcset = preg_replace_callback(
			'/(\ssrcset=)(["\'])(.*?)\2/i',
			function ( $matches ) use ( $attachment_id ) {
				return $matches[1] . $matches[2] . $this->rewrite_srcset_attr( $attachment_id, $matches[3] ) . $matches[2];
			},
			$result,
			1
		);
		return ( null === $with_srcset ) ? $result : $with_srcset;
	}

	/**
	 * Rewrite each candidate URL in a srcset attribute string to its R2
	 * equivalent, preserving the width/density descriptors. Candidates whose
	 * attachment isn't offloaded keep their original URL.
	 *
	 * @param int    $attachment_id
	 * @param string $srcset Raw srcset attribute value ("url 768w, url2 1024w").
	 * @return string
	 */
	private function rewrite_srcset_attr( $attachment_id, $srcset ) {
		$out = array();
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			// "URL [descriptor]" — split on the first whitespace run.
			$parts = preg_split( '/\s+/', $candidate, 2 );
			if ( ! is_array( $parts ) ) {
				// preg_split only returns false on a PCRE error (not possible for the
				// static /\s+/, and $candidate is non-empty here), but guard
				// defensively: leave the candidate as-is.
				$out[] = $candidate;
				continue;
			}
			$rewritten = $this->rewrite_same_dir( $attachment_id, $parts[0] );
			$url       = ( null !== $rewritten ) ? esc_url( $rewritten ) : $parts[0];
			$out[]     = isset( $parts[1] ) ? $url . ' ' . $parts[1] : $url;
		}
		return implode( ', ', $out );
	}

	/**
	 * Rewrite every source URL in a responsive srcset.
	 *
	 * @param array $sources
	 * @param array $size_array
	 * @param string $image_src
	 * @param array $image_meta
	 * @param int   $attachment_id
	 * @return array
	 */
	public function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( self::is_suppressed() || ! is_array( $sources ) ) {
			return $sources;
		}
		$attachment_id = (int) $attachment_id;
		foreach ( $sources as $width => $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}
			$rewritten = $this->rewrite_same_dir( $attachment_id, $source['url'] );
			if ( null !== $rewritten ) {
				$sources[ $width ]['url'] = $rewritten;
			}
		}
		return $sources;
	}

	/**
	 * Rewrite a local media URL to its R2 equivalent by keeping the original's
	 * R2 directory and swapping in the requested file's basename. Used for sizes
	 * and srcset entries, which share the original's directory.
	 *
	 * @param int    $attachment_id
	 * @param string $local_url
	 * @return string|null  R2 URL, or null if the attachment isn't offloaded.
	 */
	private function rewrite_same_dir( $attachment_id, $local_url ) {
		$key = $this->original_key( $attachment_id );
		if ( false === $key ) {
			return null;
		}
		// Already an R2 URL on our public base (e.g. a core-generated srcset
		// candidate that the content-tag pass re-processes) — return it unchanged.
		// Re-deriving the key from an already percent-encoded basename and
		// re-encoding it would double-encode '%' for non-ASCII/space filenames
		// (e.g. %E5%9B%BE → %25E5%259B%25BE), breaking the URL on every render.
		$base = $this->settings->public_base_url();
		if ( '' !== $base ) {
			// Match on a path boundary, not a raw prefix: a bare strpos would also
			// match a sibling host like "https://cdn.example.com.evil/…" and wrongly
			// skip rewriting. Accept the base exactly or followed by "/".
			$base_prefix = trailingslashit( $base );
			if ( $local_url === $base || 0 === strpos( (string) $local_url, $base_prefix ) ) {
				return $local_url;
			}
		}
		$dir = dirname( $key );
		$dir = ( '.' === $dir ) ? '' : trailingslashit( $dir );
		// Derive the filename from the URL PATH only — a query string or
		// fragment (e.g. a cache-buster like ?ver=123) would otherwise be folded
		// into the basename and the R2 key wouldn't match the stored object.
		$path     = wp_parse_url( $local_url, PHP_URL_PATH );
		$basename = wp_basename( is_string( $path ) && '' !== $path ? $path : $local_url );
		return $this->client->get_object_url( $dir . $basename );
	}

	/**
	 * The original file's R2 key for an attachment, or false when the
	 * attachment isn't offloaded (so its URL should be left untouched).
	 *
	 * Prefers the stored `_r2offload_key`; falls back to the current path_prefix
	 * only when the attachment is marked synced but predates key storage.
	 *
	 * @param int $attachment_id
	 * @return string|false
	 */
	private function original_key( $attachment_id ) {
		// array_key_exists (not isset) so a cached false — an attachment we've
		// already resolved as not-offloaded — counts as a hit and isn't re-queried
		// every render. Matches Local_Fallback::original_key().
		if ( ! array_key_exists( $attachment_id, $this->key_cache ) ) {
			$this->key_cache[ $attachment_id ] = $this->settings->resolve_object_key( $attachment_id );
		}
		return $this->key_cache[ $attachment_id ];
	}

	/**
	 * Drop the per-request key cache. Hooked on `switch_blog` (see Plugin): the
	 * cache is keyed by attachment ID, which is NOT unique across a multisite
	 * network, so it must not survive a switch to another blog.
	 */
	public function flush_request_cache() {
		$this->key_cache = array();
	}
}
