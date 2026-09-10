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

class MSH_Newsletter {

	const ENDPOINT_OPTION = 'msh_seo_newsletter_endpoint';

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'maybe_append' ), 22 );
		add_action( 'wp_footer', array( __CLASS__, 'inline_assets' ) );
		add_shortcode( 'msh_newsletter', array( __CLASS__, 'render' ) );
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
		if ( false !== strpos( $content, 'msh-newsletter' ) || false !== strpos( $content, '[msh_newsletter]' ) ) {
			return $content;
		}
		$form = self::render();
		return $form ? $content . "\n" . $form : $content;
	}

	public static function inline_assets() {
		if ( ! is_singular( 'post' ) || ! self::endpoint() ) {
			return;
		}
		echo '<style id="msh-newsletter-css">.msh-newsletter{margin:1.5em 0;padding:1.1em 1.25em;border:1px solid rgba(128,128,128,.25);border-radius:8px}.msh-newsletter__headline{margin:0;font-weight:600}.msh-newsletter__body{margin:.3em 0 .8em;opacity:.8;font-size:.95em}.msh-newsletter__row{display:flex;gap:.5em;flex-wrap:wrap}.msh-newsletter__input{flex:1 1 220px;padding:.55em .7em;border:1px solid rgba(128,128,128,.4);border-radius:6px;font:inherit}.msh-newsletter__btn{padding:.55em 1em;border:0;border-radius:6px;background:#111;color:#fff;font:inherit;cursor:pointer}.msh-newsletter__note{margin:.5em 0 0;font-size:.85em;opacity:.7}</style>' . "\n";
		echo '<script id="msh-newsletter-js">(function(){var f=document.querySelector("[data-msh-newsletter]");if(!f)return;f.addEventListener("submit",function(e){e.preventDefault();var i=f.querySelector("input[type=email]"),n=f.querySelector("[data-msh-newsletter-note]"),b=f.querySelector("button");if(!i||!i.value)return;b.disabled=true;fetch(f.getAttribute("data-endpoint"),{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({email:i.value,source:f.getAttribute("data-source")})}).then(function(r){return r.json().catch(function(){return{}}).then(function(d){return{ok:r.ok,d:d}})}).then(function(x){n.textContent=x.ok?"Check your inbox and click the confirmation link.":(x.d&&x.d.error)||"That did not work. Please try again.";if(x.ok){i.value=""}b.disabled=false}).catch(function(){n.textContent="That did not work. Please try again.";b.disabled=false})})})();</script>' . "\n";
	}
}
