<?php
/**
 * Plugin Name: Unused Image Cleaner
 * Description: Safely audits image attachments for database references; potential matches can be reviewed and moved to the WordPress Trash manually.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Trekrosales
 * Text Domain: unused-image-cleaner
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UIC_VERSION', '1.0.0' );
define( 'UIC_FILE', __FILE__ );
define( 'UIC_DIR', plugin_dir_path( __FILE__ ) );
require_once UIC_DIR . 'includes/classification.php';

/** Register the administration screen. */
function uic_register_admin_page() {
	add_media_page(
		__( 'Unused Image Cleaner', 'unused-image-cleaner' ),
		__( 'Image Cleaner', 'unused-image-cleaner' ),
		'manage_options',
		'uic-image-cleaner',
		'uic_render_admin_page'
	);
}
add_action( 'admin_menu', 'uic_register_admin_page' );

function uic_enqueue_admin_assets( $hook ) {
	if ( 'media_page_uic-image-cleaner' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'uic-admin', plugins_url( 'assets/admin.js', __FILE__ ), array( 'jquery' ), UIC_VERSION, true );
	wp_localize_script(
		'uic-admin',
		'uicAdmin',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'uic_scan' ),
			'i18n'    => array(
				'working' => __( 'Scanning…', 'unused-image-cleaner' ),
				'done'    => __( 'Scan complete.', 'unused-image-cleaner' ),
				'error'   => __( 'The scan encountered an error. Review the message and try again.', 'unused-image-cleaner' ),
			)
		)
	);
}
add_action( 'admin_enqueue_scripts', 'uic_enqueue_admin_assets' );

/** Return the scan result for one image. No content or database records are modified. */
function uic_check_attachment_references( $attachment_id ) {
	global $wpdb;
	$attachment_id = absint( $attachment_id );
	$file          = get_post_meta( $attachment_id, '_wp_attached_file', true );
	$metadata      = wp_get_attachment_metadata( $attachment_id );
	$attachment    = get_post( $attachment_id );

	if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment_id ) ) {
		return array( 'status' => 'uncertain', 'sources' => array( __( 'Attachment could not be verified as an image.', 'unused-image-cleaner' ) ) );
	}

	$needles = array();
	if ( $file ) {
		$needles[] = wp_basename( $file );
		$needles[] = $file;
		$needles[] = wp_get_upload_dir()['baseurl'] . '/' . ltrim( $file, '/' );
	}
	if ( ! empty( $attachment->guid ) ) {
		$needles[] = $attachment->guid;
	}
	if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) && $file ) {
		$directory = dirname( $file );
		foreach ( $metadata['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$needles[] = ( '.' === $directory ? '' : trailingslashit( $directory ) ) . $size['file'];
				$needles[] = wp_basename( $size['file'] );
			}
		}
	}
	$needles = array_values( array_unique( array_filter( $needles ) ) );
	$found  = array();

	// Exact featured-image references avoid false positives from unrelated numeric values.
	$featured = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1", (string) $attachment_id ) );
	if ( $featured ) {
		$found[] = __( 'Featured image metadata', 'unused-image-cleaner' );
	}

	// Search posts/pages and builder documents for original and generated image URLs/paths.
	foreach ( $needles as $needle ) {
		$like = '%' . $wpdb->esc_like( $needle ) . '%';
		$post_reference = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID <> %d AND (post_content LIKE %s OR post_excerpt LIKE %s) LIMIT 1", $attachment_id, $like, $like ) );
		if ( $post_reference ) {
			$found[] = __( 'Post, page, block, or page-builder content', 'unused-image-cleaner' );
			break;
		}
	}

	// Metadata includes custom fields and serialized builder data (including Elementor).
	if ( empty( $found ) || count( $found ) < 2 ) {
		foreach ( $needles as $needle ) {
			$like = '%' . $wpdb->esc_like( $needle ) . '%';
			$meta_reference = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_value LIKE %s LIMIT 1", $attachment_id, $like ) );
			if ( $meta_reference ) {
				$found[] = __( 'Post metadata or page-builder data', 'unused-image-cleaner' );
				break;
			}
		}
	}

	// Site options, widgets, and theme settings can store image paths or URLs.
	foreach ( $needles as $needle ) {
		$like = '%' . $wpdb->esc_like( $needle ) . '%';
		$option_reference = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 1", $like ) );
		if ( $option_reference ) {
			$found[] = __( 'Site option, widget, or theme setting', 'unused-image-cleaner' );
			break;
		}
	}

	$classification = uic_classify_reference_status( ! empty( $found ), file_exists( get_attached_file( $attachment_id ) ), is_array( $metadata ) && ! empty( $metadata ) );
	if ( 'used' === $classification ) {
		return array( 'status' => 'used', 'sources' => array_values( array_unique( $found ) ) );
	}

	// Absence from searchable database fields is not proof that a file is unused.
	if ( 'uncertain' === $classification ) {
		return array( 'status' => 'uncertain', 'sources' => array( __( 'Attachment file or metadata is incomplete; review manually.', 'unused-image-cleaner' ) ) );
	}
	return array( 'status' => 'potentially_unused', 'sources' => array( __( 'No database reference was found in the scanned sources; external or hardcoded references may still exist.', 'unused-image-cleaner' ) ) );
}

