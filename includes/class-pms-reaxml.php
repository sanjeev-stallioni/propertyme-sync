<?php
defined( 'ABSPATH' ) || exit;

/**
 * REA XML importer (realestate.com.au agent-feed format).
 *
 * PropertyMe publishes listing feeds as REAXML files — <propertyList>
 * containing <rental> elements with address, description, features,
 * inspection times, <images><img url=|file=> and <objects><floorplan>.
 * Files are read from a configurable drop directory (Settings → PropertyMe
 * Sync), matched to synced property posts, and moved to processed/
 * afterwards. Photos referenced by file= are resolved next to the XML.
 *
 * On live, PropertyMe's FTP delivers into the SITE ROOT — a public,
 * web-served directory holding WordPress core files. So the importer:
 *   - only considers files matching PropertyMe's {uuid}-{timestamp}.xml name
 *   - re-checks each file really is a <propertyList> before importing
 *   - moves the file OUT of the public root before parsing, so listing data
 *     stops being publicly downloadable at the first opportunity
 *   - parses with external entities disabled (XXE-safe)
 *
 * Listing → post matching order:
 *   1. _pms_rea_unique_id meta from an earlier import
 *   2. uniqueID equals a PropertyMe lot id in wp_pms_properties
 *   3. address built from unit/streetNumber/street (street type expanded)
 *      matched against post slug/title
 */
class PMS_REAXML {

	/**
	 * PropertyMe names each delivery {listingGuid}-{unixTimestamp}.xml. Used to
	 * pick our files out of a directory that may hold unrelated XML (the site
	 * root does). Kept permissive on the id part: any hex/dash id, 8+ chars.
	 */
	const FILENAME_PATTERN = '/^[0-9a-f][0-9a-f-]{7,}-\d{6,}\.xml$/i';

	/**
	 * Absolute path of the drop directory, resolved from the saved setting.
	 *
	 * The stored value is relative to ABSPATH ('' = site root) so the same
	 * settings work on local and live. Anything resolving outside the WP
	 * install is refused — an admin-only field, but there is no legitimate
	 * reason to read feeds from elsewhere on the filesystem.
	 *
	 * @param bool $create Create the directory when it does not exist yet.
	 * @return string Absolute path (trailing slash stripped), or '' if invalid.
	 */
	public static function feed_dir( $create = true ) {
		$s   = pms_get_settings();
		$rel = isset( $s['feed_dir'] ) ? (string) $s['feed_dir'] : '';
		$dir = self::resolve_dir( $rel, $create );
		return (string) apply_filters( 'pms_reaxml_dir', $dir );
	}

	/**
	 * Validate a relative drop-directory path and return it absolute.
	 * Shared by the importer and the settings-page validation.
	 *
	 * @return string Absolute path, or '' when the path is invalid/outside WP.
	 */
	public static function resolve_dir( $rel, $create = false ) {
		$rel = str_replace( '\\', '/', trim( (string) $rel ) );
		$rel = trim( $rel, '/' );

		// Reject traversal outright rather than relying on realpath() alone,
		// so a non-existent path can't be probed either.
		if ( '' !== $rel && ( false !== strpos( $rel, '..' ) || preg_match( '#^[a-zA-Z]:|^/#', $rel ) ) ) {
			return '';
		}

		$root = realpath( ABSPATH );
		if ( ! $root ) {
			return '';
		}
		$target = '' === $rel ? $root : $root . '/' . $rel;

		if ( $create && ! is_dir( $target ) ) {
			wp_mkdir_p( $target );
		}

		$real = realpath( $target );
		if ( ! $real || ! is_dir( $real ) ) {
			return '';
		}
		// Must stay inside the WordPress install (symlinks resolved).
		$root_cmp = trailingslashit( str_replace( '\\', '/', $root ) );
		$real_cmp = trailingslashit( str_replace( '\\', '/', $real ) );
		if ( 0 !== strpos( $real_cmp, $root_cmp ) ) {
			return '';
		}
		return untrailingslashit( $real );
	}

