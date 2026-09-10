<?php
/**
 * MSH Author Box
 *
 * Says who wrote the page, in the page. Search engines and answer engines
 * weigh who is speaking; the schema already names the founder, but a reader
 * (and a crawler reading the visible page) saw a bare byline. Prints a small
 * box after the article with the founder's name, role, a one-line bio and
 * profile links, all delivered by the MSH dashboard and stored in options.
 *
 * Nothing is printed until the dashboard has delivered a founder name, so a
 * site that has not told MSH who it is shows nothing rather than a blank box.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MSH_Author_Box {

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'maybe_append' ), 21 );
		add_action( 'wp_head', array( __CLASS__, 'inline_css' ) );
		add_shortcode( 'msh_author_box', array( __CLASS__, 'render' ) );
	}

	/**
	 * Founder fields as delivered by the dashboard (see MSH_Auth::verify_connection).
	 *
	 * @return array|null name, job_title, bio, same_as[] — or null when unknown.
	 */
	private static function founder() {
		$f = get_option( 'msh_seo_founder', array() );
		if ( ! is_array( $f ) || empty( $f['name'] ) ) {
			return null;
		}
		$links = array();
		if ( ! empty( $f['same_as'] ) && is_array( $f['same_as'] ) ) {
			foreach ( $f['same_as'] as $u ) {
				$u = esc_url_raw( (string) $u );
				if ( $u && 0 === strpos( $u, 'https://' ) ) {
					$links[] = $u;
				}
			}
		}
		return array(
			'name'      => (string) $f['name'],
			'job_title' => isset( $f['job_title'] ) ? (string) $f['job_title'] : '',
			'bio'       => isset( $f['bio'] ) ? (string) $f['bio'] : '',
			'same_as'   => $links,
		);
	}

	/** A readable label for a profile URL: "LinkedIn", "X", "GitHub", else the host. */
	private static function label_for( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );
		$known = array(
			'linkedin.com'  => 'LinkedIn',
			'twitter.com'   => 'X',
			'x.com'         => 'X',
			'github.com'    => 'GitHub',
			'youtube.com'   => 'YouTube',
			'instagram.com' => 'Instagram',
			'threads.net'   => 'Threads',
			'facebook.com'  => 'Facebook',
		);
		return isset( $known[ $host ] ) ? $known[ $host ] : $host;
	}

	public static function render() {
		$f = self::founder();
		if ( ! $f ) {
			return '';
		}
		$site = get_bloginfo( 'name' );
		ob_start();
		?>
		<aside class="msh-author" aria-label="About the author">
			<p class="msh-author__name"><?php echo esc_html( $f['name'] ); ?><?php if ( $f['job_title'] ) : ?> <span class="msh-author__role">&middot; <?php echo esc_html( $f['job_title'] ); ?>, <?php echo esc_html( $site ); ?></span><?php endif; ?></p>
			<?php if ( $f['bio'] ) : ?>
			<p class="msh-author__bio"><?php echo esc_html( $f['bio'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $f['same_as'] ) ) : ?>
			<p class="msh-author__links">
				<?php foreach ( $f['same_as'] as $i => $u ) : ?>
					<?php if ( $i > 0 ) : ?> &middot; <?php endif; ?><a href="<?php echo esc_url( $u ); ?>" rel="me noopener" target="_blank"><?php echo esc_html( self::label_for( $u ) ); ?></a>
				<?php endforeach; ?>
			</p>
			<?php endif; ?>
		</aside>
		<?php
		return trim( ob_get_clean() );
	}

	/**
	 * Append after single posts unless disabled or already placed by hand.
	 */
	public static function maybe_append( $content ) {
		if ( is_admin() || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( ! get_option( 'msh_seo_author_box_enabled', true ) ) {
			return $content;
		}
		if ( false !== strpos( $content, 'msh-author' ) || false !== strpos( $content, '[msh_author_box]' ) ) {
			return $content;
		}
		$box = self::render();
		return $box ? $content . "\n" . $box : $content;
	}

	public static function inline_css() {
		if ( ! is_singular( 'post' ) || ! self::founder() ) {
			return;
		}
		echo '<style id="msh-author-css">.msh-author{margin:2.5em 0 1em;padding:1.1em 1.25em;border:1px solid rgba(128,128,128,.25);border-radius:8px;font-size:.95em;line-height:1.5}.msh-author__name{margin:0;font-weight:600}.msh-author__role{font-weight:400;opacity:.75}.msh-author__bio{margin:.4em 0 0}.msh-author__links{margin:.5em 0 0;opacity:.85}</style>' . "\n";
	}
}
