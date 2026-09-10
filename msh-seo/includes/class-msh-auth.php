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

        // One-click Google Analytics: the same reply may carry the GA4
        // measurement id, so the tag goes live even if the direct push never
        // reached this site.
        MSH_Tracking::absorb( $result );

        // Bing Webmaster Tools ownership token, printed like the Google one.
        // ChatGPT and Copilot answer from Bing's index; unverified there, a
        // site cannot submit sitemaps or see what Bing thinks of it.
        if ( isset( $result['bing_site_verification'] ) && is_string( $result['bing_site_verification'] ) && '' !== $result['bing_site_verification'] ) {
            update_option( 'msh_seo_bing_site_verification', sanitize_text_field( $result['bing_site_verification'] ) );
        }
        // Where the newsletter form posts. Only delivered to sites whose
        // organisation owns the newsletter; absent means no form.
        if ( isset( $result['newsletter_endpoint'] ) && is_string( $result['newsletter_endpoint'] ) && 0 === strpos( $result['newsletter_endpoint'], 'https://' ) ) {
            update_option( 'msh_seo_newsletter_endpoint', esc_url_raw( $result['newsletter_endpoint'] ) );
        }

        // Entity links for the schema (Organization.sameAs, the founder as
        // author). Delivered by the dashboard from the brand's real profiles;
        // never overwrites a list a person typed into the settings screen.
        if ( isset( $result['entity'] ) && is_array( $result['entity'] ) ) {
            $entity = $result['entity'];
            if ( ! empty( $entity['same_as'] ) && is_array( $entity['same_as'] ) ) {
                $urls = array();
                foreach ( $entity['same_as'] as $u ) {
                    $u = esc_url_raw( (string) $u );
                    if ( $u && 0 === strpos( $u, 'https://' ) ) {
                        $urls[] = $u;
                    }
                }
                $current = get_option( 'msh_seo_social_profiles', '' );
                $ours    = 'msh' === get_option( 'msh_seo_social_profiles_source', '' );
                if ( ! empty( $urls ) && ( empty( $current ) || $ours ) ) {
                    update_option( 'msh_seo_social_profiles', implode( "\n", $urls ) );
                    update_option( 'msh_seo_social_profiles_source', 'msh' );
                }
            }
            if ( ! empty( $entity['founder'] ) && is_array( $entity['founder'] ) && ! empty( $entity['founder']['name'] ) ) {
                $f = $entity['founder'];
                $founder_urls = array();
                if ( ! empty( $f['same_as'] ) && is_array( $f['same_as'] ) ) {
                    foreach ( $f['same_as'] as $u ) {
                        $u = esc_url_raw( (string) $u );
                        if ( $u && 0 === strpos( $u, 'https://' ) ) {
                            $founder_urls[] = $u;
                        }
                    }
                }
                update_option( 'msh_seo_founder', array(
                    'name'      => sanitize_text_field( (string) $f['name'] ),
                    'job_title' => sanitize_text_field( (string) ( $f['job_title'] ?? '' ) ),
                    'bio'       => sanitize_text_field( (string) ( $f['bio'] ?? '' ) ),
                    'same_as'   => $founder_urls,
                ) );
            }
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
