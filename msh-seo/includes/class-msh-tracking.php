<?php
/**
 * MSH Tracking — the Google Analytics tag the MSH dashboard installs.
 *
 * A GA4 property never receives a visit by itself: the page has to carry the
 * property's measurement id (G-XXXXXXXXXX) in a gtag script. The dashboard
 * reads that id from the property's web data stream and delivers it here by
 * any of three routes — a direct REST push, the /verify reply, or the daily
 * beacon reply. All three land in one option and this class prints the
 * standard snippet in wp_head.
 *
 * Deliberately NOT gated behind the SEO-plugin conflict check: another
 * plugin owning the meta tags says nothing about whether Analytics should run.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Tracking {

    const OPTION  = 'msh_seo_ga_measurement_id';
    const PATTERN = '/^G-[A-Z0-9]{6,12}$/';

    public static function init() {
        // Priority 4: after MSH_Meta_Tags (1) and MSH_Schema (2), before the
        // theme's own head output, so the tag sits where Google's own
        // installer would put it.
        add_action( 'wp_head', array( __CLASS__, 'output' ), 4 );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
    }

    /** The installed measurement id, or '' when none (or a malformed one) is stored. */
    public static function get() {
        $v = get_option( self::OPTION, '' );
        return ( is_string( $v ) && preg_match( self::PATTERN, $v ) ) ? $v : '';
    }

    /** Store an id. Returns false — and stores nothing — for anything that is not a GA4 measurement id. */
    public static function set( $id ) {
        $id = strtoupper( trim( sanitize_text_field( (string) $id ) ) );
        if ( ! preg_match( self::PATTERN, $id ) ) {
            return false;
        }
        update_option( self::OPTION, $id );
        return true;
    }

    public static function clear() {
        delete_option( self::OPTION );
    }

    /**
     * Take the id out of a dashboard reply (the /verify body, or the beacon
     * reply's `analytics` block). Absent or empty means "leave things as they
     * are" — a reply that omits the id is not an instruction to remove it.
     */
    public static function absorb( $body ) {
        if ( ! is_array( $body ) ) {
            return;
        }
        if ( isset( $body['ga_measurement_id'] ) && is_string( $body['ga_measurement_id'] ) && '' !== $body['ga_measurement_id'] ) {
            self::set( $body['ga_measurement_id'] );
        }
    }

    /** What the beacon reports: what is installed right now. */
    public static function report() {
        $id = self::get();
        return array( 'ga_measurement_id' => '' === $id ? null : $id );
    }

    /**
     * The snippet. Same text as the dashboard's gtagSnippet(), which is what
     * the dashboard looks for on the live page to confirm the install.
     */
    public static function output() {
        if ( is_admin() || is_feed() || is_preview() ) {
            return;
        }
        if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
            return;
        }
        $id = self::get();
        if ( '' === $id ) {
            return;
        }
        echo "<!-- Google tag (gtag.js) — installed by MSH SEO -->\n";
        printf( "<script async src=\"https://www.googletagmanager.com/gtag/js?id=%s\"></script>\n", esc_attr( $id ) );
        printf(
            "<script>\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n  gtag('js', new Date());\n  gtag('config', '%s');\n</script>\n",
            esc_js( $id )
        );
    }

    /**
     * REST: the dashboard's direct push. Authenticated with a WordPress
     * application password (Basic auth) — the same credential it publishes
     * posts with.
     */
    public static function register_rest() {
        register_rest_route(
            'msh-seo/v1',
            '/analytics',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( __CLASS__, 'rest_get' ),
                    'permission_callback' => function () {
                        return current_user_can( 'manage_options' );
                    },
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( __CLASS__, 'rest_set' ),
                    'permission_callback' => function () {
                        return current_user_can( 'manage_options' );
                    },
                ),
            )
        );
    }

    public static function rest_get() {
        return rest_ensure_response( array_merge( self::report(), array( 'version' => MSH_SEO_VERSION ) ) );
    }

    public static function rest_set( WP_REST_Request $request ) {
        $params = $request->get_json_params();

        if ( ! empty( $params['clear'] ) ) {
            self::clear();
            return rest_ensure_response( array(
                'success' => true,
                'cleared' => true,
                'version' => MSH_SEO_VERSION,
            ) );
        }

        $id = isset( $params['measurement_id'] ) ? (string) $params['measurement_id'] : '';
        if ( ! self::set( $id ) ) {
            return new WP_Error(
                'msh_invalid_measurement_id',
                'Expected a GA4 measurement id like G-XXXXXXXXXX (not a property id).',
                array( 'status' => 400 )
            );
        }

        return rest_ensure_response( array(
            'success'           => true,
            'stored'            => true,
            'ga_measurement_id' => self::get(),
            'home'              => get_home_url(),
            'version'           => MSH_SEO_VERSION,
        ) );
    }
}
