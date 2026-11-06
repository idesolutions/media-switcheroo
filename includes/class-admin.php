<?php
namespace IDESolutions\MediaSwitcheroo;

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
		add_action( 'admin_post_media_switcheroo', array( $this, 'handle_replace' ) );
		add_filter( 'wp_get_attachment_url', array( $this, 'cache_bust_attachment_url' ), 20, 2 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'cache_bust_image_src' ), 20, 2 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'cache_bust_srcset' ), 20, 5 );
	}

	public function admin_menu(): void {
		add_media_page(
			__( 'Media Switcheroo', 'media-switcheroo' ),
			__( 'Media Switcheroo', 'media-switcheroo' ),
			'upload_files',
			'media-switcheroo',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		// Only the plugin's own screen uses these files; the Media Library and Attachment Details only get a link.
		if ( 'media_page_media-switcheroo' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'media-switcheroo-replace-admin', MEDIA_SWITCHEROO_URL . 'admin/css/admin.css', array(), MEDIA_SWITCHEROO_VERSION );
		wp_enqueue_script( 'media-switcheroo-replace-admin', MEDIA_SWITCHEROO_URL . 'admin/js/admin.js', array( 'jquery' ), MEDIA_SWITCHEROO_VERSION, true );
		wp_localize_script(
			'media-switcheroo-replace-admin',
			'mediaSwitcherooAdmin',
			array(
				'i18n' => array(
					'chooseTitle'  => __( 'Choose Media to Replace', 'media-switcheroo' ),
					'chooseButton' => __( 'Replace This Media', 'media-switcheroo' ),
					'replacing'    => __( 'Replacing media…', 'media-switcheroo' ),
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
				'page'          => 'media-switcheroo',
				'attachment_id' => $post->ID,
			),
			admin_url( 'upload.php' )
		);
		$actions['media_switcheroo'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Replace File', 'media-switcheroo' ) . '</a>';
		return $actions;
	}

	public function attachment_fields_to_edit( array $fields, \WP_Post $post ): array {
		if ( 'attachment' !== $post->post_type || ! $this->can_replace_attachment( $post->ID ) ) {
			return $fields;
		}
		$url = add_query_arg(
			array(
				'page'          => 'media-switcheroo',
				'attachment_id' => $post->ID,
			),
			admin_url( 'upload.php' )
		);
		$fields['media_switcheroo_file'] = array(
			'label' => __( 'Replace File', 'media-switcheroo' ),
			'input' => 'html',
			'html'  => '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Replace Media File', 'media-switcheroo' ) . '</a><p class="description">' . esc_html__( 'Keep this attachment and its existing URL while replacing the underlying file.', 'media-switcheroo' ) . '</p>',
		);
		return $fields;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to replace media.', 'media-switcheroo' ) );
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
		<div class="wrap media-switcheroo-replace-wrap">
			<div class="media-switcheroo-replace-hero">
				<div>
					<h1><?php esc_html_e( 'Media Switcheroo', 'media-switcheroo' ); ?></h1>
					<p><?php esc_html_e( 'Replace an existing Media Library file without breaking its attachment ID, URL, title, alt text, caption, or references.', 'media-switcheroo' ); ?></p>
				</div>
			</div>

			<?php if ( 'success' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e( 'Media replaced successfully.', 'media-switcheroo' ); ?></strong><?php if ( $caches ) : ?> <?php /* translators: %s: comma-separated list of caches that were cleared. */ echo esc_html( sprintf( __( 'Caches cleared: %s', 'media-switcheroo' ), $caches ) ); ?><?php endif; ?></p></div>
			<?php endif; ?>

			<?php if ( ! $attachment_id ) : ?>
				<div class="media-switcheroo-replace-card media-switcheroo-picker-card">
					<h2><?php esc_html_e( 'Choose Media', 'media-switcheroo' ); ?></h2>
					<p><?php esc_html_e( 'Select the Media Library item you want to replace. Images, PDFs, documents, and other WordPress-supported attachment types are supported.', 'media-switcheroo' ); ?></p>
					<button type="button" class="button button-primary button-hero" id="media-switcheroo-select-media"><?php esc_html_e( 'Choose from Media Library', 'media-switcheroo' ); ?></button>
				</div>
			<?php else : ?>
				<?php $this->render_replace_form( $attachment_id ); ?>
			<?php endif; ?>

			<div class="media-switcheroo-replace-card media-switcheroo-info-grid">
				<div><strong><?php esc_html_e( 'Safe Replacement', 'media-switcheroo' ); ?></strong><p><?php esc_html_e( 'The attachment ID and existing URL stay the same. The replacement must use the same file extension so existing links continue working.', 'media-switcheroo' ); ?></p></div>
				<div><strong><?php esc_html_e( 'Automatic Cache Clearing', 'media-switcheroo' ); ?></strong><p><?php esc_html_e( 'After replacement, WordPress caches are cleared and the plugin attempts to purge supported page-cache, hosting, optimization, and builder caches automatically.', 'media-switcheroo' ); ?></p></div>
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
		<div class="media-switcheroo-replace-grid">
			<div class="media-switcheroo-replace-card media-switcheroo-current-card">
				<h2><?php esc_html_e( 'Current Media', 'media-switcheroo' ); ?></h2>
				<div class="media-switcheroo-media-preview">
					<?php if ( wp_attachment_is_image( $attachment_id ) ) : ?>
						<?php echo wp_get_attachment_image( $attachment_id, 'medium', false, array( 'class' => 'media-switcheroo-preview-image' ) ); ?>
					<?php else : ?>
						<span class="dashicons dashicons-media-document"></span>
					<?php endif; ?>
				</div>
				<dl class="media-switcheroo-file-meta">
					<div><dt><?php esc_html_e( 'Title', 'media-switcheroo' ); ?></dt><dd><?php echo esc_html( get_the_title( $attachment_id ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'File', 'media-switcheroo' ); ?></dt><dd><?php echo esc_html( wp_basename( (string) $file ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Type', 'media-switcheroo' ); ?></dt><dd><?php echo esc_html( get_post_mime_type( $attachment_id ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Required extension', 'media-switcheroo' ); ?></dt><dd><?php echo esc_html( $ext ); ?></dd></div>
				</dl>
			</div>

			<div class="media-switcheroo-replace-card">
				<h2><?php esc_html_e( 'Replacement File', 'media-switcheroo' ); ?></h2>
				<p><?php esc_html_e( 'Choose the new file. The plugin will replace the file in place, regenerate WordPress metadata and thumbnails, and clear caches.', 'media-switcheroo' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="media-switcheroo-replace-form">
					<input type="hidden" name="action" value="media_switcheroo">
					<input type="hidden" name="attachment_id" value="<?php echo esc_attr( (string) $attachment_id ); ?>">
					<?php wp_nonce_field( 'media_switcheroo_' . $attachment_id, 'media_switcheroo_nonce' ); ?>
					<label class="media-switcheroo-dropzone" for="media-switcheroo-replacement-file">
						<span class="dashicons dashicons-upload"></span>
						<strong><?php esc_html_e( 'Choose a replacement file', 'media-switcheroo' ); ?></strong>
						<span id="media-switcheroo-file-name"><?php /* translators: %s: required file extension, for example jpg. */ echo esc_html( sprintf( __( 'Must be a .%s file', 'media-switcheroo' ), strtolower( $ext ) ) ); ?></span>
						<input type="file" id="media-switcheroo-replacement-file" name="replacement_file" required>
					</label>
					<div class="media-switcheroo-safety-note"><span class="dashicons dashicons-yes-alt"></span><div><strong><?php esc_html_e( 'Your media details stay intact.', 'media-switcheroo' ); ?></strong><p><?php esc_html_e( 'Attachment ID, URL, title, alt text, caption, description, and existing references are preserved.', 'media-switcheroo' ); ?></p></div></div>
					<button type="submit" class="button button-primary button-hero" id="media-switcheroo-submit-replace"><?php esc_html_e( 'Replace Media', 'media-switcheroo' ); ?></button>
					<a class="button button-hero" href="<?php echo esc_url( admin_url( 'upload.php?mode=list' ) ); ?>"><?php esc_html_e( 'Cancel', 'media-switcheroo' ); ?></a>
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
						__( 'The upload request was too large for this server. The maximum request size is %s. Choose a smaller file or ask the host to increase post_max_size and upload_max_filesize.', 'media-switcheroo' ),
						size_format( wp_max_upload_size() )
					)
				),
				esc_html__( 'Media Replacement Failed', 'media-switcheroo' ),
				array( 'back_link' => true )
			);
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_die( esc_html__( 'The upload request was invalid. Return to the Media Library and try again.', 'media-switcheroo' ), esc_html__( 'Media Replacement Failed', 'media-switcheroo' ), array( 'back_link' => true ) );
		}
		if ( ! $this->can_replace_attachment( $attachment_id ) ) {
			wp_die( esc_html__( 'You do not have permission to replace this media item.', 'media-switcheroo' ) );
		}
		if ( ! isset( $_POST['media_switcheroo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['media_switcheroo_nonce'] ) ), 'media_switcheroo_' . $attachment_id ) ) {
			wp_die( esc_html__( 'Your upload session expired. Refresh the Media Switcheroo page and try again.', 'media-switcheroo' ), esc_html__( 'Media Replacement Failed', 'media-switcheroo' ), array( 'back_link' => true ) );
		}

		$upload = isset( $_FILES['replacement_file'] ) && is_array( $_FILES['replacement_file'] ) ? $_FILES['replacement_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by Replacer using is_uploaded_file() and wp_check_filetype_and_ext().
		$result = $this->replacer->replace( $attachment_id, $upload );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Media Replacement Failed', 'media-switcheroo' ), array( 'back_link' => true ) );
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
				'page'          => 'media-switcheroo',
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
		return 'media_switcheroo_result_' . get_current_user_id();
	}

	public function cache_bust_attachment_url( string $url, int $attachment_id ): string {
		if ( ! $this->should_add_version() ) {
			return $url;
		}
		$version = get_post_meta( $attachment_id, '_media_switcheroo_version', true );
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
		$version = get_post_meta( $attachment_id, '_media_switcheroo_version', true );
		if ( $version ) {
			$image[0] = add_query_arg( 'asv', (string) $version, $image[0] );
		}
		return $image;
	}

	public function cache_bust_srcset( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array {
		if ( ! $this->should_add_version() ) {
			return $sources;
		}
		$version = get_post_meta( $attachment_id, '_media_switcheroo_version', true );
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
