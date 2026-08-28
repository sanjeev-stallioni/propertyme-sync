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
	/** ACF field keys on the agents CPT (additional_fields group). */
	const AGENT_FIELDS = array(
		'title'         => 'field_6a06bc42bdc14',
		'phone_number'  => 'field_6a06bc55bdc15',
		'email_address' => 'field_6a06bc76bdc16',
	);

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
		// TESTING ONLY — remove before release. Lets a full cron cycle be
		// observed in minutes instead of hours; far too frequent for live use.
		$schedules['pms_5min'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (PropertyMe — TESTING ONLY)' );
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
			// Photos come from REAXML only. The API cannot supply them:
			// /v1/lots/{Id}/images returns document metadata with no URL of
			// any kind, and the file itself is served from a host that accepts
			// only a browser login session, never an API token. Confirmed
			// 2026-08-28 against a real uploaded photo — see PROJECT-STATUS.md.
			$counts = self::sync_properties();
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
	 * Highest lot Timestamp seen so far. PropertyMe's Timestamp is an opaque
	 * ascending int64 (not a date), and /v1/lots?Timestamp=N returns only lots
	 * with a value strictly greater than N — so storing the maximum we have
	 * seen and passing it back is an exact "what changed since" cursor.
	 */
	const DELTA_OPTION = 'pms_delta_cursor';

	/**
	 * How many delta runs may pass before a full sweep is forced.
	 *
	 * A delta run cannot see a lot that has been ARCHIVED, because archiving
	 * removes it from /v1/lots altogether rather than returning it with a
	 * flag. Only a full listing reveals that a property has gone, so the
	 * sweep is what keeps archived properties from lingering on the site.
	 */
	const FULL_SWEEP_EVERY = 8;

	/**
	 * Fetch lots and project them onto the site.
	 *
	 * Runs incrementally where possible: the API's Timestamp cursor means a
	 * quiet portfolio costs one request returning nothing instead of a full
	 * re-read of every property. Every FULL_SWEEP_EVERY-th run (and any run
	 * with no stored cursor) fetches everything and reconciles removals.
	 */
	private static function sync_properties() {
		$settings = pms_get_settings();
		$cursor   = (string) get_option( self::DELTA_OPTION, '' );
		$runs     = (int) get_option( 'pms_delta_runs', 0 );

		// A sweep is due when delta is switched off, nothing has been synced
		// yet, or enough incremental runs have passed to re-check for removals.
		$full = empty( $settings['delta_sync'] )
			|| '' === $cursor
			|| $runs >= self::FULL_SWEEP_EVERY;

		$query = $full ? array() : array( 'Timestamp' => $cursor );
		$lots  = PMS_API::get( 'lots', $query );

		// A rejected cursor must never stall the sync — fall back to a sweep.
		if ( is_wp_error( $lots ) && ! $full ) {
			PMS_Logger::info( 'Incremental fetch failed (' . $lots->get_error_message() . ') — falling back to a full sync.' );
			$full  = true;
			$query = array();
			$lots  = PMS_API::get( 'lots' );
		}

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
		if ( ! is_array( $lots ) ) {
			$lots = array();
		}

		update_option( 'pms_delta_runs', $full ? 0 : $runs + 1, false );

		// Nothing changed since the cursor: the common case for a delta run.
		if ( empty( $lots ) ) {
			if ( $full ) {
				PMS_Logger::info( 'No lots returned by the API.' );
				return array( 'lots' => 0 );
			}
			return array( 'mode' => 'incremental', 'lots' => 0, 'changed' => 0 );
		}

		// Discovery aid: keep one raw sample so field mapping can be verified.
		if ( $full ) {
			PMS_Logger::info( 'Raw sample of first lot (for mapping verification).', reset( $lots ) );
		}

		$stored    = 0;
		$projected = 0;
		$seen      = array();
		$high      = $cursor;
		foreach ( $lots as $lot ) {
			if ( ! is_array( $lot ) ) {
				continue;
			}
			$lot_id = self::str( $lot['Id'] ?? '' );
			if ( '' !== $lot_id ) {
				$seen[] = $lot_id;
			}
			// Advance the cursor over every lot received, including archived
			// and unusable ones — skipping them would re-fetch them forever.
			$ts = $lot['Timestamp'] ?? null;
			if ( is_numeric( $ts ) && self::ts_greater( (string) $ts, $high ) ) {
				$high = (string) $ts;
			}
			$row_id = self::store_lot( $lot );
			if ( $row_id ) {
				$stored++;
				if ( self::project_to_post( $row_id ) ) {
					$projected++;
				}
			}
		}

		if ( '' !== $high && $high !== $cursor ) {
			update_option( self::DELTA_OPTION, $high, false );
		}

		$counts = array(
			'mode'      => $full ? 'full' : 'incremental',
			'lots'      => count( $lots ),
			'stored'    => $stored,
			'projected' => $projected,
		);

		// Only a full listing proves a property is gone from PropertyMe.
		if ( $full ) {
			$counts['removed'] = self::reconcile_removed( $seen );
		}

		return $counts;
	}

	/**
	 * Is timestamp $a greater than $b?
	 *
	 * PropertyMe's Timestamp is an 18-digit int64, which exceeds the exact
	 * range of a PHP float on 32-bit builds and sits at the edge of it
	 * everywhere else. Compare as digit strings (length first, then
	 * lexically) so no precision is lost.
	 */
	private static function ts_greater( $a, $b ) {
		if ( '' === $b ) {
			return true;
		}
		$a = ltrim( $a, '0' );
		$b = ltrim( $b, '0' );
		if ( strlen( $a ) !== strlen( $b ) ) {
			return strlen( $a ) > strlen( $b );
		}
		return strcmp( $a, $b ) > 0;
	}

	/**
	 * Unpublish properties that PropertyMe no longer returns.
	 *
	 * Archiving a lot removes it from /v1/lots entirely, so a property that
	 * has been archived (or deleted) would otherwise stay published on the
	 * site forever. Posts are moved to draft rather than deleted: photos,
	 * layout and any manual edits survive, and the client can restore or bin
	 * them from wp-admin. Only posts this plugin created are touched.
	 *
	 * @param string[] $seen Lot ids present in a full API listing.
	 */
	private static function reconcile_removed( array $seen ) {
		if ( empty( $seen ) ) {
			return 0; // never unpublish on an empty/failed listing
		}

		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT lot_id, post_id FROM ' . PMS_DB::table() . ' WHERE post_id > 0', ARRAY_A );
		$keep = array_flip( $seen );
		$gone = 0;

		foreach ( (array) $rows as $row ) {
			if ( isset( $keep[ $row['lot_id'] ] ) ) {
				continue;
			}
			$post_id = (int) $row['post_id'];
			if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
				continue;
			}
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
			$gone++;
			PMS_Logger::info( sprintf(
				'"%s" is no longer in PropertyMe (archived or removed) — moved to Draft so it no longer shows on the site. Nothing was deleted.',
				get_the_title( $post_id )
			) );
		}
		return $gone;
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
			'description'    => self::clean_description( $lot['Description'] ?? '' ),
		), $lot );
	}

	/**
	 * Staff records from /v1/members, keyed by member id. Fetched once per
	 * run; an API failure means no agent can be matched this run (matching is
	 * by email, and the email lives here), which is logged rather than fatal.
	 *
	 * Note the portfolio owner's API/developer login also appears here; it is
	 * not a property manager, so members are only ever used to enrich an agent
	 * that a lot actually references.
	 */
	private static function members() {
		static $members = null;
		if ( null !== $members ) {
			return $members;
		}
		$members  = array( 'by_id' => array() );
		$response = PMS_API::get( 'members' );
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			if ( is_wp_error( $response ) ) {
				PMS_Logger::info( 'Could not load PropertyMe members (agent contact details skipped): ' . $response->get_error_message() );
			}
			return $members;
		}
		foreach ( $response as $member ) {
			if ( ! is_array( $member ) ) {
				continue;
			}
			if ( ! empty( $member['Id'] ) ) {
				$members['by_id'][ (string) $member['Id'] ] = $member;
			}
		}
		return $members;
	}

	/**
	 * Contact details for a lot's manager, from /v1/members.
	 *
	 * Resolved on ActiveManagerMemberId — the id every lot carries and the
	 * only exact link between a property and a staff profile. Missing fields
	 * are simply absent: the agency has only filled some of the staff profiles
	 * in PropertyMe.
	 *
	 * @return array{title:string,phone_number:string,email_address:string}
	 */
	private static function agent_details( $member_id ) {
		$members = self::members();
		$member  = null;

		if ( $member_id && isset( $members['by_id'][ (string) $member_id ] ) ) {
			$member = $members['by_id'][ (string) $member_id ];
		}

		if ( ! $member ) {
			return array( 'title' => '', 'phone_number' => '', 'email_address' => '' );
		}

		// Mobile is the number the agency publishes; work phone is the fallback.
		$phone = trim( (string) ( $member['MobilePhone'] ?? '' ) );
		if ( '' === $phone ) {
			$phone = trim( (string) ( $member['WorkPhone'] ?? '' ) );
		}

		return array(
			'title'         => trim( (string) ( $member['JobTitle'] ?? '' ) ),
			'phone_number'  => $phone,
			'email_address' => trim( (string) ( $member['RegisteredEmail'] ?? '' ) ),
		);
	}

	/**
	 * Write agent contact fields, never overwriting details a person has
	 * already filled in by hand in wp-admin.
	 */
	private static function fill_agent_fields( $agent_id, array $details ) {
		foreach ( $details as $field => $value ) {
			if ( '' === $value || ! isset( self::AGENT_FIELDS[ $field ] ) ) {
				continue;
			}
			$meta_key = 'additional_fields_' . $field;
			$existing = get_post_meta( $agent_id, $meta_key, true );
			// ACF stores this group's values as single-item arrays.
			$current  = is_array( $existing ) ? trim( (string) reset( $existing ) ) : trim( (string) $existing );
			if ( '' !== $current ) {
				continue; // respect anything entered manually
			}
			update_post_meta( $agent_id, $meta_key, array( $value ) );
			update_post_meta( $agent_id, '_' . $meta_key, self::AGENT_FIELDS[ $field ] );
		}
	}

	/**
	 * Give a newly created agent the site's placeholder portrait, so agent
	 * cards keep their layout until a real photo is uploaded. PropertyMe's API
	 * never exposes staff photos, so this is always a manual follow-up.
	 *
	 * The image is chosen by the pms_agent_placeholder_image option (an
	 * attachment id), falling back to an existing agent's photo so the
	 * placeholder matches the site's own styling.
	 */
	private static function set_agent_placeholder_image( $agent_id ) {
		if ( get_post_thumbnail_id( $agent_id ) ) {
			return;
		}

		$attachment_id = (int) get_option( 'pms_agent_placeholder_image', 0 );

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			global $wpdb;
			$attachment_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT m.meta_value FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'agents'
				 WHERE m.meta_key = '_thumbnail_id' AND m.post_id != %d
				 ORDER BY p.ID ASC LIMIT 1",
				$agent_id
			) );
		}

		if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
			set_post_thumbnail( $agent_id, $attachment_id );
		}
	}

	/**
	 * The agents-CPT post for a manager, created if it doesn't exist yet.
	 *
	 * Matching is by EMAIL ADDRESS only. The email on PropertyMe's staff
	 * profile (/v1/members, resolved from the lot's ActiveManagerMemberId) is
	 * compared against the agent's email_address field in WordPress, so an
	 * agent whose name is spelt differently on the two systems still resolves
	 * to the same post — e.g. "Sharon Hamiton" in WordPress against
	 * "Sharon Hamilton" in PropertyMe.
	 *
	 * A manager with no email on their PropertyMe staff profile cannot be
	 * matched, and is left unlinked rather than guessed at by name, which
	 * could attach properties to the wrong person. The log names anyone
	 * affected so the agency can fill the profile in.
	 *
	 * @param string $name      Manager name from the lot (used for the post title only).
	 * @param string $member_id PropertyMe ActiveManagerMemberId, when known.
	 */
	private static function find_agent( $name, $member_id = '' ) {
		static $cache = array();
		$name = trim( (string) $name );

		$cache_key = $name . '|' . $member_id;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$details = self::agent_details( $member_id );
		$email   = $details['email_address'];

		if ( '' === $email ) {
			PMS_Logger::info( sprintf(
				'No email address on the PropertyMe staff profile for "%s" — property left without an agent. Add the email in PropertyMe, then re-sync.',
				'' !== $name ? $name : 'member ' . $member_id
			) );
			$cache[ $cache_key ] = 0;
			return 0;
		}

		global $wpdb;

		// ACF stores this group's values as serialised single-item arrays, so
		// match with LIKE rather than equality.
		$agent_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'additional_fields_email_address'
			 WHERE p.post_type = 'agents' AND p.post_status IN ('publish','draft') AND m.meta_value LIKE %s
			 ORDER BY p.ID ASC LIMIT 1",
			'%' . $wpdb->esc_like( $email ) . '%'
		) );

		if ( $agent_id && '' !== $name && get_the_title( $agent_id ) !== $name ) {
			PMS_Logger::info( sprintf(
				'Agent "%s" matched by email (%s) to the existing agent "%s" — the names differ between PropertyMe and WordPress.',
				$name,
				$email,
				get_the_title( $agent_id )
			) );
		}

		if ( ! $agent_id ) {
			$new_id = wp_insert_post( array(
				'post_type'   => 'agents',
				'post_status' => 'publish',
				'post_title'  => '' !== $name ? $name : $email,
			), true );
			if ( is_wp_error( $new_id ) ) {
				PMS_Logger::error( 'Could not create agent post for "' . $name . '": ' . $new_id->get_error_message() );
				$cache[ $cache_key ] = 0;
				return 0;
			}
			$agent_id = (int) $new_id;
			self::set_agent_placeholder_image( $agent_id );
			PMS_Logger::info( sprintf(
				'New agent "%s" (%s) found in PropertyMe — agent post created. Upload their photo under Agents in wp-admin; PropertyMe\'s API cannot supply one.',
				$name,
				$email
			) );
		}

		// Fill blanks on new and existing agents alike; manual entries are kept.
		self::fill_agent_fields( $agent_id, $details );

		$cache[ $cache_key ] = $agent_id;
		return $agent_id;
	}

	/** Scalar-to-string cast that refuses booleans/arrays (e.g. DisplayAddress). */
	private static function str( $value ) {
		return ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) ? trim( (string) $value ) : '';
	}

	/**
	 * Lot descriptions come from the CRM's rich-text editor wrapped in <div>
	 * and <span> tags carrying inline font/colour styles, which would drag the
	 * CRM's typography onto the site. Keep the text and its paragraph and list
	 * structure, drop the styling.
	 */
	private static function clean_description( $value ) {
		$html = self::str( $value );
		if ( '' === $html ) {
			return '';
		}

		// The editor uses block tags as line breaks; turn every block boundary
		// into a newline before tags are stripped, so paragraphs and bullet
		// lists survive instead of running into one another.
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = preg_replace( '#</(div|p|li|h[1-6]|tr)\s*>#i', "\n", $html );
		$html = preg_replace( '#<(div|p|li|h[1-6]|tr)\b[^>]*>#i', "\n", $html );
		$html = wp_strip_all_tags( $html );
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Some CRM entries arrive as one unbroken run of text — the line breaks
		// were lost in PropertyMe's editor, not here. Re-break at the markers
		// the copy itself uses so it doesn't render as a single wall of text:
		// a bullet run-on ("dishwasher- Two separate"), and a heading that
		// follows the end of a sentence ("living.WHAT LOOP LOVES:").
		if ( false === strpos( $html, "\n" ) ) {
			$html = preg_replace( '/(?<=[a-z0-9):.])\s*-\s+(?=[A-Z])/', "\n- ", $html );
			$html = preg_replace( '/(?<=[a-z0-9)])\.(?=[A-Z]{2,})/', ".\n\n", $html );
		}

		// Collapse the runs of blank lines the editor leaves behind.
		$html = preg_replace( "/[ \t]+\n/", "\n", $html );
		$html = preg_replace( "/\n{3,}/", "\n\n", $html );

		return trim( $html );
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
		// Description: the CRM copy is the fallback. Where a listing has been
		// published, REAXML carries the marketing ad copy — richer, and what
		// goes to the portals — so never overwrite that with the CRM text.
		if ( '' !== $row['description'] && ! get_post_meta( $post_id, '_pms_rea_unique_id', true ) ) {
			self::set_field_meta( $post_id, 'description', $row['description'] );
		}
		self::set_field_meta( $post_id, 'property_type', $row['property_type'] );
		// property_agent is an ACF post_object field → store the matching
		// agents-CPT post ID, not the name string.
		$raw          = json_decode( (string) ( $row['raw'] ?? '' ), true );
		$manager_id   = is_array( $raw ) ? (string) ( $raw['ActiveManagerMemberId'] ?? '' ) : '';
		$agent_post_id = self::find_agent( $row['agent_name'], $manager_id );
		if ( $agent_post_id ) {
			self::set_field_meta( $post_id, 'property_agent', (string) $agent_post_id );
		}
		// Prefer the manager's address from /v1/members; fall back to whatever
		// the agent post already holds (someone may have typed it in wp-admin).
		$agent_email = self::agent_details( $manager_id )['email_address'];
		if ( '' === $agent_email && $agent_post_id ) {
			$stored      = get_post_meta( $agent_post_id, 'additional_fields_email_address', true );
			$agent_email = is_array( $stored ) ? (string) reset( $stored ) : (string) $stored;
		}
		self::set_field_meta( $post_id, 'property_agent_email', $agent_email );

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
	 * The Elementor layout to clone onto new property posts.
	 *
	 * Preference order:
	 *   1. the template chosen in Settings → PropertyMe Sync
	 *   2. the legacy pms_layout_template_post option (pre-1.1 installs)
	 *   3. any existing property post that already has a layout
	 *
	 * Step 3 means a deleted or re-created template can't silently leave new
	 * properties unstyled; nothing is hardcoded to a specific post id.
	 *
	 * @return int Template/post id, or 0 when no usable layout exists.
	 */
	public static function layout_template_id() {
		$candidates = array();

		$s = pms_get_settings();
		if ( ! empty( $s['layout_template'] ) ) {
			$candidates[] = (int) $s['layout_template'];
		}
		$legacy = (int) get_option( 'pms_layout_template_post', 0 );
		if ( $legacy ) {
			$candidates[] = $legacy;
		}

		foreach ( $candidates as $id ) {
			$layout = get_post_meta( $id, '_elementor_data', true );
			if ( $layout && is_string( $layout ) && get_post_status( $id ) ) {
				return $id;
			}
		}

		// Last resort: an already-synced property that carries a layout.
		global $wpdb;
		$found = (int) $wpdb->get_var(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_elementor_data' AND d.meta_value != ''
			 INNER JOIN {$wpdb->postmeta} l ON l.post_id = p.ID AND l.meta_key = '_pms_lot_id'
			 WHERE p.post_type = 'post' AND p.post_status = 'publish'
			 ORDER BY p.ID ASC LIMIT 1"
		);
		return $found;
	}

	/**
	 * Is an Elementor Theme Builder "single" template configured to render
	 * property posts? If so, per-post layouts are unnecessary.
	 *
	 * Checked against the stored Theme Builder conditions rather than by
	 * running Elementor's resolver, which needs a full front-end query loop
	 * that isn't available during a cron sync.
	 *
	 * @param int $post_id Property post the template would apply to.
	 */
	public static function has_theme_builder_template( $post_id ) {
		$conditions = get_option( 'elementor_pro_theme_builder_conditions', array() );
		if ( empty( $conditions['single'] ) || ! is_array( $conditions['single'] ) ) {
			return false;
		}

		$categories = wp_get_post_categories( $post_id );

		foreach ( $conditions['single'] as $template_id => $rules ) {
			if ( ! get_post_meta( $template_id, '_elementor_data', true ) || 'publish' !== get_post_status( $template_id ) ) {
				continue;
			}
			foreach ( (array) $rules as $rule ) {
				// Rules look like include/singular/post, include/singular/post/category/101,
				// include/singular/post/<id>; exclusions are ignored deliberately —
				// a false negative only means a harmless per-post clone.
				if ( 0 !== strpos( $rule, 'include/singular/post' ) ) {
					continue;
				}
				$suffix = trim( substr( $rule, strlen( 'include/singular/post' ) ), '/' );
				if ( '' === $suffix ) {
					return true; // all posts
				}
				if ( (string) $post_id === $suffix ) {
					return true; // this exact post
				}
				if ( 0 === strpos( $suffix, 'category/' ) ) {
					$term_id = (int) substr( $suffix, strlen( 'category/' ) );
					if ( $term_id && in_array( $term_id, $categories, true ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/** Elementor saved templates + property posts that can act as a layout source. */
	public static function layout_template_choices() {
		$choices = array();

		// Only full-page template types make sense as a detail-page layout —
		// headers, popups, widgets and sections would render nonsense.
		$page_types = array( 'page', 'single', 'single-post', 'single-page', 'wp-page', 'wp-post' );
		foreach ( get_posts( array(
			'post_type'      => 'elementor_library',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) ) as $tpl ) {
			$type = get_post_meta( $tpl->ID, '_elementor_template_type', true );
			if ( ! in_array( $type, $page_types, true ) ) {
				continue;
			}
			if ( get_post_meta( $tpl->ID, '_elementor_data', true ) ) {
				$choices[ $tpl->ID ] = 'Template: ' . $tpl->post_title . ( $type ? ' (' . $type . ')' : '' );
			}
		}

		global $wpdb;
		$property_ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_elementor_data' AND d.meta_value != ''
			 WHERE p.post_type = 'post' AND p.post_status = 'publish'
			 ORDER BY p.post_title ASC LIMIT 100"
		);
		foreach ( $property_ids as $pid ) {
			$choices[ (int) $pid ] = 'Property page: ' . get_the_title( $pid );
		}

		return $choices;
	}

	/**
	 * Give a new property post its detail-page design.
	 *
	 * Preferred setup is an Elementor Theme Builder "Single" template whose
	 * display condition covers property posts: Elementor then renders every
	 * property from that one template, so nothing is copied per post and a
	 * design change reaches all properties at once. When such a template is
	 * active this does nothing.
	 *
	 * The per-post clone below is the fallback for sites without one (or if
	 * the condition is ever removed), so properties are never left unstyled.
	 * Posts that already carry their own layout are never overwritten.
	 */
	private static function ensure_elementor_layout( $post_id ) {
		if ( get_post_meta( $post_id, '_elementor_data', true ) ) {
			return;
		}
		if ( self::has_theme_builder_template( $post_id ) ) {
			return;
		}
		$template_id = self::layout_template_id();
		if ( ! $template_id ) {
			PMS_Logger::error( 'No detail-page template is available — layout not applied to post ' . $post_id . '. Choose one in Settings → PropertyMe Sync.' );
			return;
		}
		$layout = get_post_meta( $template_id, '_elementor_data', true );
		if ( ! $layout || ! is_string( $layout ) ) {
			PMS_Logger::error( 'Detail-page template ' . $template_id . ' has no Elementor data — layout not applied to post ' . $post_id . '.' );
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
