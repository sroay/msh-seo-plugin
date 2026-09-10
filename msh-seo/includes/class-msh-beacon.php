<?php
/**
 * MSH Health Beacon — the plugin's daily report on its own condition.
 *
 * The redirect engine was dead from the day this plugin shipped. A dbDelta key
 * collision meant wp_msh_redirects was never created, so every redirect 404'd
 * for the life of the plugin, and 11,306 dead hits accumulated before anyone
 * looked. The dashboard could see the SITE was up the whole time. Nothing
 * anywhere asked the plugin whether its own parts worked.
 *
 * So this deliberately does NOT report what is installed or enabled. "Installed"
 * was true for a year while the thing was broken. It reports what it could just
 * now verify:
 *
 *   - the redirect table by asking the database whether it exists and holds rows
 *   - the sitemap and llms.txt by actually fetching them over HTTP
 *   - the site's own indexing switch, which is one click away from invisible
 *
 * A subsystem it cannot verify is omitted rather than reported green. An
 * unverified "ok" is what let the original bug hide.
 *
 * Cost: three loopback requests and two COUNT queries, once a day. It has to be
 * something a customer's shared host runs forever without anyone noticing.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MSH_Beacon {

	const CRON_HOOK = 'msh_seo_health_beacon';

	/**
	 * Loopback requests must not hang a cron run on a slow or firewalled host.
	 * A timed-out probe reports as unknown, never as a failure — a network blip
	 * on the customer's box is not a broken subsystem.
	 */
	const PROBE_TIMEOUT = 10;

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( 'wp_ajax_msh_beacon_run', array( __CLASS__, 'ajax_run' ) );
	}

	/**
	 * Schedule on init rather than activation only.
	 *
	 * Activation hooks do not fire on a plugin UPDATE, so a job added in a later
	 * release would never start on the sites that already have the plugin —
	 * which is every site that matters. Same reasoning as the schema repair.
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Offset from the hour so a fleet of sites does not all report at
			// once and look like a traffic spike to the dashboard.
			wp_schedule_event( time() + ( 10 * MINUTE_IN_SECONDS ), 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/* ------------------------------------------------------------------
	 * Gathering
	 * ----------------------------------------------------------------*/

	/**
	 * Ask the database what actually exists, and count what is falling through.
	 *
	 * The second number is the one that matters. A table can exist and still be
	 * useless: rules absent while hits keep landing is the same failure the
	 * missing table caused, and it reads healthier from every other angle.
	 */
	private static function redirect_state() {
		global $wpdb;

		$redirects = $wpdb->prefix . 'msh_redirects';
		$log       = $wpdb->prefix . 'msh_404_log';

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $redirects ) );
		if ( $found !== $redirects ) {
			return array(
				'table_exists'       => false,
				'rule_count'         => 0,
				'unmatched_404s_24h' => 0,
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- name from $wpdb->prefix
		$rules = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$redirects}" );

		$unmatched = 0;
		$log_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log ) );
		if ( $log_found === $log ) {
			// Hits in the last day that no rule would have caught. The join is
			// the whole point: a raw 404 count says nothing, because a 404 the
			// redirect engine successfully handles never reaches the log.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- names from $wpdb->prefix
			$unmatched = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(l.hits), 0)
					   FROM {$log} l
					   LEFT JOIN {$redirects} r ON r.source_url = l.url
					  WHERE r.id IS NULL AND l.last_hit >= %s",
					gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
				)
			);
		}

		return array(
			'table_exists'       => true,
			'rule_count'         => $rules,
			'unmatched_404s_24h' => $unmatched,
		);
	}

	/**
	 * The scheme and host with no path — where root-level files actually live.
	 *
	 * robots.txt, llms.txt and sitemap.xml are only ever fetched from the domain
	 * root. On a subfolder install home_url() is https://example.com/blog, and
	 * probing home_url('/llms.txt') asks for /blog/llms.txt — a URL no crawler
	 * requests and which 404s while the real file at the root is fine. That
	 * false alarm fired on the first two sites this ran against.
	 */
	private static function origin() {
		$parts = wp_parse_url( home_url() );
		if ( empty( $parts['host'] ) ) {
			return home_url();
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		return $scheme . '://' . $parts['host'] . $port;
	}

	/**
	 * Fetch one of our own URLs and decide whether the answer is real.
	 *
	 * Returns 'ok', 'error', or null when the probe itself could not run —
	 * null is dropped by the caller rather than reported, because a host that
	 * blocks loopback requests is not a broken sitemap.
	 *
	 * @param string $path     Path relative to the site origin.
	 * @param array  $contains Substrings, ANY of which proves the body is real.
	 * @return string|null
	 */
	private static function probe( $path, $contains = array() ) {
		$response = wp_remote_get(
			self::origin() . $path,
			array(
				'timeout'    => self::PROBE_TIMEOUT,
				'redirection' => 3,
				'user-agent' => 'MSH-SEO-WP/' . MSH_SEO_VERSION . ' (self-check)',
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 500 ) {
			// The server erred on its own URL. That is a fault, not an
			// unreachable probe.
			return 'error';
		}
		if ( $code !== 200 ) {
			return 'error';
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === trim( (string) $body ) ) {
			return 'error';
		}

		if ( ! empty( $contains ) ) {
			// A 200 that serves the wrong thing — a themed 404 page, usually —
			// is the failure mode a status-code check alone would call healthy.
			//
			// ANY of the markers counts. Requiring one exact tag is what made
			// this call believele.com's perfectly good sitemap broken: it is a
			// sitemap INDEX, so it holds <sitemapindex> and not <urlset>.
			$matched = false;
			foreach ( (array) $contains as $needle ) {
				if ( false !== strpos( $body, $needle ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				return 'error';
			}
		}

		return 'ok';
	}

	/**
	 * Build the report.
	 */
	public static function collect() {
		global $wp_version;

		$subsystems = array();

		$redirects = self::redirect_state();
		$subsystems['redirects'] = $redirects['table_exists'] ? 'ok' : 'error';

		// WordPress core serves /wp-sitemap.xml; SEO plugins and headless setups
		// usually move it to /sitemap.xml. Either counts, and either may be an
		// index or a flat urlset — the question is whether crawlers can reach
		// ONE valid sitemap, not which file or which shape.
		$markers = array( '<urlset', '<sitemapindex', '<url>', '<sitemap>' );
		$sitemap = self::probe( '/sitemap.xml', $markers );
		if ( 'ok' !== $sitemap ) {
			$core = self::probe( '/wp-sitemap.xml', $markers );
			// Only let the fallback overwrite a real verdict when it reached the
			// server; a null here means we learned nothing new.
			if ( null !== $core ) {
				$sitemap = $core;
			}
		}
		if ( null !== $sitemap ) {
			$subsystems['sitemap'] = $sitemap;
		}

		$ai_files = self::probe( '/llms.txt' );
		if ( null !== $ai_files ) {
			$subsystems['ai_files'] = $ai_files;
		}

		// One checkbox in Settings → Reading makes an entire site invisible to
		// search, and nothing in WordPress warns you afterwards. It is the
		// cheapest catastrophic fault there is to check.
		$subsystems['indexing'] = get_option( 'blog_public' ) ? 'ok' : 'error';

		return array(
			'plugin_version' => MSH_SEO_VERSION,
			'wp_version'     => $wp_version,
			'php_version'    => PHP_VERSION,
			'site_url'       => home_url(),
			// Which Google Analytics tag this site currently prints, so the
			// dashboard can see delivery worked without crawling the page.
			'tracking'       => MSH_Tracking::report(),
			'subsystems'     => $subsystems,
			'redirects'      => $redirects,
			'cron'           => array(
				'last_run' => get_option( 'msh_beacon_last_run', null ),
			),
			// Everything MSH needs to work out where the dead URLs should point.
			// Sent with the report rather than fetched afterwards, because a
			// great many WordPress sites are unreachable from outside — behind
			// a firewall, a login wall, or a host that blocks the REST API —
			// and an inbound endpoint would quietly work for us and fail for
			// half the customers.
			'dead_urls'      => self::unmatched_404s(),
			'posts'          => self::published_urls(),
			'existing_rules' => self::existing_rules(),
			'verified_paths' => self::verified_paths(),
			'published_checks' => self::published_reachability(),
		);
	}

	/**
	 * A referrer, with anything identifying removed.
	 *
	 * Only the origin and path are kept. A query string on a referring URL can
	 * carry a session token, an email address or a reset link, and none of that
	 * is needed to answer "where did this link come from" -- so it is dropped
	 * here, on the customer's own server, rather than sent and filtered later.
	 */
	private static function clean_referrer( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$parts = wp_parse_url( $raw );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '';
		return substr( $scheme . '://' . $parts['host'] . $path, 0, 160 );
	}

	/**
	 * A user agent, shortened to the part that identifies the CLIENT.
	 *
	 * The question is only ever "browser or bot". A full UA string is long,
	 * highly identifying in combination with other fields, and adds nothing to
	 * that answer, so a known bot reports its own name and everything else
	 * collapses to the browser family.
	 */
	private static function clean_agent( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$bots = array(
			'Googlebot', 'bingbot', 'GPTBot', 'ClaudeBot', 'PerplexityBot', 'CCBot',
			'AhrefsBot', 'SemrushBot', 'DotBot', 'MJ12bot', 'YandexBot', 'Baiduspider',
			'Applebot', 'facebookexternalhit', 'Bytespider', 'PetalBot', 'DataForSeoBot',
		);
		foreach ( $bots as $bot ) {
			if ( false !== stripos( $raw, $bot ) ) {
				return $bot;
			}
		}
		// Anything self-describing as a library or crawler is not a reader.
		if ( preg_match( '/(bot|crawler|spider|scrapy|curl|wget|python-requests|httpclient|okhttp|java\/)/i', $raw ) ) {
			return 'other-bot';
		}
		foreach ( array( 'Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari' ) as $needle => $name ) {
			if ( false !== stripos( $raw, $needle ) ) {
				return $name;
			}
		}
		return 'other';
	}

	/** Published items probed per run. Small enough for shared hosting daily. */
	const MAX_REACHABILITY_PROBES = 8;

	/**
	 * Can a reader actually open what this site published?
	 *
	 * Nothing asked that until now. On technobelieve.com twelve published pages
	 * had been returning 404 to visitors for months while serving perfectly
	 * inside WordPress — every existing check answered a different question (is
	 * the site up, is the sitemap valid, is the plugin working) and all of them
	 * said yes.
	 *
	 * It is not one site's problem either. A headless front end, a theme change,
	 * a permalink change, a security plugin or a cache layer can each produce it
	 * on any install, silently.
	 *
	 * ROTATES through the catalogue rather than re-checking the same handful.
	 * A fixed sample can never find the fault in item nine, and on a 400-post
	 * site that is most of the content. At 8 a day a 400-item site is fully
	 * covered in under two months, and the offset survives in an option.
	 */
	private static function published_reachability() {
		$total = 0;
		foreach ( array( 'post', 'page' ) as $type ) {
			$counts = wp_count_posts( $type );
			$total += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
		if ( 0 === $total ) {
			return array();
		}

		$offset = (int) get_option( 'msh_beacon_reach_offset', 0 );
		if ( $offset >= $total ) {
			$offset = 0;
		}

		$items = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'numberposts'      => self::MAX_REACHABILITY_PROBES,
				'offset'           => $offset,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		update_option( 'msh_beacon_reach_offset', $offset + count( $items ), false );

		$out = array();
		foreach ( $items as $item ) {
			$url = get_permalink( $item );
			if ( ! $url ) {
				continue;
			}
			$res = wp_remote_head(
				$url,
				array(
					'timeout'     => self::PROBE_TIMEOUT,
					// Do NOT follow. A 301 to a working page is fine, and
					// following would hide a redirect chain that ends in a 404
					// behind whatever the last hop happened to return.
					'redirection' => 0,
					'user-agent'  => 'MSH-SEO-WP/' . MSH_SEO_VERSION . ' (self-check)',
				)
			);
			if ( is_wp_error( $res ) ) {
				// Unreachable probe, not a missing page. Reported as 0 so the
				// dashboard counts it as unverified rather than gone.
				$out[] = array( 'url' => $url, 'code' => 0 );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			// A redirect is only healthy if it lands somewhere real, so follow
			// this one by hand and report where it ended up.
			if ( $code >= 300 && $code < 400 ) {
				$loc = wp_remote_retrieve_header( $res, 'location' );
				if ( is_array( $loc ) ) {
					$loc = end( $loc );
				}
				// A Location header may be RELATIVE — "/contact" — which is
				// legal and is what Next.js sends. Passing that straight to
				// wp_remote_head fails with "A valid URL was not provided",
				// and every probe came back 0: not a page fault, just an
				// unusable check. Resolve it against the origin first.
				if ( $loc && '/' === substr( $loc, 0, 1 ) && '//' !== substr( $loc, 0, 2 ) ) {
					$loc = self::origin() . $loc;
				}
				if ( $loc && preg_match( '#^https?://#i', $loc ) ) {
					$hop  = wp_remote_head( $loc, array( 'timeout' => self::PROBE_TIMEOUT, 'redirection' => 3 ) );
					$code = is_wp_error( $hop ) ? 0 : (int) wp_remote_retrieve_response_code( $hop );
				} else {
					// Nowhere to follow. Report the redirect itself rather than
					// inventing a verdict about where it might have gone.
					$code = 0;
				}
			}
			$out[] = array( 'url' => $url, 'code' => $code );
		}

		return $out;
	}

	/**
	 * Which of the dead URLs' own paths answer at the site ORIGIN.
	 *
	 * The commonest broken link on a WordPress blog is one built against the
	 * wrong base URL: /contact 404s under /blog while /contact answers on the
	 * main site. The page is not missing, only the host is wrong — but MSH
	 * cannot know which of those paths are real without asking, and a redirect
	 * to a second invented URL is not a repair.
	 *
	 * So this asks, and reports only what answered 200. Derived from the dead
	 * URLs themselves rather than a fixed list of likely page names: it stays
	 * correct for a site whose pages we have never heard of, and it costs a
	 * probe only for paths something actually requested.
	 */
	private static function verified_paths() {
		$candidates = array();

		foreach ( self::unmatched_404s() as $d ) {
			$path = wp_parse_url( $d['url'], PHP_URL_PATH );
			if ( ! $path ) {
				continue;
			}
			$segments = array_values( array_filter( explode( '/', $path ) ) );
			if ( empty( $segments ) ) {
				continue;
			}
			// The blog prefix is routing, not the page name.
			if ( 'blog' === $segments[0] ) {
				array_shift( $segments );
			}
			if ( empty( $segments ) ) {
				continue;
			}
			$name = $segments[0];

			// A file request is a missing file, not a page on another host.
			if ( false !== strpos( $name, '.' ) ) {
				continue;
			}
			// Never propose an admin or system path as somewhere to send a
			// visitor. /wp-admin answers 200 — it serves a login form — so it
			// passes the probe and would sit in this list looking legitimate.
			// Nothing downstream can currently choose it, and that is exactly
			// the kind of safety that quietly stops being true one rule change
			// later.
			if ( in_array( $name, array( 'wp-admin', 'wp-login', 'wp-json', 'admin', 'login', 'wp-content', 'wp-includes' ), true ) ) {
				continue;
			}
			// Long slugs are articles; the matcher handles those from the post
			// list, and probing every one of them would be a crawl.
			if ( strlen( $name ) > 24 || substr_count( $name, '-' ) > 2 ) {
				continue;
			}

			$candidates[ $name ] = true;
			if ( count( $candidates ) >= self::MAX_PATH_PROBES ) {
				break;
			}
		}

		$live = array();
		foreach ( array_keys( $candidates ) as $name ) {
			if ( 'ok' === self::probe( '/' . $name ) ) {
				$live[] = self::origin() . '/' . $name;
			}
		}
		return $live;
	}

	/** Ceiling on the daily probe budget — this runs on shared hosting. */
	const MAX_PATH_PROBES = 12;

	/**
	 * How many dead URLs to send. The plan caps rules per run well below this,
	 * so the extra is headroom for entries that turn out unfixable rather than
	 * a promise to fix them all at once.
	 */
	const MAX_DEAD_URLS = 200;

	/** Guard against a huge site turning a daily report into a megabyte upload. */
	const MAX_POSTS = 500;

	/**
	 * 404s from the last 30 days that no rule covers, worst first.
	 *
	 * A wider window than the beacon's own 24h summary on purpose: the summary
	 * answers "is it getting worse", while this answers "what is broken", and a
	 * page that broke last week is just as broken today.
	 */
	private static function unmatched_404s() {
		global $wpdb;
		$log       = $wpdb->prefix . 'msh_404_log';
		$redirects = $wpdb->prefix . 'msh_redirects';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log ) ) !== $log ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- names from $wpdb->prefix
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.url, l.hits, l.referrer, l.user_agent, l.last_hit
				   FROM {$log} l
				   LEFT JOIN {$redirects} r ON r.source_url = l.url
				  WHERE r.id IS NULL AND l.last_hit >= %s
			   ORDER BY l.hits DESC
				  LIMIT %d",
				gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) ),
				self::MAX_DEAD_URLS
			),
			ARRAY_A
		);

		return array_map(
			static function ( $r ) {
				return array(
					'url'      => $r['url'],
					'hits'     => (int) $r['hits'],
					// Who asked. The log has recorded both since the first
					// release and nothing ever forwarded them, so every
					// escalated URL arrived with no way to tell a reader
					// following a broken link from a scanner probing paths --
					// which is the whole difference between "build this page"
					// and "leave it 404ing".
					'referrer' => self::clean_referrer( $r['referrer'] ),
					'agent'    => self::clean_agent( $r['user_agent'] ),
					// WHEN it was last asked for. `hits` is a lifetime total
					// filtered only by this field, so without it a URL hit 122
					// times last month and never since is indistinguishable
					// from one being hit right now -- and the queue is ordered
					// by that number.
					'last_hit' => $r['last_hit'] ? gmdate( 'c', strtotime( $r['last_hit'] ) ) : null,
				);
			},
			$rows ? $rows : array()
		);
	}

	/**
	 * Every published URL, so the matcher is choosing among pages that exist.
	 *
	 * Pages as well as posts: half the dead links on a real site point at
	 * /contact or /pricing, and a posts-only list cannot repair those.
	 */
	private static function published_urls() {
		$items = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'numberposts'      => self::MAX_POSTS,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);

		$out = array();
		foreach ( $items as $p ) {
			$out[] = array(
				'slug'  => $p->post_name,
				'title' => $p->post_title,
				'url'   => get_permalink( $p ),
			);
		}
		return $out;
	}

	/**
	 * Existing rules, so MSH never overwrites one and never builds a chain.
	 */
	private static function existing_rules() {
		global $wpdb;
		$table = $wpdb->prefix . 'msh_redirects';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- name from $wpdb->prefix
		$rows = $wpdb->get_results( "SELECT source_url, target_url, note FROM {$table} LIMIT 2000", ARRAY_A );
		return $rows ? $rows : array();
	}

	/**
	 * Write the repairs MSH sent back.
	 *
	 * Deliberately INSERT-only on a source that does not already exist. The
	 * planner is supposed to have excluded existing sources, but the plugin is
	 * the last thing standing before a live site and must not depend on that:
	 * a race, a stale payload, or a bug upstream must never be able to silently
	 * replace a rule somebody wrote by hand.
	 *
	 * @param array $rules Rules from the dashboard.
	 * @return array Counts of what happened.
	 */
	private static function apply_redirects( $rules ) {
		global $wpdb;

		$applied = 0;
		$skipped = 0;

		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return array( 'applied' => 0, 'skipped' => 0 );
		}

		// A schema check first: the note column is what makes these revertible,
		// and writing rules without it would produce exactly the unattributable
		// state this design exists to avoid.
		MSH_Redirects::ensure_schema();

		$table = $wpdb->prefix . 'msh_redirects';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array( 'applied' => 0, 'skipped' => count( $rules ), 'error' => 'no redirect table' );
		}

		foreach ( $rules as $rule ) {
			$source = isset( $rule['source'] ) ? esc_url_raw( $rule['source'] ) : '';
			$target = isset( $rule['target'] ) ? esc_url_raw( $rule['target'] ) : '';
			$type   = isset( $rule['type'] ) ? (int) $rule['type'] : 301;
			$note   = isset( $rule['note'] ) ? substr( sanitize_text_field( $rule['note'] ), 0, 64 ) : '';

			if ( '' === $source || '' === $target || $source === $target ) {
				$skipped++;
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- name from $wpdb->prefix
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_url = %s", $source ) );
			if ( $exists ) {
				$skipped++;
				continue;
			}

			$ok = $wpdb->insert(
				$table,
				array(
					'source_url'    => $source,
					'target_url'    => $target,
					'redirect_type' => in_array( $type, array( 301, 302, 307, 308 ), true ) ? $type : 301,
					'note'          => $note,
				),
				array( '%s', '%s', '%d', '%s' )
			);

			if ( $ok ) {
				$applied++;
			} else {
				$skipped++;
			}
		}

		return array( 'applied' => $applied, 'skipped' => $skipped );
	}

	/* ------------------------------------------------------------------
	 * Sending
	 * ----------------------------------------------------------------*/

	public static function run() {
		$api_key = MSH_Auth::get_key();
		if ( empty( $api_key ) ) {
			return null;
		}

		$payload = self::collect();

		$response = wp_remote_post(
			MSH_API::BASE_URL . '/beacon',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
					'User-Agent'    => 'MSH-SEO-WP/' . MSH_SEO_VERSION,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[MSH Beacon] Send failed: ' . $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$msg = isset( $body['error'] ) ? $body['error'] : 'HTTP ' . $code;
			error_log( '[MSH Beacon] Rejected: ' . $msg );
			return new WP_Error( 'beacon_rejected', $msg );
		}

		update_option( 'msh_beacon_last_run', current_time( 'c' ), false );

		// The Google Analytics measurement id the dashboard wants on this site.
		// Third delivery channel after the direct push and the /verify reply —
		// the one that needs nothing but this daily round trip.
		if ( isset( $body['analytics'] ) && is_array( $body['analytics'] ) ) {
			MSH_Tracking::absorb( $body['analytics'] );
		}

		// Apply the repairs MSH worked out from the report we just sent. The
		// round trip is the whole design: it needs no inbound connectivity, and
		// tomorrow's report re-measures the same 404s, so a repair that did not
		// actually work shows up on its own rather than being assumed good.
		$healed = array( 'applied' => 0, 'skipped' => 0 );
		if ( ! empty( $body['redirects'] ) ) {
			$healed = self::apply_redirects( $body['redirects'] );
			if ( $healed['applied'] > 0 ) {
				$log   = get_option( 'msh_beacon_heal_log', array() );
				$log[] = array(
					'at'      => current_time( 'c' ),
					'applied' => $healed['applied'],
					'skipped' => $healed['skipped'],
				);
				update_option( 'msh_beacon_heal_log', array_slice( $log, -30 ), false );
			}
		}

		// Keep the dashboard's verdict locally so the WordPress admin screen can
		// show the same answer, rather than a second opinion derived here.
		if ( isset( $body['status'] ) ) {
			update_option(
				'msh_beacon_last_result',
				array(
					'status' => $body['status'],
					'faults' => isset( $body['faults'] ) ? $body['faults'] : array(),
					'healed' => $healed,
					'at'     => current_time( 'c' ),
				),
				false
			);
		}

		return array_merge( is_array( $body ) ? $body : array(), array( 'healed' => $healed ) );
	}

	/**
	 * Undo every rule this system created, leaving hand-written ones untouched.
	 *
	 * The counterpart to writing unattended. Without a one-command revert,
	 * "it can fix itself" is a promise nobody can safely accept.
	 *
	 * @return int Rules removed.
	 */
	public static function revert_auto_redirects() {
		global $wpdb;
		$table = $wpdb->prefix . 'msh_redirects';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		return (int) $wpdb->delete( $table, array( 'note' => 'msh-auto-heal' ), array( '%s' ) );
	}

	/**
	 * Run on demand from the admin screen — the only way to confirm a fix
	 * without waiting a day for the next scheduled report.
	 */
	public static function ajax_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
		}
		check_ajax_referer( 'msh_beacon_run' );

		$result = self::run();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		if ( null === $result ) {
			wp_send_json_error( array( 'message' => 'Plugin is not connected to MSH yet.' ) );
		}

		wp_send_json_success( $result );
	}
}
