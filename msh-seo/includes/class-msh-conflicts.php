<?php
/**
 * Stand down when another SEO plugin is already doing the job.
 *
 * This plugin writes titles, descriptions, Open Graph tags, canonicals and
 * JSON-LD into wp_head at priorities 1 and 2, and replaces the core sitemap.
 * It did all of that unconditionally. Install it alongside Yoast or Rank Math
 * and the page gets two of everything: two titles, two descriptions, two
 * schema blocks, two canonicals, two sitemaps.
 *
 * For an SEO plugin that is the worst possible first impression. The user's
 * search results genuinely get worse, they cannot tell which plugin did it,
 * and the review writes itself. It is also the single most likely thing to
 * happen on a WordPress.org install, because most sites that want an SEO
 * plugin already have one.
 *
 * So the rule is: whoever was there first owns the output.
 *
 * What stands down is ONLY the part that collides — meta tags, schema,
 * breadcrumbs and the sitemap. Everything that makes this plugin different
 * keeps running, because none of it competes: redirects, the 404 log and its
 * automatic repair, the health checks, the AI tools. A user with Yoast still
 * gets working broken-link repair, which is the reason to install this
 * alongside rather than instead.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MSH_Conflicts {

	/**
	 * Other SEO plugins that own wp_head output.
	 *
	 * Detected by class, constant or function rather than by plugin file,
	 * because is_plugin_active() is only reliable inside wp-admin and this
	 * decision has to be made on every front-end request too.
	 *
	 * @var array<string, array{label:string, check:string, type:string}>
	 */
	private static function registry() {
		return array(
			'wordpress-seo' => array(
				'label' => 'Yoast SEO',
				'check' => 'WPSEO_VERSION',
				'type'  => 'constant',
			),
			'seo-by-rank-math' => array(
				'label' => 'Rank Math SEO',
				'check' => 'RANK_MATH_VERSION',
				'type'  => 'constant',
			),
			'all-in-one-seo-pack' => array(
				'label' => 'All in One SEO',
				'check' => 'AIOSEO_VERSION',
				'type'  => 'constant',
			),
			'wp-seopress' => array(
				'label' => 'SEOPress',
				'check' => 'SEOPRESS_VERSION',
				'type'  => 'constant',
			),
			'autodescription' => array(
				'label' => 'The SEO Framework',
				'check' => 'THE_SEO_FRAMEWORK_VERSION',
				'type'  => 'constant',
			),
			'slim-seo' => array(
				'label' => 'Slim SEO',
				'check' => 'SlimSEO\Plugin',
				'type'  => 'class',
			),
		);
	}

	/** @var array<int,string>|null Memoised, so wp_head does not re-scan per tag. */
	private static $detected = null;

	/**
	 * Names of the conflicting SEO plugins that are active. Empty when clear.
	 *
	 * @return array<int,string>
	 */
	public static function detected() {
		if ( null !== self::$detected ) {
			return self::$detected;
		}

		$found = array();
		foreach ( self::registry() as $entry ) {
			$hit = false;
			if ( 'constant' === $entry['type'] ) {
				$hit = defined( $entry['check'] );
			} elseif ( 'class' === $entry['type'] ) {
				$hit = class_exists( $entry['check'] );
			} elseif ( 'function' === $entry['type'] ) {
				$hit = function_exists( $entry['check'] );
			}
			if ( $hit ) {
				$found[] = $entry['label'];
			}
		}

		self::$detected = $found;
		return $found;
	}

	/** Is another SEO plugin already writing head output? */
	public static function has_conflict() {
		return count( self::detected() ) > 0;
	}

	/**
	 * Should this plugin emit head/sitemap output?
	 *
	 * Filterable so a user who genuinely wants both can say so — some people
	 * run one plugin for schema and another for sitemaps deliberately, and
	 * refusing to let them is its own support ticket.
	 */
	public static function should_output() {
		$allow = ! self::has_conflict();

		/**
		 * Filter whether MSH SEO writes meta tags, schema and sitemaps.
		 *
		 * @param bool  $allow    False when another SEO plugin was detected.
		 * @param array $detected Labels of the plugins found.
		 */
		return (bool) apply_filters( 'msh_seo_should_output_meta', $allow, self::detected() );
	}

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Tell the user what happened, once, where they will see it.
	 *
	 * Silence here is worse than the conflict: the user installs the plugin,
	 * sees no new meta tags, and concludes it is broken.
	 */
	public static function notice() {
		if ( ! self::has_conflict() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false === strpos( (string) $screen->id, 'msh-seo' ) && 'plugins' !== $screen->id && 'dashboard' !== $screen->id ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), 'msh_seo_conflict_dismissed', true ) ) {
			return;
		}

		$names = implode( ', ', self::detected() );
		echo '<div class="notice notice-info is-dismissible"><p><strong>MSH SEO</strong> — ';
		printf(
			/* translators: %s: names of the other SEO plugins detected. */
			esc_html__( '%s is already handling your meta tags, schema and sitemap, so MSH SEO has left those alone to avoid duplicates.', 'msh-seo' ),
			'<strong>' . esc_html( $names ) . '</strong>'
		);
		echo ' ';
		esc_html_e( 'Everything else still runs: broken-link repair, the 404 log, health checks and the AI tools.', 'msh-seo' );
		echo '</p></div>';
	}
}