	/** Import every PropertyMe XML in the feed directory. Per-file counts. */
	public static function import_dir() {
		$dir = self::feed_dir();
		if ( '' === $dir ) {
			PMS_Logger::error( 'REAXML: drop directory is not valid — check Settings → PropertyMe Sync.' );
			return array();
		}

		$files = self::find_feed_files( $dir );
		if ( ! $files ) {
			PMS_Logger::info( 'REAXML: no files found in ' . $dir );
			return array();
		}

		$results = array();
		foreach ( $files as $file ) {
			// Move out of the (possibly public) drop directory FIRST, then
			// import from the archived copy: listing data spends as little
			// time as possible publicly downloadable, and a mid-import
			// failure can't leave the file to be processed twice.
			$staged = self::archive( $file );
			if ( '' === $staged ) {
				PMS_Logger::error( 'REAXML: could not move ' . basename( $file ) . ' out of the drop directory — skipped.' );
				continue;
			}
			$results[ basename( $file ) ] = self::import_file( $staged );
		}
		if ( $results ) {
			PMS_Logger::info( 'REAXML import finished.', $results );
		}
		return $results;
	}

	/**
	 * PropertyMe-looking XML files in $dir, verified to be REAXML.
	 *
	 * The drop directory on live is the site root, so both checks matter:
	 * the filename pattern keeps us away from unrelated XML, and the content
	 * sniff makes sure a coincidental name is not treated as a listing feed.
	 */
	private static function find_feed_files( $dir ) {
		$found = array();
		foreach ( (array) glob( $dir . '/*.[xX][mM][lL]' ) as $path ) {
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			if ( ! preg_match( self::FILENAME_PATTERN, basename( $path ) ) ) {
				continue;
			}
			if ( ! self::looks_like_reaxml( $path ) ) {
				PMS_Logger::info( 'REAXML: ignoring ' . basename( $path ) . ' — not a PropertyMe listing feed.' );
				continue;
			}
			$found[] = $path;
		}
		sort( $found ); // filenames end in a timestamp: oldest first
		return $found;
	}

	/** Cheap content sniff: <propertyList> root with a listing inside. */
	private static function looks_like_reaxml( $path ) {
		$head = (string) file_get_contents( $path, false, null, 0, 4096 );
		return false !== stripos( $head, '<propertyList' )
			&& preg_match( '/<(rental|residential|commercial|land|rural)\b/i', $head );
	}