/** Scan a bounded batch of image attachments and accumulate results per administrator. */
function uic_ajax_scan_batch() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to scan the media library.', 'unused-image-cleaner' ) ), 403 );
	}
	check_ajax_referer( 'uic_scan', 'nonce' );
	$page  = max( 1, absint( $_POST['page'] ?? 1 ) );
	$limit = 25;
	$query = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => array( 'inherit', 'private' ),
			'post_mime_type' => 'image',
			'posts_per_page' => $limit,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => false,
		)
	);
	$key     = 'uic_scan_' . get_current_user_id();
	$results = 1 === $page ? array() : get_transient( $key );
	if ( ! is_array( $results ) ) {
		$results = array();
	}
	foreach ( $query->posts as $id ) {
		$check = uic_check_attachment_references( $id );
		$results[ $id ] = array( 'status' => $check['status'], 'sources' => $check['sources'] );
	}
	set_transient( $key, $results, DAY_IN_SECONDS );
	wp_send_json_success(
		array(
			'page'       => $page,
			'totalPages' => max( 1, (int) $query->max_num_pages ),
			'processed'  => count( $results ),
			'total'      => (int) $query->found_posts,
			'done'       => $page >= max( 1, (int) $query->max_num_pages ),
		)
	);
}
add_action( 'wp_ajax_uic_scan_batch', 'uic_ajax_scan_batch' );

