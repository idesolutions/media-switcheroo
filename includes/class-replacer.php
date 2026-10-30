<?php
namespace IDESolutions\AssetSwap;

defined( 'ABSPATH' ) || exit;

// The staged, rollback-safe replacement of a file already in the uploads directory needs an atomic rename(), chmod()
// to keep its permissions, and delete results it can act on. WP_Filesystem cannot provide these, and it may ask for
// FTP credentials. Every path is first confined to the uploads directory (see is_safe_upload_path()).
// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

final class Replacer {
	private Cache_Manager $cache_manager;

	public function __construct( Cache_Manager $cache_manager ) {
		$this->cache_manager = $cache_manager;
	}

	/**
	 * Replace an attachment's underlying file while preserving its ID and URL.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $upload        One $_FILES-style upload array.
	 * @return array|\WP_Error
	 */
	public function replace( int $attachment_id, array $upload ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new \WP_Error( 'asset_swap_invalid_attachment', __( 'The selected item is not a Media Library attachment.', 'asset-swap' ) );
		}

		$target        = get_attached_file( $attachment_id, true );
		$attached_path = is_string( $target ) ? $target : '';
		if ( ! is_string( $target ) || '' === $target || ! is_file( $target ) ) {
			return new \WP_Error( 'asset_swap_missing_file', __( 'The existing media file could not be found on the server.', 'asset-swap' ) );
		}

		// Normalised for the checks and file operations below only. WordPress itself must be given the path exactly as it
		// returned it: on Windows servers a normalised path no longer matches the uploads directory, and WordPress would
		// then store an absolute path instead of the usual relative one and lose track of the file.
		$target = wp_normalize_path( $target );
		if ( is_link( $target ) ) {
			return new \WP_Error( 'asset_swap_symlink_target', __( 'Asset Swap will not replace a symlinked media file.', 'asset-swap' ) );
		}

		if ( ! $this->is_safe_upload_path( $target ) ) {
			return new \WP_Error( 'asset_swap_unsafe_path', __( 'The attachment file is outside the WordPress uploads directory and cannot be replaced safely.', 'asset-swap' ) );
		}

		if ( ! is_writable( $target ) || ! is_writable( dirname( $target ) ) ) {
			return new \WP_Error( 'asset_swap_not_writable', __( 'WordPress does not have permission to replace this media file.', 'asset-swap' ) );
		}

