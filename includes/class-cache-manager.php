<?php
namespace IDESolutions\AssetSwap;

defined( 'ABSPATH' ) || exit;

final class Cache_Manager {
	/**
	 * Purge WordPress and known cache layers after a media replacement.
	 *
	 * Cache integrations are intentionally isolated so a third-party cache
	 * plugin failure cannot turn a completed media replacement into a fatal error.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $url           Attachment URL.
	 * @return string[] Human-readable cache labels that were purged.
	 */
	public function purge( int $attachment_id, string $url ): array {
		$purged = array();

		// Clear what WordPress cached about this attachment. A full object-cache flush would also throw away every
		// other cached item on the site (and on a shared Redis or Memcached server, other sites' items), so it is opt-in.
		clean_post_cache( $attachment_id );
		clean_attachment_cache( $attachment_id );
		wp_cache_delete( $attachment_id, 'posts' );
		wp_cache_delete( $attachment_id, 'post_meta' );
		$purged[] = __( 'WordPress attachment cache', 'media-switcheroo' );

		/**
		 * Filters whether to flush the entire WordPress object cache after a replacement.
		 *
		 * @param bool $flush         Whether to flush everything. Default false.
		 * @param int  $attachment_id Attachment ID.
		 */
		if ( apply_filters( 'ides_asset_swap/flush_object_cache', false, $attachment_id ) && function_exists( 'wp_cache_flush' ) ) {
			try {
				if ( false !== wp_cache_flush() ) {
					$purged[] = __( 'WordPress object cache', 'media-switcheroo' );
				}
			} catch ( \Throwable $e ) {
				$this->log_cache_error( 'WordPress object cache', $e );
			}
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			$this->attempt( 'W3 Total Cache', static fn() => w3tc_flush_all(), $purged );
		} elseif ( function_exists( 'w3tc_pgcache_flush' ) ) {
			$this->attempt( 'W3 Total Cache', static fn() => w3tc_pgcache_flush(), $purged );
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			$this->attempt( 'WP Rocket', static fn() => rocket_clean_domain(), $purged );
		}

		if ( defined( 'LSCWP_DIR' ) || has_action( 'litespeed_purge_all' ) ) {
			$this->attempt( 'LiteSpeed Cache', static fn() => do_action( 'litespeed_purge_all' ), $purged );
		}

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			$this->attempt( 'SiteGround Optimizer', static fn() => sg_cachepress_purge_cache(), $purged );
		}

		global $wp_fastest_cache;
		if ( is_object( $wp_fastest_cache ) && method_exists( $wp_fastest_cache, 'deleteCache' ) ) {
			$this->attempt( 'WP Fastest Cache', static fn() => $wp_fastest_cache->deleteCache(), $purged );
		}

		if ( defined( 'BREEZE_VERSION' ) || has_action( 'breeze_clear_all_cache' ) ) {
			$this->attempt( 'Breeze', static fn() => do_action( 'breeze_clear_all_cache' ), $purged );
		}

		if ( class_exists( 'Cache_Enabler' ) || has_action( 'cache_enabler_clear_complete_cache' ) ) {
			$this->attempt( 'Cache Enabler', static fn() => do_action( 'cache_enabler_clear_complete_cache' ), $purged );
		}

		if ( class_exists( '\\autoptimizeCache' ) && method_exists( '\\autoptimizeCache', 'clearall' ) ) {
			$this->attempt( 'Autoptimize', static fn() => \autoptimizeCache::clearall(), $purged );
		}

		if ( class_exists( '\\WpeCommon' ) ) {
			$this->attempt(
				'WP Engine',
				static function () {
					if ( method_exists( '\\WpeCommon', 'purge_memcached' ) ) {
						\WpeCommon::purge_memcached();
					}
					if ( method_exists( '\\WpeCommon', 'purge_varnish_cache' ) ) {
						\WpeCommon::purge_varnish_cache();
					}
					if ( method_exists( '\\WpeCommon', 'clear_maxcdn_cache' ) ) {
						\WpeCommon::clear_maxcdn_cache();
					}
				},
				$purged
			);
		}

		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) && method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
			$this->attempt( 'Elementor files', static fn() => \Elementor\Plugin::$instance->files_manager->clear_cache(), $purged );
		}

		if ( has_action( 'fastpixel/purge/all' ) ) {
			$this->attempt( 'FastPixel', static fn() => do_action( 'fastpixel/purge/all' ), $purged );
		}

		try {
			/**
			 * Allow integrations to purge additional caches/CDNs.
			 *
			 * @param int    $attachment_id Attachment ID.
			 * @param string $url           Attachment URL.
			 */
			do_action( 'ides_asset_swap/cache/purge', $attachment_id, $url );
		} catch ( \Throwable $e ) {
			$this->log_cache_error( 'Custom cache integration', $e );
		}

		return array_values( array_unique( array_filter( $purged ) ) );
	}

	private function attempt( string $label, callable $callback, array &$purged ): void {
		try {
			$callback();
			$purged[] = $label;
		} catch ( \Throwable $e ) {
			$this->log_cache_error( $label, $e );
		}
	}

	private function log_cache_error( string $label, \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'Media Switcheroo cache purge failed for %s: %s', $label, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
