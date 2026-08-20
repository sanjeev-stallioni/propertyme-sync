<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cron scheduling + sync engine.
 *
 * Flow per lot:  PropertyMe API  →  wp_pms_properties (custom table, source
 * of truth: normalized columns + raw JSON)  →  projection into the existing
 * frontend structure (a regular post + field meta + For Lease/Leased
 * category), so the site's design and templates keep working unchanged.
 *
 * Uses WordPress core APIs only — no third-party plugin functions. Field
 * meta is written in the exact key/value layout the theme already reads
 * (value row + '_name' => field key reference row).
 *
 * PropertyMe entities are matched via lot_id in the custom table and the
 * _pms_lot_id post meta; pre-existing manual posts are adopted once by
 * slugified address.
 *
 * NOTE: the exact /lots response shape is confirmed on first real sync —
 * extract() covers plausible key variants and the first item of every sync
 * is logged raw so the mapping can be verified quickly. Images are
 * deliberately out of scope for now.
 */
class PMS_Sync {

	const CRON_HOOK = 'pms_sync_event';

	/** Field name => field key reference (from the existing "Property" field group). */
	const PROPERTY_FIELDS = array(
		'property_type'        => 'field_69e84daef2fad',
		'leased_type'          => 'field_6a0667a78bde6',
		'address_suburb'       => 'field_69e8074feef77',
		'postcode'             => 'field_69e80a7f75687',
		'bed'                  => 'field_69e80aaf8ea97',
		'bath'                 => 'field_69e80abd8ea98',
		'car'                  => 'field_69e80ac88ea99',
		'images'               => 'field_69e84a4eff201',
		'floorplan'            => 'field_69e80b07f080b',
		'cost_$'               => 'field_69e80f279ca3d',
		'description'          => 'field_69e80b72d9eba',
		'inspection_date'      => 'field_69e80d5a7c5af',
		'inspection_times'     => 'field_69e80ba1c7dec',
		'maps'                 => 'field_69e80baec7ded',
		'property_agent'       => 'field_6a06be98c4e3c',
		'property_agent_email' => 'field_6a0c32ccb6ce8',
		'notes'                => 'field_6a08fce42e441',
	);

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		PMS_DB::maybe_upgrade();
	}

	public static function cron_schedules( $schedules ) {
		$schedules['pms_6h']  = array( 'interval' => 6 * HOUR_IN_SECONDS,  'display' => 'Every 6 hours (PropertyMe)' );
		$schedules['pms_8h']  = array( 'interval' => 8 * HOUR_IN_SECONDS,  'display' => 'Every 8 hours (PropertyMe)' );
		$schedules['pms_12h'] = array( 'interval' => 12 * HOUR_IN_SECONDS, 'display' => 'Every 12 hours (PropertyMe)' );
		return $schedules;
	}

	public static function activate() {
		PMS_DB::install();
		self::reschedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** (Re)schedule the cron event according to current settings. */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$s = pms_get_settings();
		if ( ! empty( $s['sync_enabled'] ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $s['sync_interval'], self::CRON_HOOK );
		}
	}

	/* ---------- Sync run ---------- */

	public static function run() {
		if ( get_transient( 'pms_sync_running' ) ) {
			PMS_Logger::info( 'Sync skipped: previous run still in progress.' );
			return;
		}
		set_transient( 'pms_sync_running', 1, 15 * MINUTE_IN_SECONDS );
		PMS_Logger::info( 'Sync started.' );

		try {
			$counts           = self::sync_properties();
			$counts['photos'] = self::sync_api_images();
			update_option( 'pms_last_sync', current_time( 'mysql' ), false );
			PMS_Logger::info( 'Sync finished.', $counts );
			PMS_REAXML::import_dir();
		} catch ( Throwable $e ) {
			PMS_Logger::error( 'Sync crashed: ' . $e->getMessage() );
		} finally {
			delete_transient( 'pms_sync_running' );
		}
	}

	/**
	 * Photos from the API: GET lots/{id}/images per synced lot. Currently
	 * every portfolio lot returns [] (no photos uploaded in the CRM), so
	 * this is a no-op until they appear; REA XML is the main photo source.
	 */
	public static function sync_api_images() {
		global $wpdb;
		$rows     = $wpdb->get_results( 'SELECT lot_id, post_id FROM ' . PMS_DB::table() . ' WHERE post_id > 0', ARRAY_A );
		$imported = 0;
		foreach ( (array) $rows as $row ) {
			$images = PMS_API::get( 'lots/' . $row['lot_id'] . '/images' );
			if ( is_wp_error( $images ) || empty( $images ) || ! is_array( $images ) ) {
				continue;
			}
			$ids = array();
			foreach ( $images as $image ) {
				if ( ! is_array( $image ) ) {
					continue;
				}
				$url = '';
				foreach ( array( 'Url', 'DownloadUrl', 'url', 'Uri', 'Href' ) as $key ) {
					if ( ! empty( $image[ $key ] ) && is_string( $image[ $key ] ) ) {
						$url = $image[ $key ];
						break;
					}
				}
				if ( '' === $url ) {
					PMS_Logger::info( 'API image with no recognisable URL key — raw sample logged.', $image );
					continue;
				}
				$att = PMS_Media::import( (int) $row['post_id'], $url, (string) ( $image['UpdatedOn'] ?? ( $image['Id'] ?? '' ) ) );
				if ( $att ) {
					$ids[] = $att;
				}
			}
			if ( $ids ) {
				PMS_Media::apply_gallery( (int) $row['post_id'], $ids );
				$imported += count( $ids );
			}
		}
		return $imported;
	}

	private static function sync_properties() {
		$lots = PMS_API::get( 'lots' );
		if ( is_wp_error( $lots ) ) {
			PMS_Logger::error( 'Fetching lots failed: ' . $lots->get_error_message() );
			return array( 'error' => $lots->get_error_message() );
		}

		// Some APIs wrap the collection; unwrap common envelope keys.
		foreach ( array( 'items', 'data', 'results', 'value', 'lots' ) as $envelope ) {
			if ( isset( $lots[ $envelope ] ) && is_array( $lots[ $envelope ] ) ) {
				$lots = $lots[ $envelope ];
				break;
			}
		}
		if ( empty( $lots ) || ! is_array( $lots ) ) {
			PMS_Logger::info( 'No lots returned by the API.' );
			return array( 'lots' => 0 );
		}

		// Discovery aid: keep one raw sample so field mapping can be verified.
		PMS_Logger::info( 'Raw sample of first lot (for mapping verification).', reset( $lots ) );

		$stored    = 0;
		$projected = 0;
		foreach ( $lots as $lot ) {
			if ( ! is_array( $lot ) ) {
				continue;
			}
			$row_id = self::store_lot( $lot );
			if ( $row_id ) {
				$stored++;
				if ( self::project_to_post( $row_id ) ) {
					$projected++;
				}
			}
		}
		return array( 'lots' => count( $lots ), 'stored' => $stored, 'projected' => $projected );
	}

	/* ---------- Stage 1: API payload → custom table ---------- */

	/**
	 * Mapping is based on the real /api/v1/lots payload (verified 2026-08-20):
	 * Id, AddressText ("12B Sample Rd, Suburbton WA 6000"), nested Address
	 * {Unit, Number, Street, Suburb, PostalCode, State, Latitude, Longitude},
	 * Bedrooms/Bathrooms/CarSpaces, RentAmount + AdRentAmount (advertised),
	 * PropertySubtype/PropertyType, ManagerName (the agent), Vacant (drives
	 * For Lease/Leased), ArchivedOn/IsArchived, Description, Notes (internal
	 * CRM notes — deliberately NOT synced to the public site).
	 * Beware: DisplayAddress is a BOOLEAN flag, not an address.
	 */
	private static function store_lot( array $lot ) {
		$lot_id = self::str( $lot['Id'] ?? '' );
		if ( '' === $lot_id ) {
			return 0;
		}
		if ( ! empty( $lot['IsArchived'] ) || ! empty( $lot['ArchivedOn'] ) ) {
			PMS_Logger::info( 'Skipped archived lot ' . $lot_id . '.' );
			return 0;
		}

		$addr = is_array( $lot['Address'] ?? null ) ? $lot['Address'] : array();

		// Title in the site's existing style: "12 Sample Court", "8/1 Sample Drive".
		$unit    = self::str( $addr['Unit'] ?? '' );
		$number  = self::str( $addr['Number'] ?? '' );
		$street  = self::expand_street( self::str( $addr['Street'] ?? '' ) );
		$address = trim( ( '' !== $unit ? $unit . '/' : '' ) . trim( $number . ' ' . $street ) );
		if ( strlen( $address ) < 4 ) {
			$address = self::str( $lot['AddressText'] ?? '' );
		}
		if ( strlen( $address ) < 4 ) {
			PMS_Logger::error( 'Skipped lot ' . $lot_id . ': no usable address.', $addr );
			return 0;
		}

		// Advertised rent when listed, otherwise the tenancy rent.
		$rent = self::str( $lot['AdRentAmount'] ?? '' );
		if ( '' === $rent || '0' === $rent ) {
			$rent = self::str( $lot['RentAmount'] ?? '' );
		}

		return PMS_DB::upsert_lot( array(
			'lot_id'         => $lot_id,
			'address'        => $address,
			'suburb'         => self::str( $addr['Suburb'] ?? '' ),
			'postcode'       => self::str( $addr['PostalCode'] ?? '' ),
			'bed'            => self::str( $lot['Bedrooms'] ?? '' ),
			'bath'           => self::str( $lot['Bathrooms'] ?? '' ),
			'car'            => self::str( $lot['CarSpaces'] ?? '' ),
			'rent'           => '0' === $rent ? '' : $rent,
			'property_type'  => self::str( $lot['PropertySubtype'] ?? ( $lot['PropertyType'] ?? '' ) ),
			'listing_status' => empty( $lot['Vacant'] ) ? 'leased' : 'for_lease',
			'agent_name'     => self::str( $lot['ManagerName'] ?? '' ),
			'agent_email'    => '', // not in the lot payload; needs the contacts endpoint
			'description'    => self::str( $lot['Description'] ?? '' ),
		), $lot );
	}

	/**
	 * Find the agents-CPT post for a manager name (cached per request).
	 * An unknown manager gets a new agent post created automatically — with
	 * the name only, since the lot payload carries nothing else; photo,
	 * position, phone, and email are then filled in by the client under
	 * wp-admin → Agents (logged so they know to).
	 */
	private static function find_agent( $name ) {
		static $cache = array();
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		if ( ! isset( $cache[ $name ] ) ) {
			global $wpdb;
			$cache[ $name ] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'agents' AND post_status IN ('publish','draft') AND post_title = %s LIMIT 1",
				$name
			) );
			if ( ! $cache[ $name ] ) {
				$new_id = wp_insert_post( array(
					'post_type'   => 'agents',
					'post_status' => 'publish',
					'post_title'  => $name,
				), true );
				if ( is_wp_error( $new_id ) ) {
					PMS_Logger::error( 'Could not create agent post for "' . $name . '": ' . $new_id->get_error_message() );
					$new_id = 0;
				} else {
					PMS_Logger::info( 'New agent "' . $name . '" found in PropertyMe — agent post created (add photo/phone/email under Agents in wp-admin).' );
				}
				$cache[ $name ] = (int) $new_id;
			}
		}
		return $cache[ $name ];
	}

	/** Scalar-to-string cast that refuses booleans/arrays (e.g. DisplayAddress). */
	private static function str( $value ) {
		return ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) ? trim( (string) $value ) : '';
	}

	/** Expand the trailing AU street-type abbreviation to the site's naming style. */
	public static function expand_street( $street ) {
		static $map = array(
			'st' => 'Street', 'rd' => 'Road', 'ct' => 'Court', 'ave' => 'Avenue',
			'dr' => 'Drive', 'pl' => 'Place', 'cres' => 'Crescent', 'la' => 'Lane',
			'cl' => 'Close', 'tce' => 'Terrace', 'pde' => 'Parade', 'hwy' => 'Highway',
			'blvd' => 'Boulevard', 'cir' => 'Circle', 'sq' => 'Square', 'gr' => 'Grove',
			'esp' => 'Esplanade', 'cct' => 'Circuit',
		);
		$words = preg_split( '/\s+/', trim( $street ) );
		if ( count( $words ) > 1 ) {
			$last = strtolower( rtrim( end( $words ), '.' ) );
			if ( isset( $map[ $last ] ) ) {
				$words[ count( $words ) - 1 ] = $map[ $last ];
			}
		}
		return implode( ' ', $words );
	}

	/* ---------- Stage 2: custom table → existing frontend structure ---------- */

	/**
	 * Project one table row into the post structure the theme renders.
	 * Public so a full re-projection can be run after a mapping change.
	 */
	public static function project_to_post( $row_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . PMS_DB::table() . ' WHERE id = %d', $row_id
		), ARRAY_A );
		if ( ! $row ) {
			return false;
		}

		$post_id = self::find_post( $row );
		$postarr = array(
			'post_title'  => $row['address'],
			'post_type'   => 'post',
			'post_status' => 'publish',
		);
		if ( $post_id ) {
			$postarr['ID'] = $post_id;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $post_id ) ) {
			PMS_Logger::error( 'Projection failed for lot ' . $row['lot_id'] . ': ' . $post_id->get_error_message() );
			return false;
		}

		update_post_meta( $post_id, '_pms_lot_id', $row['lot_id'] );
		update_post_meta( $post_id, '_pms_synced_at', $row['synced_at'] );
		PMS_DB::set_post_id( $row_id, $post_id );

		self::set_field_meta( $post_id, 'address_suburb', $row['suburb'] );
		self::set_field_meta( $post_id, 'postcode', $row['postcode'] );
		self::set_field_meta( $post_id, 'bed', $row['bed'] );
		self::set_field_meta( $post_id, 'bath', $row['bath'] );
		self::set_field_meta( $post_id, 'car', $row['car'] );
		// Always written (even empty): the listing widget's price filter
		// requires the cost_$ meta row to exist, else the post vanishes
		// whenever a price range is applied.
		self::set_field_meta( $post_id, 'cost_$', $row['rent'], true );
		self::set_field_meta( $post_id, 'description', $row['description'] );
		self::set_field_meta( $post_id, 'property_type', $row['property_type'] );
		// property_agent is an ACF post_object field → store the matching
		// agents-CPT post ID, not the name string.
		$agent_post_id = self::find_agent( $row['agent_name'] );
		if ( $agent_post_id ) {
			self::set_field_meta( $post_id, 'property_agent', (string) $agent_post_id );
		}
		self::set_field_meta( $post_id, 'property_agent_email', $row['agent_email'] );

		// The map widget reads the ACF Google Map field "maps" (needs lat/lng);
		// PropertyMe supplies coordinates in the lot's Address block (kept in raw).
		$raw = json_decode( (string) $row['raw'], true );
		$geo = is_array( $raw['Address'] ?? null ) ? $raw['Address'] : array();
		if ( ! empty( $geo['Latitude'] ) && ! empty( $geo['Longitude'] ) ) {
			self::set_field_meta( $post_id, 'maps', array(
				'address' => self::str( $raw['AddressText'] ?? '' ),
				'lat'     => (float) $geo['Latitude'],
				'lng'     => (float) $geo['Longitude'],
				'zoom'    => 14,
			) );
		}

		// Listing status (from the lot's Vacant flag). The frontend listing
		// widget filters on the leased_type meta field; the category mirrors
		// it for archive/SEO consistency.
		$status_label = 'leased' === $row['listing_status'] ? 'Leased' : 'For Lease';
		self::set_field_meta( $post_id, 'leased_type', $status_label );
		wp_set_object_terms( $post_id, $status_label, 'category' );

		self::ensure_elementor_layout( $post_id );

		return true;
	}

	/**
	 * Property detail pages are per-post Elementor layouts whose widgets are
	 * all bound to dynamic tags (ACF gallery carousel, field headings,
	 * agent card, map, enquiry form). New synced posts get a copy of the
	 * layout from a designated template post so they render identically;
	 * posts that already have their own Elementor data are left untouched.
	 */
	private static function ensure_elementor_layout( $post_id ) {
		if ( get_post_meta( $post_id, '_elementor_data', true ) ) {
			return;
		}
		$template_id = (int) get_option( 'pms_layout_template_post', 991956 ); // site's designated template property post
		$layout      = get_post_meta( $template_id, '_elementor_data', true );
		if ( ! $layout || ! is_string( $layout ) ) {
			PMS_Logger::error( 'Layout template post ' . $template_id . ' has no Elementor data — detail layout not applied to post ' . $post_id . '.' );
			return;
		}
		update_post_meta( $post_id, '_elementor_data', wp_slash( $layout ) );
		foreach ( array( '_elementor_edit_mode', '_elementor_template_type', '_elementor_version', '_elementor_page_settings' ) as $meta_key ) {
			$value = get_post_meta( $template_id, $meta_key, true );
			if ( '' !== $value && null !== $value ) {
				update_post_meta( $post_id, $meta_key, $value );
			}
		}
		delete_post_meta( $post_id, '_elementor_css' ); // regenerate per post
	}

	/**
	 * Match by PropertyMe id first, then adopt a pre-existing manual post by
	 * slugified address or exact title. A candidate already claimed by a
	 * DIFFERENT lot is never adopted — that guard prevents one post being
	 * hijacked by several lots.
	 */
	private static function find_post( array $row ) {
		if ( ! empty( $row['post_id'] ) && get_post( (int) $row['post_id'] ) ) {
			return (int) $row['post_id'];
		}
		$found = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_pms_lot_id',
			'meta_value'     => $row['lot_id'],
		) );
		if ( $found ) {
			return (int) $found[0];
		}

		$candidate = get_page_by_path( sanitize_title( $row['address'] ), OBJECT, 'post' );
		$candidate = $candidate ? (int) $candidate->ID : 0;
		if ( ! $candidate ) {
			global $wpdb;
			$candidate = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_title = %s LIMIT 1",
				$row['address']
			) );
		}
		if ( $candidate ) {
			$claimed_by = get_post_meta( $candidate, '_pms_lot_id', true );
			if ( '' !== $claimed_by && $claimed_by !== $row['lot_id'] ) {
				return 0; // belongs to another lot — create a fresh post instead
			}
		}
		return $candidate;
	}

	/**
	 * Write a field value in the storage layout the theme already reads:
	 * the value row plus the '_name' => field key reference row. Core
	 * update_post_meta() only — no plugin API involved. Arrays (gallery
	 * IDs) serialize exactly as the field type expects.
	 */
	public static function set_field_meta( $post_id, $field_name, $value, $allow_empty = false ) {
		if ( ( '' === $value || null === $value ) && ! $allow_empty ) {
			return;
		}
		$value = null === $value ? '' : $value;
		$field_key = self::PROPERTY_FIELDS[ $field_name ] ?? '';
		if ( '' === $field_key ) {
			return;
		}
		update_post_meta( $post_id, $field_name, $value );
		update_post_meta( $post_id, '_' . $field_name, $field_key );
	}
}
