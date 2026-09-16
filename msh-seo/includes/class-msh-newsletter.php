<?php
/**
 * MSH Newsletter Signup
 *
 * A two-field signup form under every post, posting straight to the MSH
 * newsletter's public double-opt-in endpoint. The endpoint arrives from the
 * dashboard in the connection reply and is stored in an option; a site whose
 * organisation does not own a newsletter never receives one and renders
 * nothing. The visitor gets a confirmation email from MSH; nothing is
 * subscribed until they click it.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MSH_SEO_Newsletter {

	const ENDPOINT_OPTION = 'msh_seo_newsletter_endpoint';

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'maybe_append' ), 22 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_shortcode( 'msh_seo_newsletter', array( __CLASS__, 'render' ) );
	}

	private static function endpoint() {
		$e = (string) get_option( self::ENDPOINT_OPTION, '' );
		return ( $e && 0 === strpos( $e, 'https://' ) ) ? $e : '';
	}

	public static function render() {
		$endpoint = self::endpoint();
		if ( ! $endpoint ) {
			return '';
		}
		$headline = (string) get_option( 'msh_seo_newsletter_headline', 'Get one useful marketing idea a week' );
		$body     = (string) get_option( 'msh_seo_newsletter_body', 'A short weekly email on organic growth, written from what actually worked. No spam, one click to leave.' );
		$source   = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		// A shortcode placed outside single posts still needs the assets.
		self::enqueue();
		ob_start();
		?>
		<form class="msh-newsletter" data-msh-newsletter data-endpoint="<?php echo esc_url( $endpoint ); ?>" data-source="<?php echo esc_attr( $source ); ?>" method="post" action="<?php echo esc_url( $endpoint ); ?>">
			<p class="msh-newsletter__headline"><?php echo esc_html( $headline ); ?></p>
			<p class="msh-newsletter__body"><?php echo esc_html( $body ); ?></p>
			<div class="msh-newsletter__row">
				<label class="screen-reader-text" for="msh-newsletter-email">Email address</label>
				<input id="msh-newsletter-email" class="msh-newsletter__input" type="email" name="email" required placeholder="you@company.com" autocomplete="email" />
				<button class="msh-newsletter__btn" type="submit">Subscribe</button>
			</div>
			<p class="msh-newsletter__note" data-msh-newsletter-note>You will get one email asking you to confirm.</p>
		</form>
		<?php
		return trim( ob_get_clean() );
	}

	public static function maybe_append( $content ) {
		if ( is_admin() || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( ! get_option( 'msh_seo_newsletter_enabled', true ) ) {
			return $content;
		}
		if ( false !== strpos( $content, 'msh-newsletter' ) || false !== strpos( $content, '[msh_seo_newsletter]' ) ) {
			return $content;
		}
		$form = self::render();
		return $form ? $content . "\n" . $form : $content;
	}

	/**
	 * Load the form's assets on single posts, where the form is appended
	 * automatically.
	 */
	public static function enqueue_assets() {
		if ( ! is_singular( 'post' ) || ! self::endpoint() ) {
			return;
		}
		self::enqueue();
	}

	/**
	 * Styles in the head, script in the footer.
	 */
	private static function enqueue() {
		wp_enqueue_style( 'msh-seo-newsletter', MSH_SEO_URL . 'assets/css/newsletter.css', array(), MSH_SEO_VERSION );
		wp_enqueue_script( 'msh-seo-newsletter', MSH_SEO_URL . 'assets/js/newsletter.js', array(), MSH_SEO_VERSION, true );
	}
}
