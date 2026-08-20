<?php
defined( 'ABSPATH' ) || exit;

/**
 * REA XML importer (realestate.com.au agent-feed format).
 *
 * PropertyMe publishes listing feeds as REAXML files — <propertyList>
 * containing <rental> elements with address, description, features,
 * inspection times, <images><img url=|file=> and <objects><floorplan>.
 * Files are read from a drop directory (uploads/propertyme-feed by
 * default), matched to synced property posts, and moved to processed/
 * afterwards. Photos referenced by file= are resolved next to the XML.
 *
 * Listing → post matching order:
 *   1. _pms_rea_unique_id meta from an earlier import
 *   2. uniqueID equals a PropertyMe lot id in wp_pms_properties
 *   3. address built from unit/streetNumber/street (street type expanded)
 *      matched against post slug/title
 */
class PMS_REAXML {

	public static function feed_dir() {
		$uploads = wp_upload_dir();
		$dir     = apply_filters( 'pms_reaxml_dir', trailingslashit( $uploads['basedir'] ) . 'propertyme-feed' );
		wp_mkdir_p( $dir );
		return $dir;
	}

	/** Import every *.xml in the feed directory. Returns per-file counts. */
	public static function import_dir() {
		$dir   = self::feed_dir();
		$files = array_merge( glob( $dir . '/*.xml' ) ?: array(), glob( $dir . '/*.XML' ) ?: array() );
		if ( ! $files ) {
			PMS_Logger::info( 'REAXML: no files found in ' . $dir );
			return array();
		}
		$results = array();
		foreach ( $files as $file ) {
			$results[ basename( $file ) ] = self::import_file( $file );
			self::archive( $file );
		}
		PMS_Logger::info( 'REAXML import finished.', $results );
		return $results;
	}

	public static function import_file( $path ) {
		libxml_use_internal_errors( true );
		$xml = simplexml_load_file( $path );
		if ( false === $xml ) {
			PMS_Logger::error( 'REAXML: cannot parse ' . basename( $path ) );
			return array( 'error' => 'parse failed' );
		}

		$matched = 0;
		$skipped = 0;
		$images  = 0;
		foreach ( $xml->children() as $listing ) {
			if ( ! isset( $listing->uniqueID ) ) {
				continue; // not a listing element
			}
			$result = self::import_listing( $listing, dirname( $path ) );
			if ( $result['post_id'] ) {
				$matched++;
				$images += $result['images'];
			} else {
				$skipped++;
			}
		}
		return compact( 'matched', 'skipped', 'images' );
	}

	private static function import_listing( SimpleXMLElement $listing, $base_dir ) {
		$unique_id = trim( (string) $listing->uniqueID );
		$status    = strtolower( trim( (string) $listing['status'] ) );

		// Withdrawn/offmarket carry no data payload — nothing to update.
		if ( in_array( $status, array( 'withdrawn', 'offmarket', 'deleted' ), true ) ) {
			return array( 'post_id' => 0, 'images' => 0 );
		}

		$post_id = self::match_post( $unique_id, $listing );
		if ( ! $post_id ) {
			PMS_Logger::info( 'REAXML: no matching property post for uniqueID ' . $unique_id . ' — skipped.' );
			return array( 'post_id' => 0, 'images' => 0 );
		}
		update_post_meta( $post_id, '_pms_rea_unique_id', $unique_id );

		// Ad copy and inspections come from the listing, not the CRM lot.
		$description = trim( (string) $listing->description );
		if ( '' !== $description ) {
			PMS_Sync::set_field_meta( $post_id, 'description', $description );
		}
		if ( isset( $listing->inspectionTimes->inspection ) ) {
			$times = array();
			foreach ( $listing->inspectionTimes->inspection as $inspection ) {
				$t = trim( (string) $inspection );
				if ( '' !== $t ) {
					$times[] = $t;
				}
			}
			if ( $times ) {
				PMS_Sync::set_field_meta( $post_id, 'inspection_times', implode( ', ', $times ) );
			}
		}

		// Photos: <images><img id="m|a|b..." url=|file= modTime=>, main first.
		$attachment_ids = array();
		if ( isset( $listing->images->img ) ) {
			foreach ( $listing->images->img as $img ) {
				$att = self::import_object( $post_id, $img, $base_dir );
				if ( $att ) {
					$attachment_ids[ strtolower( (string) $img['id'] ) ] = $att;
				}
			}
		}
		if ( $attachment_ids ) {
			ksort( $attachment_ids ); // 'a'..'z' order; 'm' (main) forced first:
			if ( isset( $attachment_ids['m'] ) ) {
				$attachment_ids = array( 'm' => $attachment_ids['m'] ) + $attachment_ids;
			}
			PMS_Media::apply_gallery( $post_id, array_values( $attachment_ids ) );
		}

		// First floorplan → the ACF file field.
		if ( isset( $listing->objects->floorplan ) ) {
			foreach ( $listing->objects->floorplan as $plan ) {
				$att = self::import_object( $post_id, $plan, $base_dir );
				if ( $att ) {
					PMS_Media::apply_floorplan( $post_id, $att );
					break;
				}
			}
		}

		return array( 'post_id' => $post_id, 'images' => count( $attachment_ids ) );
	}

	/** Resolve an <img>/<floorplan> element's url= or file= source and import it. */
	private static function import_object( $post_id, SimpleXMLElement $el, $base_dir ) {
		$version = (string) $el['modTime'];
		$url     = trim( (string) $el['url'] );
		if ( '' !== $url ) {
			return PMS_Media::import( $post_id, $url, $version );
		}
		$file = basename( trim( (string) $el['file'] ) ); // no path traversal
		if ( '' !== $file ) {
			return PMS_Media::import( $post_id, trailingslashit( $base_dir ) . $file, $version );
		}
		return 0;
	}

	private static function match_post( $unique_id, SimpleXMLElement $listing ) {
		if ( '' !== $unique_id ) {
			$found = get_posts( array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_pms_rea_unique_id',
				'meta_value'     => $unique_id,
			) );
			if ( $found ) {
				return (int) $found[0];
			}
			$row = PMS_DB::get_by_lot_id( $unique_id );
			if ( $row && ! empty( $row['post_id'] ) && get_post( (int) $row['post_id'] ) ) {
				return (int) $row['post_id'];
			}
		}

		// Fall back to the address, built the same way the sync builds titles.
		$addr = $listing->address;
		if ( ! $addr ) {
			return 0;
		}
		$unit    = trim( (string) $addr->unitNumber );
		$number  = trim( (string) $addr->streetNumber );
		$street  = PMS_Sync::expand_street( trim( (string) $addr->street ) );
		$address = trim( ( '' !== $unit ? $unit . '/' : '' ) . trim( $number . ' ' . $street ) );
		if ( strlen( $address ) < 4 ) {
			return 0;
		}
		$candidate = get_page_by_path( sanitize_title( $address ), OBJECT, 'post' );
		if ( $candidate ) {
			return (int) $candidate->ID;
		}
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_title = %s LIMIT 1",
			$address
		) );
	}

	private static function archive( $file ) {
		$processed = trailingslashit( dirname( $file ) ) . 'processed';
		wp_mkdir_p( $processed );
		@rename( $file, trailingslashit( $processed ) . gmdate( 'Ymd-His' ) . '-' . basename( $file ) );
	}
}
