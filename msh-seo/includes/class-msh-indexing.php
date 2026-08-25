<?php
/**
 * MSH Instant Indexing (IndexNow)
 *
 * Automatically submits new and updated URLs to IndexNow-compatible
 * search engines (Bing, Yandex, Naver, Seznam) when posts are
 * published or updated.
 *
 * Handles API key generation, key file verification, single and bulk
 * URL submission, and maintains a log of recent submissions.
 *
 * @package MSH_SEO
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Indexing {

    /**
     * Option key for storing the IndexNow API key.
     *
     * @var string
     */
    const INDEXNOW_KEY_OPTION = 'msh_seo_indexnow_key';

    /**
     * Transient key for storing the submission log.
     *
     * @var string
     */
    const LOG_TRANSIENT = 'msh_indexnow_log';

    /**
     * Maximum number of log entries to retain.
     *
     * @var int
     */
    const MAX_LOG_ENTRIES = 20;

    /**
     * Initialize IndexNow hooks.
     *
     * Hooks into post publish/update to auto-submit URLs, and
     * handles serving the IndexNow key verification file.
     *
     * @return void
     */
    const GOOGLE_KEY_OPTION = 'msh_seo_google_indexing_key';

    public static function init() {
        // Auto-submit on post publish.
        add_action( 'transition_post_status', array( __CLASS__, 'on_post_status_change' ), 10, 3 );

        // Serve the IndexNow key verification file.
        add_action( 'init', array( __CLASS__, 'serve_key_file' ), 1 );

        // Bulk re-submit action (triggered from the admin).
        add_action( 'admin_post_msh_seo_bulk_index', array( __CLASS__, 'handle_bulk_index' ) );

        // Deferred submission handler — publish hooks only SCHEDULE this, so
        // the editor's publish request is never blocked by indexing HTTP calls.
        add_action( 'msh_seo_scheduled_submit', array( __CLASS__, 'handle_deferred_submit' ), 10, 2 );
    }

    /**
     * Generate and store an IndexNow API key, or return the existing one.
     *
     * Generates a 32-character hexadecimal key if none exists.
     *
     * @return string The IndexNow API key.
     */
    public static function get_or_create_key() {
        $key = get_option( self::INDEXNOW_KEY_OPTION, '' );

        if ( ! empty( $key ) ) {
            return $key;
        }

        // Generate a 32-character hex key.
        $key = bin2hex( random_bytes( 16 ) );
        update_option( self::INDEXNOW_KEY_OPTION, $key, false );

        return $key;
    }

    /**
     * Serve the IndexNow key verification file.
     *
     * When a request comes in for /{key}.txt, this method outputs
     * the key as plain text and exits. This verifies key ownership
     * with IndexNow-compatible search engines.
     *
     * @return void
     */
    public static function serve_key_file() {
        $key = get_option( self::INDEXNOW_KEY_OPTION, '' );

        if ( empty( $key ) ) {
            return;
        }

        // Get the request URI without query string.
        $request_uri = isset( $_SERVER['REQUEST_URI'] )
            ? strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' )
            : '';

        // Remove any leading directory from the URI (for subdirectory installs).
        $home_path   = wp_parse_url( home_url(), PHP_URL_PATH );
        $home_path   = $home_path ? trailingslashit( $home_path ) : '/';
        $request_path = preg_replace( '#^' . preg_quote( $home_path, '#' ) . '#', '/', $request_uri );

        if ( '/' . $key . '.txt' === $request_path ) {
            header( 'Content-Type: text/plain; charset=utf-8' );
            header( 'X-Robots-Tag: noindex' );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $key;
            exit;
        }
    }

    /**
     * Submit a single URL to the IndexNow API.
     *
     * Sends a POST request to the IndexNow endpoint which notifies
     * all participating search engines (Bing, Yandex, Naver, Seznam).
     *
     * Uses wp_remote_post with a 10-second timeout. Results are logged
     * in a transient for admin display.
     *
     * @param string $url The URL to submit for indexing.
     * @return bool True if the submission was accepted (2xx response), false otherwise.
     */
    public static function submit_url( $url ) {
        return self::submit_urls( array( $url ) );
    }

    /**
     * Submit multiple URLs at once to the IndexNow API.
     *
     * Sends a batch of URLs (max 10,000 per request per IndexNow spec).
     *
     * @param array $urls Array of URL strings to submit.
     * @return bool True if the submission was accepted, false otherwise.
     */
    public static function submit_urls( $urls ) {
        if ( empty( $urls ) ) {
            return false;
        }

        // Enforce the 10,000 URL limit per batch.
        $urls = array_slice( $urls, 0, 10000 );

        $key          = self::get_or_create_key();
        $host         = wp_parse_url( home_url(), PHP_URL_HOST );
        $key_location = home_url( '/' . $key . '.txt' );

        $body = array(
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => $key_location,
            'urlList'     => array_values( array_map( 'esc_url_raw', $urls ) ),
        );

        $response = wp_remote_post( 'https://api.indexnow.org/IndexNow', array(
            'timeout'     => 10,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => array(
                'Content-Type' => 'application/json; charset=utf-8',
            ),
            'body'        => wp_json_encode( $body ),
        ) );

        $success     = false;
        $status_code = 0;
        $error_msg   = '';

        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
        } else {
            $status_code = wp_remote_retrieve_response_code( $response );
            $success     = $status_code >= 200 && $status_code < 300;

            if ( ! $success ) {
                $error_msg = wp_remote_retrieve_body( $response );
            }
        }

        // Log the submission.
        self::log_submission( $urls, $success, $status_code, $error_msg );

        return $success;
    }

    /**
     * Handle post status transitions for auto-submission.
     *
     * Submits the permalink to IndexNow when a post is first published
     * or when an already-published post is updated.
     *
     * Skips revisions, autosaves, and non-public post types.
     *
     * @param string  $new_status The new post status.
     * @param string  $old_status The old post status.
     * @param WP_Post $post       The post object.
     * @return void
     */
    public static function on_post_status_change( $new_status, $old_status, $post ) {
        // Only act on publish transitions.
        if ( 'publish' !== $new_status ) {
            return;
        }

        // Skip revisions and autosaves.
        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
            return;
        }

        // Only submit for public post types.
        $post_type_obj = get_post_type_object( $post->post_type );
        if ( ! $post_type_obj || ! $post_type_obj->public ) {
            return;
        }

        // Prevent duplicate submissions during the same request.
        $already_submitted = get_transient( 'msh_indexnow_submitted_' . $post->ID );
        if ( $already_submitted ) {
            return;
        }

        // Mark as submitted for this request (60 second cooldown).
        set_transient( 'msh_indexnow_submitted_' . $post->ID, 1, 60 );

        $permalink = get_permalink( $post->ID );
        $urls      = array( $permalink );

        // Also submit the sitemap URL if available.
        $sitemap_url = home_url( '/sitemap.xml' );
        $urls[]      = $sitemap_url;

        // DEFER the actual HTTP submissions (IndexNow + optional Google ping):
        // this hook runs inside the editor's publish request, and blocking
        // remote calls here can stack up to ~30s of save latency. The
        // scheduled event runs them out-of-band; spawn_cron() kicks the cron
        // runner immediately via a non-blocking loopback.
        wp_schedule_single_event( time(), 'msh_seo_scheduled_submit', array( $urls, $permalink ) );
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }
    }

    /**
     * Deferred submission runner (wp-cron): IndexNow batch + optional Google
     * Indexing API ping. Never runs inside a user-facing request.
     *
     * @param array  $urls       URLs for IndexNow.
     * @param string $google_url Permalink for the Google Indexing API ping.
     * @return void
     */
    public static function handle_deferred_submit( $urls, $google_url = '' ) {
        if ( ! empty( $urls ) && is_array( $urls ) ) {
            self::submit_urls( $urls );
        }
        if ( ! empty( $google_url ) && self::google_indexing_enabled() ) {
            self::submit_url_google( $google_url, 'URL_UPDATED' );
        }
    }

    /**
     * Handle scheduled IndexNow submissions via wp-cron.
     *
     * @param array $urls Array of URLs to submit.
     * @return void
     */
    public static function handle_scheduled_submit( $urls ) {
        if ( ! empty( $urls ) && is_array( $urls ) ) {
            self::submit_urls( $urls );
        }
    }

    /**
     * Get recent IndexNow submissions for admin display.
     *
     * Returns the last 20 submissions from the transient log.
     * Each entry contains: urls, success, status_code, error, timestamp.
     *
     * @return array Array of submission log entries.
     */
    public static function get_recent_submissions() {
        $log = get_transient( self::LOG_TRANSIENT );
        return is_array( $log ) ? $log : array();
    }

    /**
     * Is Google Indexing API configured (a service-account JSON is stored)?
     *
     * @return bool
     */
    public static function google_indexing_enabled() {
        return '' !== trim( (string) get_option( self::GOOGLE_KEY_OPTION, '' ) );
    }

    /**
     * Full Google-indexing status for UI chips:
     *  - 'local'   → this site holds its own service-account key (self-hosted pings)
     *  - 'central' → MSH pings Google server-side on every publish (no key needed here)
     *  - 'off'     → neither
     *
     * @return string local|central|off
     */
    public static function google_status() {
        if ( self::google_indexing_enabled() ) {
            return 'local';
        }
        if ( class_exists( 'MSH_Auth' ) ) {
            $info = MSH_Auth::get_connection_info();
            if ( is_array( $info ) && ! empty( $info['central_google_indexing'] ) ) {
                return 'central';
            }
        }
        return 'off';
    }

    /**
     * Submit a single URL to Google's Indexing API.
     *
     * Requires a Google Cloud service account (Indexing API enabled) added as
     * an Owner of the property in Search Console. Officially supports JobPosting
     * / BroadcastEvent; widely used to prompt crawl for other content too.
     *
     * @param string $url  The URL to notify.
     * @param string $type URL_UPDATED or URL_DELETED.
     * @return bool True on 2xx.
     */
    public static function submit_url_google( $url, $type = 'URL_UPDATED' ) {
        $token = self::get_google_access_token();
        if ( empty( $token ) ) {
            return false;
        }

        $response = wp_remote_post( 'https://indexing.googleapis.com/v3/urlNotifications:publish', array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'url'  => esc_url_raw( $url ),
                'type' => ( 'URL_DELETED' === $type ) ? 'URL_DELETED' : 'URL_UPDATED',
            ) ),
        ) );

        $success = false;
        $code    = 0;
        $error   = '';
        if ( is_wp_error( $response ) ) {
            $error = $response->get_error_message();
        } else {
            $code    = wp_remote_retrieve_response_code( $response );
            $success = $code >= 200 && $code < 300;
            if ( ! $success ) {
                $error = wp_remote_retrieve_body( $response );
            }
        }

        self::log_submission( array( $url . ' (Google)' ), $success, $code, $error );
        return $success;
    }

    /**
     * Re-submit every published URL to IndexNow (batched) and, when enabled,
     * to Google's Indexing API (capped at 200/run to respect the daily quota).
     *
     * @return array { count, indexnow, google }
     */
    public static function submit_all() {
        $ids = get_posts( array(
            'post_type'   => array( 'post', 'page' ),
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ) );

        $urls = array();
        foreach ( $ids as $id ) {
            $permalink = get_permalink( $id );
            if ( $permalink ) {
                $urls[] = $permalink;
            }
        }
        $urls = array_values( array_unique( $urls ) );

        if ( empty( $urls ) ) {
            return array( 'count' => 0, 'indexnow' => false, 'google' => 0 );
        }

        $indexnow = self::submit_urls( $urls );

        $google_count = 0;
        if ( self::google_indexing_enabled() ) {
            foreach ( array_slice( $urls, 0, 200 ) as $u ) {
                if ( self::submit_url_google( $u, 'URL_UPDATED' ) ) {
                    $google_count++;
                }
            }
        }

        return array( 'count' => count( $urls ), 'indexnow' => $indexnow, 'google' => $google_count );
    }

    /**
     * admin-post handler: bulk re-submit all URLs, then redirect back.
     *
     * @return void
     */
    public static function handle_bulk_index() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'msh-seo' ) );
        }
        check_admin_referer( 'msh_seo_bulk_index' );

        $result = self::submit_all();

        wp_safe_redirect( add_query_arg(
            array( 'page' => 'msh-seo', 'msh_bulk' => (int) $result['count'] ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Get a cached Google OAuth access token, minting one from the stored
     * service-account JSON via a signed JWT (RS256) when needed.
     *
     * @return string Access token, or '' on failure.
     */
    private static function get_google_access_token() {
        $cached = get_transient( 'msh_google_index_token' );
        if ( $cached ) {
            return $cached;
        }

        $json = trim( (string) get_option( self::GOOGLE_KEY_OPTION, '' ) );
        if ( '' === $json ) {
            return '';
        }
        $creds = json_decode( $json, true );
        if ( empty( $creds['client_email'] ) || empty( $creds['private_key'] ) ) {
            return '';
        }

        $now    = time();
        $header = self::base64url_encode( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
        $claim  = self::base64url_encode( wp_json_encode( array(
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/indexing',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ) ) );

        $signing_input = $header . '.' . $claim;
        $signature     = '';
        if ( ! openssl_sign( $signing_input, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256 ) ) {
            return '';
        }
        $jwt = $signing_input . '.' . self::base64url_encode( $signature );

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'timeout' => 10,
            'body'    => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return '';
        }
        $data  = json_decode( wp_remote_retrieve_body( $response ), true );
        $token = isset( $data['access_token'] ) ? $data['access_token'] : '';
        if ( $token ) {
            set_transient( 'msh_google_index_token', $token, 55 * MINUTE_IN_SECONDS );
        }
        return $token;
    }

    /**
     * URL-safe base64 (no padding) for JWT segments.
     *
     * @param string $data Raw data.
     * @return string
     */
    private static function base64url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /**
     * Log an IndexNow submission to the transient store.
     *
     * Maintains a rolling log of the last 20 submissions with a
     * 7-day expiration on the transient.
     *
     * @param array  $urls        The submitted URLs.
     * @param bool   $success     Whether the submission was successful.
     * @param int    $status_code The HTTP status code from the API.
     * @param string $error_msg   Error message if the submission failed.
     * @return void
     */
    private static function log_submission( $urls, $success, $status_code, $error_msg = '' ) {
        $log = get_transient( self::LOG_TRANSIENT );

        if ( ! is_array( $log ) ) {
            $log = array();
        }

        $entry = array(
            'urls'        => array_map( 'esc_url_raw', $urls ),
            'success'     => (bool) $success,
            'status_code' => intval( $status_code ),
            'error'       => sanitize_text_field( $error_msg ),
            'timestamp'   => current_time( 'mysql' ),
        );

        // Prepend new entry.
        array_unshift( $log, $entry );

        // Keep only the last N entries.
        $log = array_slice( $log, 0, self::MAX_LOG_ENTRIES );

        // Store for 7 days.
        set_transient( self::LOG_TRANSIENT, $log, 7 * DAY_IN_SECONDS );
    }
}
