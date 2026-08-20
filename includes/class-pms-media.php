<?php
defined( 'ABSPATH' ) || exit;

/**
 * Media ingestion shared by the API image sync and the REA XML importer.
 *
 * Every imported attachment carries a _pms_src meta (hash of source + mod
 * time), so re-running an import reuses existing attachments instead of
 * duplicating them; a changed mod time imports the new version.
 */
class PMS_Media {

	/**
	 * Import one image/file into the media library, attached to $post_id.
	 *
	 * @param int    $post_id Parent post.
	 * @param string $src     http(s) URL or absolute local file path.
	 * @param string $version Source version marker (modTime etc.) for dedupe.
	 * @return int Attachment ID, or 0 on failure.
	 */
	public static function import( $post_id, $src, $version = '' ) {
		$key      = md5( $src . '|' . $version );
		$existing = self::find_by_key( $key );
		if ( $existing ) {
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( preg_match( '#^https?://#i', $src ) ) {
			$tmp = download_url( $src, 60 );
			if ( is_wp_error( $tmp ) ) {
				PMS_Logger::error( 'Image download failed: ' . $src . ' — ' . $tmp->get_error_message() );
				return 0;
			}
			$name = basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
		} else {
			if ( ! is_readable( $src ) ) {
				PMS_Logger::error( 'Image file not readable: ' . $src );
				return 0;
			}
			// Work on a copy: media_handle_sideload moves its input file.
			$tmp = wp_tempnam( basename( $src ) );
			if ( ! $tmp || ! copy( $src, $tmp ) ) {
				return 0;
			}
			$name = basename( $src );
		}

		$attachment_id = media_handle_sideload(
			array( 'name' => sanitize_file_name( $name ), 'tmp_name' => $tmp ),
			$post_id
		);
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			PMS_Logger::error( 'Sideload failed for ' . $name . ': ' . $attachment_id->get_error_message() );
			return 0;
		}

		update_post_meta( $attachment_id, '_pms_src', $key );
		return (int) $attachment_id;
	}

	private static function find_by_key( $key ) {
		$found = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_pms_src',
			'meta_value'     => $key,
		) );
		return $found ? (int) $found[0] : 0;
	}

	/** Featured image + ACF gallery, in source order (main image first). */
	public static function apply_gallery( $post_id, array $attachment_ids ) {
		$attachment_ids = array_values( array_filter( array_map( 'intval', $attachment_ids ) ) );
		if ( ! $attachment_ids ) {
			return;
		}
		set_post_thumbnail( $post_id, $attachment_ids[0] );
		// ACF galleries store IDs as strings — match that exactly.
		PMS_Sync::set_field_meta( $post_id, 'images', array_map( 'strval', $attachment_ids ) );
	}

	public static function apply_floorplan( $post_id, $attachment_id ) {
		if ( $attachment_id ) {
			PMS_Sync::set_field_meta( $post_id, 'floorplan', (string) (int) $attachment_id );
		}
	}
}
