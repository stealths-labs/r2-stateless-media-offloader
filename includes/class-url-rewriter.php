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
		add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_image_src' ), 10, 4 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset' ), 10, 5 );
		// Big-image full-resolution original — served via its own filter, not
		// wp_get_attachment_url, so it needs hooking too or it 404s in Stateless
		// mode once the local copy is removed.
		add_filter( 'wp_get_original_image_url', array( $this, 'filter_original_image_url' ), 10, 2 );
	}

	/**
	 * Rewrite the URL of a big-image upload's full-resolution original.
	 *
	 * @param string|false $url
	 * @param int          $attachment_id
	 * @return string|false
	 */
	public function filter_original_image_url( $url, $attachment_id ) {
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
		if ( self::is_suppressed() ) {
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
		$rewritten = $this->rewrite_same_dir( (int) $attachment_id, $image[0] );
		if ( null !== $rewritten ) {
			$image[0] = $rewritten;
		}
		return $image;
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
		$dir = dirname( $key );
		$dir = ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir );
		return $this->client->get_object_url( $dir . wp_basename( $local_url ) );
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
		if ( ! isset( $this->key_cache[ $attachment_id ] ) ) {
			$this->key_cache[ $attachment_id ] = $this->settings->resolve_object_key( $attachment_id );
		}
		return $this->key_cache[ $attachment_id ];
	}
}