/** Render the review table. */
function uic_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to access this page.', 'unused-image-cleaner' ) );
	}
	$results = get_transient( 'uic_scan_' . get_current_user_id() );
	$results = is_array( $results ) ? $results : array();
	$search  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
	$status  = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
	$page    = max( 1, absint( $_GET['paged'] ?? 1 ) );
	$per     = 20;
	$rows    = array();
	foreach ( $results as $id => $result ) {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			continue;
		}
		if ( $search && false === stripos( $post->post_title . ' ' . wp_basename( get_attached_file( $id ) ), $search ) ) {
			continue;
		}
		if ( $status && $status !== $result['status'] ) {
			continue;
		}
		$rows[] = array( 'id' => (int) $id, 'post' => $post, 'result' => $result );
	}
	$total_pages = max( 1, (int) ceil( count( $rows ) / $per ) );
	$rows = array_slice( $rows, ( $page - 1 ) * $per, $per );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Unused Image Cleaner', 'unused-image-cleaner' ); ?></h1>
		<p><?php esc_html_e( 'Run a read-only scan, review the findings, and choose explicitly which potential matches to move to Trash. A missing database reference does not prove an image is unused.', 'unused-image-cleaner' ); ?></p>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'This scanner cannot reliably detect references in external systems, hardcoded theme or plugin files, CSS, JavaScript, remote caches, or third-party services. Back up your site before deletion and verify each selection.', 'unused-image-cleaner' ); ?></p></div>
		<p><button type="button" class="button button-primary" id="uic-start-scan"><?php esc_html_e( 'Start / restart scan', 'unused-image-cleaner' ); ?></button> <span id="uic-progress" role="status" aria-live="polite"></span></p>
		<?php if ( $results ) : ?>
			<?php $counts = array_count_values( array_column( $results, 'status' ) ); ?>
			<p><strong><?php esc_html_e( 'Last scan:', 'unused-image-cleaner' ); ?></strong> <?php echo esc_html( sprintf( __( '%1$d images reviewed: %2$d used, %3$d potentially unused, %4$d uncertain.', 'unused-image-cleaner' ), count( $results ), $counts['used'] ?? 0, $counts['potentially_unused'] ?? 0, $counts['uncertain'] ?? 0 ) ); ?></p>
			<form method="get" action="">
				<input type="hidden" name="page" value="uic-image-cleaner" />
				<p class="search-box"><label class="screen-reader-text" for="uic-search"><?php esc_html_e( 'Search images', 'unused-image-cleaner' ); ?></label><input id="uic-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search filename', 'unused-image-cleaner' ); ?>" />
				<select name="status"><option value=""><?php esc_html_e( 'All statuses', 'unused-image-cleaner' ); ?></option><?php foreach ( array( 'used' => __( 'Used', 'unused-image-cleaner' ), 'potentially_unused' => __( 'Potentially unused', 'unused-image-cleaner' ), 'uncertain' => __( 'Uncertain', 'unused-image-cleaner' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
				<?php submit_button( __( 'Filter', 'unused-image-cleaner' ), 'secondary', '', false ); ?></p>
			</form>
			<form method="post">
				<?php wp_nonce_field( 'uic_confirm_delete', 'uic_nonce' ); ?>
				<input type="hidden" name="uic_action" value="confirm_delete" />
				<table class="widefat striped"><thead><tr><th class="check-column"><input type="checkbox" id="uic-select-all" aria-label="<?php esc_attr_e( 'Select eligible images on this page', 'unused-image-cleaner' ); ?>" /></th><th><?php esc_html_e( 'Thumbnail', 'unused-image-cleaner' ); ?></th><th><?php esc_html_e( 'Filename', 'unused-image-cleaner' ); ?></th><th><?php esc_html_e( 'Size', 'unused-image-cleaner' ); ?></th><th><?php esc_html_e( 'Uploaded', 'unused-image-cleaner' ); ?></th><th><?php esc_html_e( 'Status / references', 'unused-image-cleaner' ); ?></th><th><?php esc_html_e( 'Attachment URL', 'unused-image-cleaner' ); ?></th></tr></thead><tbody>
				<?php foreach ( $rows as $row ) : $id = $row['id']; $file = get_attached_file( $id ); $status_label = array( 'used' => __( 'Used', 'unused-image-cleaner' ), 'potentially_unused' => __( 'Potentially unused', 'unused-image-cleaner' ), 'uncertain' => __( 'Uncertain', 'unused-image-cleaner' ) )[ $row['result']['status'] ] ?? __( 'Uncertain', 'unused-image-cleaner' ); ?>
				<tr><th class="check-column"><input type="checkbox" name="attachment_ids[]" value="<?php echo esc_attr( $id ); ?>" <?php disabled( 'potentially_unused' !== $row['result']['status'] ); ?> aria-label="<?php echo esc_attr( sprintf( __( 'Select %s', 'unused-image-cleaner' ), get_the_title( $id ) ) ); ?>" /></th><td><?php echo wp_kses_post( wp_get_attachment_image( $id, array( 60, 60 ) ) ); ?></td><td><?php echo esc_html( wp_basename( $file ) ); ?></td><td><?php echo esc_html( size_format( $file && file_exists( $file ) ? filesize( $file ) : 0 ) ); ?></td><td><?php echo esc_html( get_the_date( '', $id ) ); ?></td><td><strong><?php echo esc_html( $status_label ); ?></strong><br /><?php echo esc_html( implode( '; ', $row['result']['sources'] ) ); ?></td><td><a href="<?php echo esc_url( wp_get_attachment_url( $id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open', 'unused-image-cleaner' ); ?></a></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $rows ) : ?><tr><td colspan="7"><?php esc_html_e( 'No images match these filters.', 'unused-image-cleaner' ); ?></td></tr><?php endif; ?>
				</tbody></table>
				<p><button class="button" type="submit" <?php disabled( empty( $rows ) ); ?>><?php esc_html_e( 'Review selected for Trash', 'unused-image-cleaner' ); ?></button></p>
			</form>
			<?php if ( $total_pages > 1 ) : ?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $total_pages ) ) ); ?></div></div><?php endif; ?>
		<?php else : ?><p><?php esc_html_e( 'No scan results yet. Start a scan to review images.', 'unused-image-cleaner' ); ?></p><?php endif; ?>
	</div>
	<?php
}

