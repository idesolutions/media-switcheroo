<?php
namespace IDESolutions\AssetSwap;

defined( 'ABSPATH' ) || exit;

final class Admin {
	private Replacer $replacer;

	public function __construct( Replacer $replacer ) {
		$this->replacer = $replacer;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'media_row_actions', array( $this, 'media_row_actions' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields_to_edit' ), 20, 2 );
		add_action( 'admin_post_ides_asset_swap', array( $this, 'handle_replace' ) );
		add_filter( 'wp_get_attachment_url', array( $this, 'cache_bust_attachment_url' ), 20, 2 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'cache_bust_image_src' ), 20, 2 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'cache_bust_srcset' ), 20, 5 );
	}

	public function admin_menu(): void {
		add_media_page(
			__( 'Asset Swap', 'asset-swap' ),
			__( 'Asset Swap', 'asset-swap' ),
			'upload_files',
			'asset-swap',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		// Only the Asset Swap screen uses these files; the Media Library and Attachment Details only get a link.
		if ( 'media_page_asset-swap' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'asset-swap-replace-admin', IDES_ASSET_SWAP_URL . 'admin/css/admin.css', array(), IDES_ASSET_SWAP_VERSION );
		wp_enqueue_script( 'asset-swap-replace-admin', IDES_ASSET_SWAP_URL . 'admin/js/admin.js', array( 'jquery' ), IDES_ASSET_SWAP_VERSION, true );
		wp_localize_script(
			'asset-swap-replace-admin',
			'assetSwapAdmin',
			array(
				'i18n' => array(
					'chooseTitle'  => __( 'Choose Media to Replace', 'asset-swap' ),
					'chooseButton' => __( 'Replace This Media', 'asset-swap' ),
					'replacing'    => __( 'Replacing media…', 'asset-swap' ),
				),
			)
		);
		wp_enqueue_media();
	}

	public function media_row_actions( array $actions, \WP_Post $post ): array {
		if ( 'attachment' !== $post->post_type || ! $this->can_replace_attachment( $post->ID ) ) {
			return $actions;
		}
		$url = add_query_arg(
			array(
				'page'          => 'asset-swap',
				'attachment_id' => $post->ID,
			),
			admin_url( 'upload.php' )
		);
		$actions['ides_asset_swap'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Replace File', 'asset-swap' ) . '</a>';
		return $actions;
	}

	public function attachment_fields_to_edit( array $fields, \WP_Post $post ): array {
		if ( 'attachment' !== $post->post_type || ! $this->can_replace_attachment( $post->ID ) ) {
			return $fields;
		}
		$url = add_query_arg(
			array(
				'page'          => 'asset-swap',
				'attachment_id' => $post->ID,
			),
			admin_url( 'upload.php' )
		);
		$fields['ides_asset_swap_file'] = array(
			'label' => __( 'Replace File', 'asset-swap' ),
			'input' => 'html',
			'html'  => '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Replace Media File', 'asset-swap' ) . '</a><p class="description">' . esc_html__( 'Keep this attachment and its existing URL while replacing the underlying file.', 'asset-swap' ) . '</p>',
		);
		return $fields;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to replace media.', 'asset-swap' ) );
		}

		// Choosing which item to show is read-only and permission-checked below, so no nonce is needed.
		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( wp_unslash( $_GET['attachment_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $attachment_id && ( 'attachment' !== get_post_type( $attachment_id ) || ! $this->can_replace_attachment( $attachment_id ) ) ) {
			$attachment_id = 0;
		}

		// The result of a replacement is kept on the server for this user. Showing text taken from the address would
		// let anyone craft a link that displays a fake "Media replaced successfully" message.
		$result = get_transient( $this->result_key() );
		if ( false !== $result ) {
			delete_transient( $this->result_key() );
		}
		$notice = is_array( $result ) ? 'success' : '';
		$caches = is_array( $result ) && ! empty( $result['caches'] ) ? implode( ', ', array_map( 'sanitize_text_field', (array) $result['caches'] ) ) : '';
		?>
		<div class="wrap asset-swap-replace-wrap">
			<div class="asset-swap-replace-hero">
				<div>
					<h1><?php esc_html_e( 'Asset Swap', 'asset-swap' ); ?></h1>
					<p><?php esc_html_e( 'Replace an existing Media Library file without breaking its attachment ID, URL, title, alt text, caption, or references.', 'asset-swap' ); ?></p>
				</div>
			</div>

			<?php if ( 'success' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e( 'Media replaced successfully.', 'asset-swap' ); ?></strong><?php if ( $caches ) : ?> <?php /* translators: %s: comma-separated list of caches that were cleared. */ echo esc_html( sprintf( __( 'Caches cleared: %s', 'asset-swap' ), $caches ) ); ?><?php endif; ?></p></div>
			<?php endif; ?>

			<?php if ( ! $attachment_id ) : ?>
				<div class="asset-swap-replace-card asset-swap-picker-card">
					<h2><?php esc_html_e( 'Choose Media', 'asset-swap' ); ?></h2>
					<p><?php esc_html_e( 'Select the Media Library item you want to replace. Images, PDFs, documents, and other WordPress-supported attachment types are supported.', 'asset-swap' ); ?></p>
					<button type="button" class="button button-primary button-hero" id="asset-swap-select-media"><?php esc_html_e( 'Choose from Media Library', 'asset-swap' ); ?></button>
				</div>
			<?php else : ?>
				<?php $this->render_replace_form( $attachment_id ); ?>
			<?php endif; ?>

			<div class="asset-swap-replace-card asset-swap-info-grid">
				<div><strong><?php esc_html_e( 'Safe Replacement', 'asset-swap' ); ?></strong><p><?php esc_html_e( 'The attachment ID and existing URL stay the same. The replacement must use the same file extension so existing links continue working.', 'asset-swap' ); ?></p></div>
				<div><strong><?php esc_html_e( 'Automatic Cache Clearing', 'asset-swap' ); ?></strong><p><?php esc_html_e( 'After replacement, WordPress caches are cleared and Asset Swap attempts to purge supported page-cache, hosting, optimization, and builder caches automatically.', 'asset-swap' ); ?></p></div>
			</div>
		</div>
		<?php
	}

	private function render_replace_form( int $attachment_id ): void {
		$post = get_post( $attachment_id );
		$file = get_attached_file( $attachment_id, true );
		$url  = wp_get_attachment_url( $attachment_id );
		$ext  = strtoupper( pathinfo( (string) $file, PATHINFO_EXTENSION ) );
		?>
		<div class="asset-swap-replace-grid">
			<div class="asset-swap-replace-card asset-swap-current-card">
				<h2><?php esc_html_e( 'Current Media', 'asset-swap' ); ?></h2>
				<div class="asset-swap-media-preview">
					<?php if ( wp_attachment_is_image( $attachment_id ) ) : ?>
						<?php echo wp_get_attachment_image( $attachment_id, 'medium', false, array( 'class' => 'asset-swap-preview-image' ) ); ?>
					<?php else : ?>
						<span class="dashicons dashicons-media-document"></span>
					<?php endif; ?>
				</div>
				<dl class="asset-swap-file-meta">
					<div><dt><?php esc_html_e( 'Title', 'asset-swap' ); ?></dt><dd><?php echo esc_html( get_the_title( $attachment_id ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'File', 'asset-swap' ); ?></dt><dd><?php echo esc_html( wp_basename( (string) $file ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Type', 'asset-swap' ); ?></dt><dd><?php echo esc_html( get_post_mime_type( $attachment_id ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Required extension', 'asset-swap' ); ?></dt><dd><?php echo esc_html( $ext ); ?></dd></div>
				</dl>
			</div>

			<div class="asset-swap-replace-card">
				<h2><?php esc_html_e( 'Replacement File', 'asset-swap' ); ?></h2>
				<p><?php esc_html_e( 'Choose the new file. Asset Swap will replace the file in place, regenerate WordPress metadata and thumbnails, and clear caches.', 'asset-swap' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="asset-swap-replace-form">
					<input type="hidden" name="action" value="ides_asset_swap">
					<input type="hidden" name="attachment_id" value="<?php echo esc_attr( (string) $attachment_id ); ?>">
					<?php wp_nonce_field( 'ides_asset_swap_' . $attachment_id, 'ides_asset_swap_nonce' ); ?>
					<label class="asset-swap-dropzone" for="asset-swap-replacement-file">
						<span class="dashicons dashicons-upload"></span>
						<strong><?php esc_html_e( 'Choose a replacement file', 'asset-swap' ); ?></strong>
						<span id="asset-swap-file-name"><?php /* translators: %s: required file extension, for example jpg. */ echo esc_html( sprintf( __( 'Must be a .%s file', 'asset-swap' ), strtolower( $ext ) ) ); ?></span>
						<input type="file" id="asset-swap-replacement-file" name="replacement_file" required>
					</label>
					<div class="asset-swap-safety-note"><span class="dashicons dashicons-yes-alt"></span><div><strong><?php esc_html_e( 'Your media details stay intact.', 'asset-swap' ); ?></strong><p><?php esc_html_e( 'Attachment ID, URL, title, alt text, caption, description, and existing references are preserved.', 'asset-swap' ); ?></p></div></div>
					<button type="submit" class="button button-primary button-hero" id="asset-swap-submit-replace"><?php esc_html_e( 'Replace Media', 'asset-swap' ); ?></button>
					<a class="button button-hero" href="<?php echo esc_url( admin_url( 'upload.php?mode=list' ) ); ?>"><?php esc_html_e( 'Cancel', 'asset-swap' ); ?></a>
				</form>
			</div>
		</div>
		<?php
	}

	public function handle_replace(): void {
		// PHP empties both $_POST and $_FILES when the request exceeds post_max_size.
		// Detect that case before interpreting a missing attachment ID as an
		// authorization failure.
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : 0;
		if ( $content_length > 0 && empty( $_POST ) && empty( $_FILES ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: Maximum request size configured by PHP. */
						__( 'The upload request was too large for this server. The maximum request size is %s. Choose a smaller file or ask the host to increase post_max_size and upload_max_filesize.', 'asset-swap' ),
						size_format( wp_max_upload_size() )
					)
				),
				esc_html__( 'Media Replacement Failed', 'asset-swap' ),
				array( 'back_link' => true )
			);
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_die( esc_html__( 'The upload request was invalid. Return to the Media Library and try again.', 'asset-swap' ), esc_html__( 'Media Replacement Failed', 'asset-swap' ), array( 'back_link' => true ) );
		}
		if ( ! $this->can_replace_attachment( $attachment_id ) ) {
			wp_die( esc_html__( 'You do not have permission to replace this media item.', 'asset-swap' ) );
		}
		if ( ! isset( $_POST['ides_asset_swap_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ides_asset_swap_nonce'] ) ), 'ides_asset_swap_' . $attachment_id ) ) {
			wp_die( esc_html__( 'Your upload session expired. Refresh the Asset Swap page and try again.', 'asset-swap' ), esc_html__( 'Media Replacement Failed', 'asset-swap' ), array( 'back_link' => true ) );
		}

		$upload = isset( $_FILES['replacement_file'] ) && is_array( $_FILES['replacement_file'] ) ? $_FILES['replacement_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by Replacer using is_uploaded_file() and wp_check_filetype_and_ext().
		$result = $this->replacer->replace( $attachment_id, $upload );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Media Replacement Failed', 'asset-swap' ), array( 'back_link' => true ) );
		}

		set_transient(
			$this->result_key(),
			array(
				'attachment_id' => $attachment_id,
				'caches'        => $result['caches'] ?? array(),
			),
			5 * MINUTE_IN_SECONDS
		);

		$redirect = add_query_arg(
			array(
				'page'          => 'asset-swap',
				'attachment_id' => $attachment_id,
			),
			admin_url( 'upload.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Determine whether the current user may replace an attachment.
	 *
	 * Some managed WordPress platforms map attachment edit capabilities through
	 * the parent post. Administrators who can upload and manage the site should
	 * not be blocked solely by that attachment-specific mapping.
	 */
	private function can_replace_attachment( int $attachment_id ): bool {
		if ( ! current_user_can( 'upload_files' ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $attachment_id ) || current_user_can( 'manage_options' );
	}

	/**
	 * Where the replacement version is added to media URLs.
	 *
	 * Only on the public site. The admin and the REST API (which the block editor uses to insert media) must see the
	 * clean URL, otherwise the version number would be saved into post content and could never be refreshed again.
	 */
	private function should_add_version(): bool {
		return ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	private function result_key(): string {
		return 'ides_asset_swap_result_' . get_current_user_id();
	}

	public function cache_bust_attachment_url( string $url, int $attachment_id ): string {
		if ( ! $this->should_add_version() ) {
			return $url;
		}
		$version = get_post_meta( $attachment_id, '_ides_asset_swap_version', true );
		if ( ! $version ) {
			return $url;
		}
		return add_query_arg( 'asv', (string) $version, $url );
	}

	/**
	 * @param array|false $image         Array of image data, or false.
	 * @param int         $attachment_id Attachment ID.
	 * @return array|false
	 */
	public function cache_bust_image_src( $image, int $attachment_id ) {
		if ( ! is_array( $image ) || empty( $image[0] ) || ! $this->should_add_version() ) {
			return $image;
		}
		$version = get_post_meta( $attachment_id, '_ides_asset_swap_version', true );
		if ( $version ) {
			$image[0] = add_query_arg( 'asv', (string) $version, $image[0] );
		}
		return $image;
	}

	public function cache_bust_srcset( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array {
		if ( ! $this->should_add_version() ) {
			return $sources;
		}
		$version = get_post_meta( $attachment_id, '_ides_asset_swap_version', true );
		if ( ! $version ) {
			return $sources;
		}
		foreach ( $sources as &$source ) {
			if ( ! empty( $source['url'] ) ) {
				$source['url'] = add_query_arg( 'asv', (string) $version, $source['url'] );
			}
		}
		unset( $source );
		return $sources;
	}
}
