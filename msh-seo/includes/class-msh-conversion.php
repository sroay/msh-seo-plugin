<?php
/**
 * MSH Conversion — intent-personalized CTAs + conversion tracking.
 *
 * Renders a call-to-action (shortcode [msh_cta], a block, or auto-appended to
 * posts) whose message adapts to the visitor's intent — classified client-side
 * from referrer, UTM params and returning status. Impression/click events are
 * streamed back to the MSH brain, which aggregates per post + intent so the
 * content engine learns which topics CONVERT, not just rank. No standalone SEO
 * plugin closes this content → rank → convert loop.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Conversion {

    /**
     * Wire up shortcode, block, tracking route, assets and auto-inject.
     */
    public static function init() {
        add_shortcode( 'msh_cta', array( __CLASS__, 'render_cta' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_filter( 'the_content', array( __CLASS__, 'maybe_autoinject' ), 20 );
        add_action( 'init', array( __CLASS__, 'register_block' ) );
    }

    /**
     * Register a lightweight dynamic block that renders the same CTA.
     */
    public static function register_block() {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }
        register_block_type( 'msh-seo/cta', array(
            'render_callback' => array( __CLASS__, 'render_cta' ),
            'attributes'      => array(),
        ) );
    }

    /**
     * Fetch the CTA config from MSH (cached). Returns null when unavailable.
     *
     * @return array|null { variants: array<string,array>, target_url: string }
     */
    private static function get_config() {
        // Front-end hot path: gate on the stored key only — never on
        // is_connected(), whose self-healing verify is admin-only and must
        // not run (or flap) during a visitor's page render. cta_config()
        // itself is transient-cached with a 4s timeout + negative caching.
        if ( ! class_exists( 'MSH_Auth' ) || ! MSH_Auth::get_key() ) {
            return null;
        }
        $res = MSH_API::cta_config();
        if ( is_wp_error( $res ) || empty( $res['config']['variants'] ) || empty( $res['target_url'] ) ) {
            return null;
        }
        return array(
            'variants'   => $res['config']['variants'],
            'target_url' => $res['target_url'],
        );
    }

    /**
     * Render the CTA. SSR renders the "default" variant (works without JS + for
     * crawlers); the client script swaps in the intent-matched variant.
     *
     * @return string HTML, or '' when no config.
     */
    public static function render_cta( $atts = array() ) {
        $config = self::get_config();
        if ( ! $config ) {
            return '';
        }

        $variants = $config['variants'];
        $default  = isset( $variants['default'] ) ? $variants['default'] : reset( $variants );
        if ( empty( $default ) ) {
            return '';
        }

        $headline = isset( $default['headline'] ) ? $default['headline'] : '';
        $body     = isset( $default['body'] ) ? $default['body'] : '';
        $label    = isset( $default['button_label'] ) ? $default['button_label'] : 'Learn more';
        $url      = isset( $default['button_url'] ) ? $default['button_url'] : $config['target_url'];

        $variants_json = wp_json_encode( $variants );

        ob_start();
        ?>
        <div class="msh-cta" data-msh-cta data-variants="<?php echo esc_attr( $variants_json ); ?>">
            <div class="msh-cta__inner">
                <h3 class="msh-cta__headline"><?php echo esc_html( $headline ); ?></h3>
                <p class="msh-cta__body"><?php echo esc_html( $body ); ?></p>
                <a class="msh-cta__btn" href="<?php echo esc_url( $url ); ?>" rel="nofollow noopener" data-msh-cta-btn><?php echo esc_html( $label ); ?></a>
            </div>
        </div>
        <?php
        return trim( ob_get_clean() );
    }

    /**
     * Auto-append the CTA to single posts (unless already present or disabled).
     */
    public static function maybe_autoinject( $content ) {
        if ( is_admin() || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }
        if ( ! get_option( 'msh_seo_cta_enabled', true ) ) {
            return $content;
        }
        // Don't double up if the author already placed a CTA.
        if ( false !== strpos( $content, 'msh-cta' ) || false !== strpos( $content, '[msh_cta]' ) ) {
            return $content;
        }
        $cta = self::render_cta();
        return $cta ? $content . $cta : $content;
    }

    /**
     * Enqueue the front-end tracking script + CTA styles on singular views.
     */
    public static function enqueue_assets() {
        if ( ! is_singular() ) {
            return;
        }

        wp_register_style( 'msh-cta', false, array(), MSH_SEO_VERSION );
        wp_enqueue_style( 'msh-cta' );
        wp_add_inline_style( 'msh-cta', self::inline_css() );

        wp_enqueue_script(
            'msh-cta',
            MSH_SEO_URL . 'assets/js/msh-cta.js',
            array(),
            MSH_SEO_VERSION,
            true
        );
        wp_localize_script( 'msh-cta', 'mshCta', array(
            'endpoint' => esc_url_raw( rest_url( 'msh-seo/v1/conversion-event' ) ),
        ) );
    }

    /**
     * Minimal on-brand CTA styles (MSH pink → orange).
     */
    private static function inline_css() {
        return '.msh-cta{margin:2.5em 0;border-radius:14px;padding:2px;background:linear-gradient(135deg,#ff5c8a,#ff9a3c)}'
            . '.msh-cta__inner{background:#fff;border-radius:12px;padding:24px;text-align:center}'
            . '.msh-cta__headline{margin:0 0 8px;font-size:1.35em;font-weight:700;line-height:1.25}'
            . '.msh-cta__body{margin:0 0 16px;color:#555;font-size:1em}'
            . '.msh-cta__btn{display:inline-block;background:linear-gradient(135deg,#ff5c8a,#ff9a3c);color:#fff !important;'
            . 'padding:12px 26px;border-radius:9999px;font-weight:600;text-decoration:none;transition:opacity .2s}'
            . '.msh-cta__btn:hover{opacity:.9;color:#fff !important}';
    }

    /**
     * Register the public conversion-event tracking route.
     */
    public static function register_routes() {
        register_rest_route( 'msh-seo/v1', '/conversion-event', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_conversion_event' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * REST: receive a CTA event from the browser and forward it to MSH.
     * Public route — protected by same-origin check + per-IP rate limiting.
     */
    public static function rest_conversion_event( WP_REST_Request $request ) {
        // Same-origin guard: reject only when an Origin is present AND mismatched.
        $origin    = $request->get_header( 'origin' );
        $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( $origin ) {
            $origin_host = wp_parse_url( $origin, PHP_URL_HOST );
            if ( $origin_host && $home_host && strcasecmp( $origin_host, $home_host ) !== 0 ) {
                return new WP_REST_Response( array( 'ok' => false ), 403 );
            }
        }

        // Light per-IP rate limit.
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
        $rl_key = 'msh_cta_rl_' . md5( $ip );
        $count  = (int) get_transient( $rl_key );
        if ( $count > 200 ) {
            return new WP_REST_Response( array( 'ok' => true ), 202 );
        }
        set_transient( $rl_key, $count + 1, 10 * MINUTE_IN_SECONDS );

        $params = $request->get_json_params();
        $type   = isset( $params['type'] ) ? sanitize_key( $params['type'] ) : '';
        $url    = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : '';
        $bucket = isset( $params['bucket'] ) ? sanitize_key( $params['bucket'] ) : 'default';

        if ( ! in_array( $type, array( 'impression', 'click', 'conversion' ), true ) || empty( $url ) ) {
            return new WP_REST_Response( array( 'ok' => false ), 400 );
        }

        $event = array( 'url' => $url, 'type' => $type, 'bucket' => $bucket );
        if ( isset( $params['value'] ) && is_numeric( $params['value'] ) ) {
            $event['value'] = (float) $params['value'];
        }

        MSH_API::conversion_event( array( $event ) );

        return new WP_REST_Response( array( 'ok' => true ), 202 );
    }
}