	public static function import_file( $path ) {
		libxml_use_internal_errors( true );
		// Files arrive over FTP into a public directory — parse with network
		// access off and entity substitution left disabled (XXE-safe).
		$xml = simplexml_load_file( $path, 'SimpleXMLElement', LIBXML_NONET );
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

	/**
	 * Split REAXML inspection strings into the site's two display fields.
	 *
	 * Input looks like "13-Aug-2026 4:00pm to 4:15pm" (one entry per open
	 * home). The theme shows a date heading and a separate times line, in the
	 * style the site's manual posts use: "WED 3 DEC" / "2:30pm - 3:00pm".
	 *
	 * The earliest upcoming inspection wins the date heading; its time range
	 * goes to the times field. Anything unparseable falls back to the raw
	 * string in the times field so nothing is silently dropped.
	 *
	 * @param string[] $raw Inspection strings from the feed.
	 * @return array{date:string,times:string}
	 */
	public static function split_inspections( array $raw ) {
		$parsed = array();
		foreach ( $raw as $entry ) {
			// "13-Aug-2026 4:00pm to 4:15pm"
			if ( ! preg_match( '/^\s*(\d{1,2}-[A-Za-z]{3}-\d{4})\s+(.+?)\s*$/', $entry, $m ) ) {
				continue;
			}
			$stamp = strtotime( $m[1] );
			if ( ! $stamp ) {
				continue;
			}
			$parsed[] = array(
				'stamp' => $stamp,
				'times' => str_ireplace( ' to ', ' - ', trim( $m[2] ) ),
			);
		}

		if ( ! $parsed ) {
			// Unrecognised format: keep the feed's text rather than lose it.
			return array( 'date' => '', 'times' => implode( ', ', $raw ) );
		}

		usort( $parsed, static function ( $a, $b ) {
			return $a['stamp'] <=> $b['stamp'];
		} );

		// Prefer the next inspection that hasn't happened yet.
		$today = (int) current_time( 'timestamp' );
		$next  = $parsed[0];
		foreach ( $parsed as $candidate ) {
			if ( $candidate['stamp'] >= strtotime( 'today', $today ) ) {
				$next = $candidate;
				break;
			}
		}

		// Australian day-month-year: "WED 3 DEC 2026". The year is included so
		// an older listing's inspection date is never read as this year's.
		$date = strtoupper( gmdate( 'D j M Y', $next['stamp'] ) );

		// Same-day extra sessions are appended to the times line.
		$same_day = array();
		foreach ( $parsed as $candidate ) {
			if ( gmdate( 'Y-m-d', $candidate['stamp'] ) === gmdate( 'Y-m-d', $next['stamp'] ) ) {
				$same_day[] = $candidate['times'];
			}
		}

		return array(
			'date'  => $date,
			'times' => implode( ', ', array_unique( $same_day ) ),
		);
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
		//
		// The feed is third-party content and its description may carry markup
		// (REAXML wraps ad copy in CDATA, so tags survive the parser intact).
		// The ACF field is a textarea, which ACF renders WITHOUT escaping, so
		// anything stored here reaches the page as live HTML. Run it through
		// the same stripper the API copy uses, which keeps the paragraph
		// breaks and drops every tag.
		$description = PMS_Sync::clean_description( (string) $listing->description );
		if ( '' !== $description ) {
			PMS_Sync::set_field_meta( $post_id, 'description', $description );
		}
		if ( isset( $listing->inspectionTimes->inspection ) ) {
			$raw = array();
			foreach ( $listing->inspectionTimes->inspection as $inspection ) {
				$t = trim( (string) $inspection );
				if ( '' !== $t ) {
					$raw[] = $t;
				}
			}
			if ( $raw ) {
				// The site shows inspections as a date heading + a times line
				// ("WED 3 DEC" / "2:30pm - 3:00pm"), so split the REAXML
				// "13-Aug-2026 4:00pm to 4:15pm" strings into those two fields.
				$parsed = self::split_inspections( $raw );
				PMS_Sync::set_field_meta( $post_id, 'inspection_date', $parsed['date'] );
				PMS_Sync::set_field_meta( $post_id, 'inspection_times', $parsed['times'] );
				// REAXML has no inspection summary, and any summary already on
				// the page came from the API describing a different event, so
				// it is cleared — the page falls back to its default line.
				PMS_Sync::set_field_meta( $post_id, 'inspection_description', '', true );
				// Mark the advertised times as feed-owned so the API sync,
				// which covers every property, does not overwrite them.
				update_post_meta( $post_id, '_pms_rea_inspection', 1 );
			}
		}

		// Photos: <img id="m|a|b..." url=|file= modTime=>, main first. PropertyMe
		// delivers them inside <objects>; <images> kept for older feed variants.
		$attachment_ids = array();
		foreach ( array( $listing->objects, $listing->images ) as $container ) {
			if ( ! isset( $container->img ) ) {
				continue;
			}
			foreach ( $container->img as $img ) {
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

	/**
	 * Archive directory for imported feeds: always under uploads, never the
	 * drop directory itself — which on live is the public site root. Kept out
	 * of the web with .htaccess + an index stub (uploads is world-readable).
	 */
	public static function processed_dir() {
		$uploads   = wp_upload_dir();
		$processed = trailingslashit( $uploads['basedir'] ) . 'propertyme-feed/processed';
		if ( ! is_dir( $processed ) ) {
			wp_mkdir_p( $processed );
		}
		$htaccess = trailingslashit( $processed ) . '.htaccess';
		if ( is_dir( $processed ) && ! file_exists( $htaccess ) ) {
			// Apache 2.2 and 2.4 syntaxes; harmless where mod_authz is absent.
			@file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
			@file_put_contents( trailingslashit( $processed ) . 'index.php', "<?php // Silence is golden.\n" );
		}
		return $processed;
	}

	/**
	 * Move an imported feed file out of the drop directory.
	 *
	 * @return string New absolute path, or '' if the move failed.
	 */
	private static function archive( $file ) {
		$processed = self::processed_dir();
		if ( ! is_dir( $processed ) ) {
			return '';
		}
		$target = trailingslashit( $processed ) . gmdate( 'Ymd-His' ) . '-' . basename( $file );
		// Same-second deliveries of different files must not overwrite.
		$i = 1;
		while ( file_exists( $target ) ) {
			$target = trailingslashit( $processed ) . gmdate( 'Ymd-His' ) . '-' . $i . '-' . basename( $file );
			$i++;
		}
		return @rename( $file, $target ) ? $target : '';
	}
}
