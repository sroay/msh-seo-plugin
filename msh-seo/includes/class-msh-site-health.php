<?php
/**
 * Answer "is this plugin working?" inside WordPress, where people already look.
 *
 * The beacon already computes all of this — whether the redirect table exists,
 * how many 404s are landing uncaught, whether another SEO plugin has taken
 * over. Until now it sent that exclusively to Marketing So High. The person
 * whose site it is could not see any of it.
 *
 * That asymmetry is a support queue waiting to happen: the user has a question
 * the plugin can already answer, no way to ask it, and a support forum right
 * there. Tools > Site Health is where WordPress trained everyone to look, and
 * the Info tab is the first thing any support reply asks them to paste.
 *
 * Every check reads state the plugin already holds. Nothing here calls out to
 * the network — Site Health runs on page load and has to stay fast.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MSH_Site_Health {

	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
	}

	public static function register_tests( $tests ) {
		$tests['direct']['msh_seo_connection'] = array(
			'label' => __( 'MSH SEO connection', 'msh-seo' ),
			'test'  => array( __CLASS__, 'test_connection' ),
		);
		$tests['direct']['msh_seo_redirects'] = array(
			'label' => __( 'MSH SEO redirect engine', 'msh-seo' ),
			'test'  => array( __CLASS__, 'test_redirects' ),
		);
		$tests['direct']['msh_seo_conflict'] = array(
			'label' => __( 'MSH SEO plugin conflicts', 'msh-seo' ),
			'test'  => array( __CLASS__, 'test_conflict' ),
		);
		return $tests;
	}

	private static function result( $label, $status, $colour, $body, $actions = '' ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'SEO', 'msh-seo' ),
				'color' => $colour,
			),
			'description' => '<p>' . $body . '</p>',
			'actions'     => $actions,
			'test'        => 'msh_seo',
		);
	}

	/**
	 * Not being connected is not a fault. The local features work without an
	 * account, and reporting a deliberate choice as critical is how a Site
	 * Health panel teaches people to ignore it.
	 */
	public static function test_connection() {
		if ( MSH_Auth::is_connected() ) {
			return self::result(
				__( 'MSH SEO is connected', 'msh-seo' ),
				'good',
				'blue',
				esc_html__( 'AI features are available and this site reports its health daily.', 'msh-seo' )
			);
		}
		return self::result(
			__( 'MSH SEO is not connected', 'msh-seo' ),
			'recommended',
			'gray',
			esc_html__( 'The local SEO features are all working. Connecting a free Marketing So High account additionally enables the AI tools and automatic broken-link repair.', 'msh-seo' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=msh-seo' ) ) . '">' . esc_html__( 'Open MSH SEO settings', 'msh-seo' ) . '</a>'
		);
	}

	/**
	 * The check that would have caught the year the redirect engine was dead.
	 * A dbDelta quirk meant the table was never created, every redirect 404'd,
	 * and nothing anywhere said so.
	 */
	public static function test_redirects() {
		global $wpdb;
		$table  = $wpdb->prefix . 'msh_redirects';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

		if ( ! $exists ) {
			return self::result(
				__( 'The redirect table is missing', 'msh-seo' ),
				'critical',
				'red',
				esc_html__( 'Redirects cannot work because their database table does not exist. Deactivating and reactivating MSH SEO rebuilds it.', 'msh-seo' )
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- name from $wpdb->prefix
		$rules    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$log      = $wpdb->prefix . 'msh_404_log';
		$uncaught = 0;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log ) ) === $log ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- names from $wpdb->prefix
			$uncaught = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(l.hits),0) FROM {$log} l
					   LEFT JOIN {$table} r ON r.source_url = l.url
					  WHERE r.id IS NULL AND l.last_hit >= %s",
					gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
				)
			);
		}

		if ( $uncaught > 20 ) {
			return self::result(
				__( 'Visitors are hitting broken links', 'msh-seo' ),
				'recommended',
				'orange',
				sprintf(
					/* translators: 1: number of hits. 2: number of redirect rules. */
					esc_html__( '%1$d requests in the last day hit a page that does not exist, and no redirect caught them. You have %2$d redirect rules. Connecting MSH SEO lets it repair these automatically.', 'msh-seo' ),
					$uncaught,
					$rules
				)
			);
		}

		return self::result(
			__( 'The redirect engine is working', 'msh-seo' ),
			'good',
			'blue',
			sprintf(
				/* translators: %d: number of redirect rules. */
				esc_html__( '%d redirect rules are active, and no significant broken-link traffic was seen in the last day.', 'msh-seo' ),
				$rules
			)
		);
	}

	public static function test_conflict() {
		if ( ! MSH_Conflicts::has_conflict() ) {
			return self::result(
				__( 'No SEO plugin conflicts', 'msh-seo' ),
				'good',
				'blue',
				esc_html__( 'MSH SEO is the only SEO plugin writing meta tags, schema and sitemaps on this site.', 'msh-seo' )
			);
		}
		return self::result(
			__( 'Another SEO plugin is handling your meta tags', 'msh-seo' ),
			'recommended',
			'gray',
			sprintf(
				/* translators: %s: names of the other SEO plugins found. */
				esc_html__( '%s is active, so MSH SEO has stepped back from meta tags, schema and sitemaps to avoid duplicating them. Its redirects, broken-link repair, health checks and AI tools all still run.', 'msh-seo' ),
				esc_html( implode( ', ', MSH_Conflicts::detected() ) )
			)
		);
	}

	/**
	 * The Info tab. This is what a support reply asks the user to paste, so it
	 * should already contain every question that reply would have to ask.
	 */
	public static function debug_information( $info ) {
		global $wpdb;

		$last  = get_option( 'msh_beacon_last_result', array() );
		$table = $wpdb->prefix . 'msh_redirects';
		$next  = wp_next_scheduled( 'msh_seo_health_beacon' );

		$info['msh-seo'] = array(
			'label'  => __( 'MSH SEO', 'msh-seo' ),
			'fields' => array(
				'version'        => array(
					'label' => __( 'Version', 'msh-seo' ),
					'value' => MSH_SEO_VERSION,
				),
				'connected'      => array(
					'label' => __( 'Connected to Marketing So High', 'msh-seo' ),
					'value' => MSH_Auth::is_connected() ? __( 'Yes', 'msh-seo' ) : __( 'No', 'msh-seo' ),
				),
				'conflicts'      => array(
					'label' => __( 'Other SEO plugins detected', 'msh-seo' ),
					'value' => MSH_Conflicts::has_conflict()
						? implode( ', ', MSH_Conflicts::detected() )
						: __( 'None', 'msh-seo' ),
				),
				'redirect_table' => array(
					'label' => __( 'Redirect table', 'msh-seo' ),
					'value' => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table
						? __( 'Present', 'msh-seo' )
						: __( 'MISSING', 'msh-seo' ),
				),
				'last_health'    => array(
					'label' => __( 'Last health report', 'msh-seo' ),
					'value' => isset( $last['at'] )
						? $last['at'] . ' (' . ( isset( $last['status'] ) ? $last['status'] : '?' ) . ')'
						: __( 'Never sent', 'msh-seo' ),
				),
				'health_faults'  => array(
					'label' => __( 'Reported faults', 'msh-seo' ),
					'value' => ! empty( $last['faults'] )
						? implode( ' | ', (array) $last['faults'] )
						: __( 'None', 'msh-seo' ),
				),
				'beacon_next'    => array(
					'label' => __( 'Next health report', 'msh-seo' ),
					'value' => $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : __( 'Not scheduled', 'msh-seo' ),
				),
			),
		);

		return $info;
	}
}