/** Confirm explicit selections, then move only validated image attachments to Trash. */
function uic_handle_admin_post() {
	if ( ! is_admin() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['uic_action'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage these files.', 'unused-image-cleaner' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'uic_confirm_delete', 'uic_nonce' );
	$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $_POST['attachment_ids'] ?? array() ) ) ) ) );
	$results = get_transient( 'uic_scan_' . get_current_user_id() );
	$results = is_array( $results ) ? $results : array();
	if ( 'confirm_delete' === sanitize_key( wp_unslash( $_POST['uic_action'] ) ) && empty( $_POST['uic_final_confirm'] ) ) {
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Confirm moving images to Trash', 'unused-image-cleaner' ); ?></h1><p><?php esc_html_e( 'Only the listed images will be moved to WordPress Trash. Their files are not intentionally permanently deleted. Review the selection carefully.', 'unused-image-cleaner' ); ?></p><ul>
		<?php foreach ( $ids as $id ) : if ( isset( $results[ $id ] ) && 'potentially_unused' === $results[ $id ]['status'] && current_user_can( 'delete_post', $id ) ) : ?><li><?php echo esc_html( wp_basename( get_attached_file( $id ) ) ); ?> — <?php echo esc_html( wp_get_attachment_url( $id ) ); ?></li><?php endif; endforeach; ?>
		</ul><form method="post"><?php wp_nonce_field( 'uic_confirm_delete', 'uic_nonce' ); ?><input type="hidden" name="uic_action" value="confirm_delete" /><input type="hidden" name="uic_final_confirm" value="1" /><?php foreach ( $ids as $id ) : ?><input type="hidden" name="attachment_ids[]" value="<?php echo esc_attr( $id ); ?>" /><?php endforeach; ?><p><button type="submit" class="button button-primary"><?php esc_html_e( 'Move listed images to Trash', 'unused-image-cleaner' ); ?></button> <a class="button" href="<?php echo esc_url( admin_url( 'upload.php?page=uic-image-cleaner' ) ); ?>"><?php esc_html_e( 'Cancel', 'unused-image-cleaner' ); ?></a></p></form></div>
		<?php
		exit;
	}
	if ( 'confirm_delete' === sanitize_key( wp_unslash( $_POST['uic_action'] ) ) && ! empty( $_POST['uic_final_confirm'] ) ) {
		$moved = 0;
		foreach ( $ids as $id ) {
			if ( empty( $results[ $id ] ) || 'potentially_unused' !== $results[ $id ]['status'] || ! current_user_can( 'delete_post', $id ) || 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			// Recheck immediately before action; if status changed to used/uncertain, refuse deletion.
			$check = uic_check_attachment_references( $id );
			if ( 'potentially_unused' !== $check['status'] ) {
				continue;
			}
			if ( wp_trash_post( $id ) ) {
				$moved++;
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'uic-image-cleaner', 'uic_trashed' => $moved ), admin_url( 'upload.php' ) ) );
		exit;
	}
}
add_action( 'admin_init', 'uic_handle_admin_post' );

function uic_admin_notice() {
	if ( isset( $_GET['page'], $_GET['uic_trashed'] ) && 'uic-image-cleaner' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( '%d image(s) moved to WordPress Trash. Check Media → Trash to restore them if needed.', 'unused-image-cleaner' ), absint( $_GET['uic_trashed'] ) ) ) );
	}
}
add_action( 'admin_notices', 'uic_admin_notice' );

function uic_activate() {
	// No schema or persistent settings are required; activation intentionally has no side effects.
}
register_activation_hook( __FILE__, 'uic_activate' );

function uic_deactivate() {
	// Per-user scan transients are temporary and expire naturally; no media is changed.
}
register_deactivation_hook( __FILE__, 'uic_deactivate' );

function uic_uninstall_cleanup() {
	$users = get_users( array( 'fields' => 'ID' ) );
	foreach ( $users as $user_id ) {
		delete_transient( 'uic_scan_' . absint( $user_id ) );
	}
}
register_uninstall_hook( __FILE__, 'uic_uninstall_cleanup' );