		$upload_error = isset( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
		$tmp_name     = isset( $upload['tmp_name'] ) && is_string( $upload['tmp_name'] ) ? $upload['tmp_name'] : '';
		$upload_name  = isset( $upload['name'] ) && is_string( $upload['name'] ) ? sanitize_file_name( wp_unslash( $upload['name'] ) ) : '';

		if ( UPLOAD_ERR_OK !== $upload_error ) {
			return new \WP_Error( 'asset_swap_upload_error', $this->upload_error_message( $upload_error ) );
		}

		if ( '' === $tmp_name || '' === $upload_name || ! is_uploaded_file( $tmp_name ) ) {
			return new \WP_Error( 'asset_swap_invalid_upload', __( 'No valid replacement file was uploaded.', 'asset-swap' ) );
		}

		$actual_size = @filesize( $tmp_name );
		if ( false === $actual_size || $actual_size < 1 ) {
			return new \WP_Error( 'asset_swap_empty_upload', __( 'The replacement file is empty or could not be read.', 'asset-swap' ) );
		}
		if ( $actual_size > wp_max_upload_size() ) {
			return new \WP_Error( 'asset_swap_file_too_large', __( 'The replacement file exceeds the maximum upload size allowed by this site.', 'asset-swap' ) );
		}

		$allowed_mimes = get_allowed_mime_types();
		$checked       = wp_check_filetype_and_ext( $tmp_name, $upload_name, $allowed_mimes );
		$new_ext       = strtolower( (string) ( $checked['ext'] ?? '' ) );
		$new_type      = sanitize_mime_type( (string) ( $checked['type'] ?? '' ) );
		$old_ext       = strtolower( pathinfo( $target, PATHINFO_EXTENSION ) );
		$old_type      = sanitize_mime_type( (string) get_post_mime_type( $attachment_id ) );

		if ( ! $new_ext || ! $new_type ) {
			return new \WP_Error( 'asset_swap_disallowed_type', __( 'WordPress does not allow this replacement file type.', 'asset-swap' ) );
		}

		if ( $new_ext !== $old_ext ) {
			return new \WP_Error(
				'asset_swap_extension_mismatch',
				sprintf(
					/* translators: 1: existing extension, 2: replacement extension. */
					__( 'To preserve the existing media URL, the replacement must use the same file extension. Existing: %1$s. Replacement: %2$s.', 'asset-swap' ),
					strtoupper( $old_ext ),
					strtoupper( $new_ext )
				)
			);
		}

		if ( $old_type && $this->mime_family( $old_type ) !== $this->mime_family( $new_type ) ) {
			return new \WP_Error( 'asset_swap_mime_mismatch', __( 'The replacement file type does not match the existing attachment type.', 'asset-swap' ) );
		}

		if ( 'image/svg+xml' === $new_type ) {
			if ( ! $this->valid_svg( $tmp_name ) ) {
				return new \WP_Error( 'asset_swap_invalid_svg', __( 'The uploaded SVG could not be validated as a safe SVG document.', 'asset-swap' ) );
			}
		} elseif ( str_starts_with( $new_type, 'image/' ) && ! $this->valid_image( $tmp_name ) ) {
			return new \WP_Error( 'asset_swap_invalid_image', __( 'The uploaded image could not be validated.', 'asset-swap' ) );
		}

		$old_metadata  = wp_get_attachment_metadata( $attachment_id );
		$old_metadata  = is_array( $old_metadata ) ? $old_metadata : array();
		$old_permissions = @fileperms( $target );
		$backup        = wp_tempnam( wp_basename( $target ) . '-asset-swap-backup' );

		if ( ! $backup || ! @copy( $target, $backup ) ) {
			if ( $backup && is_file( $backup ) ) {
				wp_delete_file( $backup );
			}
			return new \WP_Error( 'asset_swap_backup_failed', __( 'Asset Swap could not create a temporary backup of the existing file.', 'asset-swap' ) );
		}
		@chmod( $backup, 0600 );

		$stage = @tempnam( dirname( $target ), '.asset-swap-' );
		if ( ! $stage ) {
			wp_delete_file( $backup );
			return new \WP_Error( 'asset_swap_stage_failed', __( 'Asset Swap could not prepare a secure temporary replacement file.', 'asset-swap' ) );
		}
		$stage = wp_normalize_path( $stage );

		if ( ! @copy( $tmp_name, $stage ) ) {
			wp_delete_file( $stage );
			wp_delete_file( $backup );
			return new \WP_Error( 'asset_swap_copy_failed', __( 'The replacement file could not be staged for installation.', 'asset-swap' ) );
		}

		if ( $old_permissions ) {
			@chmod( $stage, $old_permissions & 0777 );
		} else {
			@chmod( $stage, 0644 );
		}

		if ( ! $this->install_staged_file( $stage, $target, $backup ) ) {
			wp_delete_file( $stage );
			wp_delete_file( $backup );
			return new \WP_Error( 'asset_swap_install_failed', __( 'The replacement file could not be installed. The original file was restored.', 'asset-swap' ) );
		}

		$post_update = wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => $new_type,
			),
			true
		);

		if ( is_wp_error( $post_update ) ) {
			$this->restore_backup( $backup, $target );
			return $post_update;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$disable_big_images = static function () {
			return false;
		};
		add_filter( 'big_image_size_threshold', $disable_big_images, 999 );
		$new_metadata = wp_generate_attachment_metadata( $attachment_id, $attached_path );
		remove_filter( 'big_image_size_threshold', $disable_big_images, 999 );

		if ( ! is_array( $new_metadata ) ) {
			$new_metadata = array();
		}
		if ( 'image/svg+xml' === $new_type && empty( $new_metadata ) ) {
			// WordPress core does not generate raster thumbnails for SVG files.
			// Preserve metadata supplied by the original SVG upload or its SVG plugin.
			$new_metadata = $old_metadata;
		}
		unset( $new_metadata['original_image'] );

		if ( 'image/svg+xml' !== $new_type && str_starts_with( $new_type, 'image/' ) && empty( $new_metadata ) ) {
			$this->rollback( $attachment_id, $backup, $target, $old_type, $old_metadata );
			return new \WP_Error( 'asset_swap_metadata_failed', __( 'WordPress could not regenerate image metadata. The original file was restored.', 'asset-swap' ) );
		}

		$metadata_updated = wp_update_attachment_metadata( $attachment_id, $new_metadata );
		if ( false === $metadata_updated && wp_get_attachment_metadata( $attachment_id ) !== $new_metadata ) {
			$this->rollback( $attachment_id, $backup, $target, $old_type, $old_metadata );
			return new \WP_Error( 'asset_swap_metadata_failed', __( 'WordPress could not save the replacement media metadata. The original file was restored.', 'asset-swap' ) );
		}

		$this->delete_stale_derivatives( $target, $old_metadata, $new_metadata );

		update_attached_file( $attachment_id, $attached_path );
		update_post_meta( $attachment_id, '_ides_asset_swap_version', (string) time() );
		update_post_meta( $attachment_id, '_ides_asset_swap_date', current_time( 'mysql' ) );
		update_post_meta( $attachment_id, '_ides_asset_swap_user', get_current_user_id() );

		wp_delete_file( $backup );

		$url    = wp_get_attachment_url( $attachment_id );
		$purged = $this->cache_manager->purge( $attachment_id, (string) $url );

		do_action( 'ides_asset_swap/replaced', $attachment_id, $url, $new_metadata );

		return array(
			'attachment_id' => $attachment_id,
			'url'           => (string) $url,
			'mime_type'     => $new_type,
			'caches'        => $purged,
		);
	}

	private function valid_image( string $path ): bool {
		$image = @getimagesize( $path );
		return is_array( $image ) && ! empty( $image[0] ) && ! empty( $image[1] );
	}

	/**
	 * Validate an SVG without treating it as a raster image.
	 *
	 * PHP's getimagesize() does not support SVG. Parse the XML without network access or entity expansion, then
	 * reject anything that can run code or pull in something from elsewhere: scripts, embedded HTML, event handlers,
	 * external references (links, images, <use>, stylesheets) and style rules that load remote resources. An SVG may
	 * only reference itself (#id) or embed a raster image as a data: URI.
	 */
	private function valid_svg( string $path ): bool {
		if ( ! class_exists( '\\DOMDocument' ) || ! is_readable( $path ) ) {
			return false;
		}

		$contents = file_get_contents( $path );
		if ( false === $contents || '' === trim( $contents ) || false !== strpos( $contents, "\0" ) ) {
			return false;
		}

		if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $contents ) ) {
			return false;
		}

		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadXML( $contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || ! $document->documentElement || 'svg' !== strtolower( $document->documentElement->localName ) ) {
			return false;
		}

		// Processing instructions (for example an xml-stylesheet) can pull in remote styles.
		$xpath = new \DOMXPath( $document );
		if ( $xpath->query( '//processing-instruction()' )->length > 0 ) {
			return false;
		}

		$blocked_elements = array( 'script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'canvas', 'handler', 'listener' );
		foreach ( $document->getElementsByTagName( '*' ) as $element ) {
			$tag = strtolower( $element->localName );

			if ( in_array( $tag, $blocked_elements, true ) ) {
				return false;
			}

			if ( 'style' === $tag && ! $this->css_is_safe( $element->textContent ) ) {
				return false;
			}

			// Animations can rewrite a link target after the document has loaded.
			if ( in_array( $tag, array( 'animate', 'set' ), true ) && preg_match( '/href/i', (string) $element->getAttribute( 'attributeName' ) ) ) {
				return false;
			}

			if ( ! $element->hasAttributes() ) {
				continue;
			}

			foreach ( $element->attributes as $attribute ) {
				$name  = strtolower( $attribute->localName );
				$value = trim( $attribute->value );

				if ( str_starts_with( $name, 'on' ) || preg_match( '/(?:java|vb)script\s*:/i', $value ) ) {
					return false;
				}

				if ( 'href' === $name && ! $this->svg_reference_is_safe( $value ) ) {
					return false;
				}

				if ( false !== stripos( $value, 'url(' ) || 'style' === $name ) {
					if ( ! $this->css_is_safe( $value ) ) {
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * An SVG reference is safe when it points inside the document, or embeds a raster image as a data URI.
	 */
	private function svg_reference_is_safe( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value || '#' === $value[0] ) {
			return true;
		}
		return (bool) preg_match( '#^data:image/(?:png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$#i', $value );
	}

	/**
	 * CSS inside an SVG may use gradients and patterns by id, and embedded raster data, but must not import
	 * stylesheets or load anything from another address.
	 */
	private function css_is_safe( string $css ): bool {
		if ( preg_match( '/@import|expression\s*\(|behavior\s*:|-moz-binding|(?:java|vb)script\s*:/i', $css ) ) {
			return false;
		}

		if ( preg_match_all( '/url\(\s*([\'"]?)(.*?)\1\s*\)/is', $css, $matches ) ) {
			foreach ( $matches[2] as $reference ) {
				if ( ! $this->svg_reference_is_safe( $reference ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private function upload_error_message( int $error ): string {
		switch ( $error ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The replacement file exceeds the upload size allowed by this server.', 'asset-swap' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The replacement file was only partially uploaded. Please try again.', 'asset-swap' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'Choose a replacement file before submitting.', 'asset-swap' );
			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'The server is missing its temporary upload directory. Contact the hosting provider.', 'asset-swap' );
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not write the uploaded file to disk. Contact the hosting provider.', 'asset-swap' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'A server extension stopped the upload. Contact the hosting provider.', 'asset-swap' );
			default:
				return __( 'The replacement file could not be uploaded. Please try again.', 'asset-swap' );
		}
	}

	private function mime_family( string $mime ): string {
		$parts = explode( '/', strtolower( $mime ), 2 );
		return $parts[0] ?? '';
	}

	private function is_safe_upload_path( string $target ): bool {
		$uploads = wp_get_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? realpath( (string) $uploads['basedir'] ) : false;
		$real    = realpath( $target );

		if ( false === $basedir || false === $real ) {
			return false;
		}

		$basedir = trailingslashit( wp_normalize_path( $basedir ) );
		$real    = wp_normalize_path( $real );

		return str_starts_with( $real, $basedir );
	}

	private function install_staged_file( string $stage, string $target, string $backup ): bool {
		if ( @rename( $stage, $target ) ) {
			return true;
		}

		// Windows may not allow rename() over an existing destination. Remove the
		// destination only after the backup exists, then retry the atomic move.
		if ( is_file( $target ) && ! @unlink( $target ) ) {
			return false;
		}
		if ( @rename( $stage, $target ) ) {
			return true;
		}

		$this->restore_backup( $backup, $target );
		return false;
	}

	private function restore_backup( string $backup, string $target ): void {
		if ( is_file( $backup ) ) {
			@copy( $backup, $target );
			wp_delete_file( $backup );
		}
	}

	private function rollback( int $attachment_id, string $backup, string $target, string $old_type, array $old_metadata ): void {
		$this->restore_backup( $backup, $target );
		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => $old_type,
			)
		);
		wp_update_attachment_metadata( $attachment_id, $old_metadata );
	}

	private function delete_stale_derivatives( string $target, array $old_metadata, array $new_metadata ): void {
		$dir      = trailingslashit( dirname( $target ) );
		$basename = basename( $target );
		$keep     = array( $basename => true );

		if ( ! empty( $new_metadata['sizes'] ) && is_array( $new_metadata['sizes'] ) ) {
			foreach ( $new_metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$keep[ wp_basename( (string) $size['file'] ) ] = true;
				}
			}
		}

		if ( ! empty( $new_metadata['original_image'] ) ) {
			$keep[ wp_basename( (string) $new_metadata['original_image'] ) ] = true;
		}

		$old_files = array();
		if ( ! empty( $old_metadata['sizes'] ) && is_array( $old_metadata['sizes'] ) ) {
			foreach ( $old_metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$old_files[] = wp_basename( (string) $size['file'] );
				}
			}
		}
		if ( ! empty( $old_metadata['original_image'] ) ) {
			$old_files[] = wp_basename( (string) $old_metadata['original_image'] );
		}

		foreach ( array_unique( $old_files ) as $name ) {
			if ( ! $name || isset( $keep[ $name ] ) ) {
				continue;
			}
			$file = wp_normalize_path( $dir . $name );
			if ( $this->is_safe_upload_path( $file ) && is_file( $file ) && ! is_link( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}

// phpcs:enable
