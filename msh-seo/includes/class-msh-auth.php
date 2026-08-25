<?php
/**
 * MSH Auth - API key encryption and connection management.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Auth {

    const OPTION_KEY        = 'msh_seo_api_key';
    const OPTION_CONNECTION = 'msh_seo_connection_info';
    const TRANSIENT_VERIFY  = 'msh_seo_verified';
    const CIPHER            = 'aes-256-cbc';

    /**
     * Derive a fixed IV from AUTH_SALT.
     */
    private static function get_iv() {
        $iv_length = openssl_cipher_iv_length( self::CIPHER );
        $salt      = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'msh-seo-default-salt';
        return substr( hash( 'sha256', $salt, true ), 0, $iv_length );
    }

    /**
     * Get the encryption key from wp-config AUTH_KEY.
     */
    private static function get_encryption_key() {
        return defined( 'AUTH_KEY' ) ? AUTH_KEY : 'msh-seo-default-key';
    }

    /**
     * Encrypt and save the API key.
     *
     * @param string $raw_key The plaintext API key.
     * @return bool
     */
    public static function save_key( $raw_key ) {
        $raw_key = sanitize_text_field( $raw_key );

        if ( empty( $raw_key ) ) {
            return false;
        }

        $encrypted = openssl_encrypt(
            $raw_key,
            self::CIPHER,
            self::get_encryption_key(),
            0,
            self::get_iv()
        );

        if ( false === $encrypted ) {
            return false;
        }

        return update_option( self::OPTION_KEY, $encrypted );
    }

    /**
     * Decrypt and return the API key.
     *
     * @return string|false
     */
    public static function get_key() {
        $encrypted = get_option( self::OPTION_KEY, '' );

        if ( empty( $encrypted ) ) {
            return false;
        }

        $decrypted = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            self::get_encryption_key(),
            0,
            self::get_iv()
        );

        return $decrypted ?: false;
    }

    /**
     * Delete the stored API key and clear transients.
     *
     * @return bool
     */
    public static function delete_key() {
        delete_option( self::OPTION_KEY );
        delete_option( self::OPTION_CONNECTION );
        delete_transient( self::TRANSIENT_VERIFY );

        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( '_transient_msh_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_msh_' ) . '%'
        ) );

        return true;
    }

    /**
     * Check if an API key exists and is non-empty.
     *
     * @return bool
     */
    public static function is_connected() {
        $key = self::get_key();
        if ( empty( $key ) ) {
            return false;
        }
        if ( false !== get_transient( self::TRANSIENT_VERIFY ) ) {
            return true;
        }
        // The hourly verify transient expired — self-heal with a silent
        // re-verify. ADMIN REQUESTS ONLY: a blocking remote call must never
        // run inside a visitor's page render (front-end callers use their own
        // cached data paths). An in-flight lock prevents a stampede when
        // several admin requests race the expiry, and a short backoff stops
        // hammering MSH when it's unreachable.
        if ( ! is_admin() ) {
            return false;
        }
        if ( false !== get_transient( 'msh_seo_verify_backoff' ) || false !== get_transient( 'msh_seo_verify_lock' ) ) {
            return false;
        }
        set_transient( 'msh_seo_verify_lock', 1, 30 );
        $result = self::verify_connection();
        delete_transient( 'msh_seo_verify_lock' );
        if ( ! is_wp_error( $result ) ) {
            return true;
        }
        set_transient( 'msh_seo_verify_backoff', 1, 5 * MINUTE_IN_SECONDS );
        return false;
    }

    /**
     * Call MSH /api/plugin/verify to validate the key.
     * Caches result in a 1-hour transient.
     *
     * @return array|WP_Error Connection info or error.
     */
    public static function verify_connection() {
        $result = MSH_API::verify();

        if ( is_wp_error( $result ) ) {
            delete_transient( self::TRANSIENT_VERIFY );
            delete_option( self::OPTION_CONNECTION );
            return $result;
        }

        set_transient( self::TRANSIENT_VERIFY, true, HOUR_IN_SECONDS );
        update_option( self::OPTION_CONNECTION, $result );

        // One-click GSC onboarding: the dashboard may include the Google site
        // verification token in the verify response — store it so the meta
        // tag goes live even if the direct REST push never reached this site
        // (e.g. plugin was reinstalled, or the push happened while offline).
        if ( isset( $result['google_site_verification'] ) && is_string( $result['google_site_verification'] ) && '' !== $result['google_site_verification'] ) {
            update_option( 'msh_seo_google_site_verification', sanitize_text_field( $result['google_site_verification'] ) );
        }

        return $result;
    }

    /**
     * Return cached connection data (plan, org_name, limits).
     *
     * @return array|false
     */
    public static function get_connection_info() {
        return get_option( self::OPTION_CONNECTION, false );
    }
}
