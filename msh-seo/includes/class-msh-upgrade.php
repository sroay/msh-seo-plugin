<?php
/**
 * One-time data migration to the msh_seo prefix.
 *
 * Until 1.5.0 the plugin stored data under a three-letter "msh_" prefix:
 * options, post meta, two database tables and several transients. The
 * WordPress.org guidelines ask for a distinct prefix of at least four
 * characters, so every name now starts with msh_seo_. Sites that already run
 * the plugin hold real data under the old names (redirect rules, the 404 log,
 * focus keywords, Autopilot settings), and renaming the code without moving
 * the data would silently lose all of it.
 *
 * This runs once per site, on the first request after the update, before
 * anything reads the new names. It is idempotent and safe to interrupt: every
 * step checks what is already there before touching it.
 *
 * @package MSH_SEO
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MSH_SEO_Upgrade {

	/**
	 * Set when the prefix migration has completed on this site.
	 *
	 * @var string
	 */
	const DONE_OPTION = 'msh_seo_prefix_migrated';

	/**
	 * Short lock so two concurrent first requests do not migrate twice.
	 *
	 * @var string
	 */
	const LOCK_OPTION = 'msh_seo_prefix_migration_lock';

	/**
	 * Options renamed in 1.5.0 (old => new).
	 *
	 * @var array
	 */
	const OPTIONS = array(
		'msh_autopilot_auto_ping'      => 'msh_seo_autopilot_auto_ping',
		'msh_autopilot_default_mode'   => 'msh_seo_autopilot_default_mode',
		'msh_autopilot_last_scan'      => 'msh_seo_autopilot_last_scan',
		'msh_autopilot_min_age_days'   => 'msh_seo_autopilot_min_age_days',
		'msh_autopilot_min_word_count' => 'msh_seo_autopilot_min_word_count',
		'msh_autopilot_scan_frequency' => 'msh_seo_autopilot_scan_frequency',
		'msh_beacon_heal_log'          => 'msh_seo_beacon_heal_log',
		'msh_beacon_last_result'       => 'msh_seo_beacon_last_result',
		'msh_beacon_last_run'          => 'msh_seo_beacon_last_run',
		'msh_beacon_reach_offset'      => 'msh_seo_beacon_reach_offset',
		'msh_crawler_settings'         => 'msh_seo_crawler_settings',
		'msh_freshness_stale_count'    => 'msh_seo_freshness_stale_count',
	);

	/**
	 * Post meta keys renamed in 1.5.0 (old => new).
	 *
	 * @var array
	 */
	const POST_META = array(
		'_msh_distributed_channels' => '_msh_seo_distributed_channels',
		'_msh_focus_keyword'        => '_msh_seo_focus_keyword',
		'_msh_freshness_checked'    => '_msh_seo_freshness_checked',
		'_msh_freshness_score'      => '_msh_seo_freshness_score',
		'_msh_og_image'             => '_msh_seo_og_image',
		'_msh_product_brand'        => '_msh_seo_product_brand',
		'_msh_product_gtin'         => '_msh_seo_product_gtin',
		'_msh_product_mpn'          => '_msh_seo_product_mpn',
		'_msh_schema_type'          => '_msh_seo_schema_type',
	);

	/**
	 * Table suffixes renamed in 1.5.0 (old => new), after $wpdb->prefix.
	 *
	 * @var array
	 */
	const TABLES = array(
		'msh_redirects' => 'msh_seo_redirects',
		'msh_404_log'   => 'msh_seo_404_log',
	);

	/**
	 * Cron recurrences renamed in 1.5.0 (old => new).
	 *
	 * @var array
	 */
	const SCHEDULES = array(
		'msh_weekly'   => 'msh_seo_weekly',
		'msh_biweekly' => 'msh_seo_biweekly',
	);

	/**
	 * Run the migration if this site has not had it yet.
	 *
	 * Cheap on every later request: one autoloaded option read.
	 *
	 * @return void
	 */
	public static function maybe_run() {
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}

		// add_option() fails when the row exists, which makes it an atomic
		// test-and-set. A lock older than five minutes belongs to a request
		// that died, so it is taken over.
		if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
			$started = (int) get_option( self::LOCK_OPTION );
			if ( $started > time() - 5 * MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( self::LOCK_OPTION, time(), false );
		}

		$report = array(
			'at'        => current_time( 'mysql', true ),
			'version'   => MSH_SEO_VERSION,
			'tables'    => self::migrate_tables(),
			'options'   => self::migrate_options(),
			'post_meta' => self::migrate_post_meta(),
			'cron'      => self::migrate_cron(),
		);
		self::delete_old_transients();

		// Query vars in stored rewrite rules changed name; WordPress rebuilds
		// the rules on the next request when the option is gone.
		delete_option( 'rewrite_rules' );

		update_option( self::DONE_OPTION, $report, false );
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Rename the redirect and 404 tables, keeping every row.
	 *
	 * @return array Per-table outcome.
	 */
	private static function migrate_tables() {
		global $wpdb;
		$result = array();

		foreach ( self::TABLES as $old_suffix => $new_suffix ) {
			$old = $wpdb->prefix . $old_suffix;
			$new = $wpdb->prefix . $new_suffix;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time schema migration.
			$has_old = $old === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) );
			if ( ! $has_old ) {
				$result[ $old_suffix ] = 'absent';
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time schema migration.
			$has_new = $new === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) );
			if ( $has_new ) {
				// A new table can exist only if something created it before this
				// ran, in which case it is empty. Never drop one that holds rows.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time schema migration.
				$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $new ) );
				if ( $rows > 0 ) {
					$result[ $old_suffix ] = 'kept both: new table already has ' . $rows . ' rows';
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time schema migration.
				$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $new ) );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time schema migration.
			$ok = false !== $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old, $new ) );
			$result[ $old_suffix ] = $ok ? 'renamed' : 'rename failed: ' . $wpdb->last_error;
		}

		return $result;
	}

	/**
	 * Copy each old option to its new name, keeping autoload, then delete the old one.
	 *
	 * @return array Names that moved.
	 */
	private static function migrate_options() {
		global $wpdb;
		$moved = array();

		foreach ( self::OPTIONS as $old => $new ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration; get_option() cannot report autoload.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $old ) );
			if ( ! $row ) {
				continue;
			}
			if ( false === get_option( $new ) ) {
				$autoload = in_array( $row->autoload, array( 'yes', 'on', 'auto-on', 'auto' ), true );
				add_option( $new, get_option( $old ), '', $autoload );
			}
			delete_option( $old );
			$moved[] = $old;
		}

		return $moved;
	}

	/**
	 * Rename post meta keys in place.
	 *
	 * If a post somehow already carries the new key, its old row is the stale
	 * one and is removed rather than duplicated.
	 *
	 * @return array Rows renamed per key.
	 */
	private static function migrate_post_meta() {
		global $wpdb;
		$counts = array();

		foreach ( self::POST_META as $old => $new ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration.
			$wpdb->query( $wpdb->prepare(
				"DELETE o FROM {$wpdb->postmeta} o INNER JOIN {$wpdb->postmeta} n ON n.post_id = o.post_id AND n.meta_key = %s WHERE o.meta_key = %s",
				$new,
				$old
			) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time migration.
			$renamed = $wpdb->update( $wpdb->postmeta, array( 'meta_key' => $new ), array( 'meta_key' => $old ) );
			if ( $renamed ) {
				$counts[ $old ] = (int) $renamed;
			}
		}

		if ( $counts ) {
			wp_cache_flush_group( 'post_meta' );
		}
		return $counts;
	}

	/**
	 * Move scheduled events off recurrence names that no longer exist.
	 *
	 * WordPress drops a recurring event whose recurrence is unregistered the
	 * next time it runs, so an Autopilot scan left on "msh_weekly" would
	 * quietly stop.
	 *
	 * @return array Hooks rescheduled.
	 */
	private static function migrate_cron() {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return array();
		}

		$moved = array();
		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				foreach ( $events as $event ) {
					if ( empty( $event['schedule'] ) || ! isset( self::SCHEDULES[ $event['schedule'] ] ) ) {
						continue;
					}
					$args = isset( $event['args'] ) ? $event['args'] : array();
					wp_unschedule_event( $timestamp, $hook, $args );
					wp_schedule_event( $timestamp, self::SCHEDULES[ $event['schedule'] ], $hook, $args );
					$moved[] = $hook;
				}
			}
		}

		return $moved;
	}

	/**
	 * Remove transients stored under the old prefix. They are caches and
	 * rebuild themselves under the new names.
	 *
	 * @return void
	 */
	private static function delete_old_transients() {
		global $wpdb;
		foreach ( array( '_transient_', '_transient_timeout_' ) as $kind ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration.
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
				$wpdb->esc_like( $kind . 'msh_' ) . '%',
				$wpdb->esc_like( $kind . 'msh_seo_' ) . '%'
			) );
		}
	}
}
