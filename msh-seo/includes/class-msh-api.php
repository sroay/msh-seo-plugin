<?php
/**
 * MSH API - Communication with the MSH platform.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_API {

    const BASE_URL = 'https://app.marketingsohigh.com/api/plugin';

    /**
     * Make an authenticated request to the MSH API.
     *
     * @param string $endpoint Relative endpoint path (e.g. '/verify').
     * @param array  $data     Request body data.
     * @return array|WP_Error  Parsed JSON response or error.
     */
    private static function request( $endpoint, $data = array(), $timeout = 60 ) {
        $api_key = MSH_Auth::get_key();

        if ( empty( $api_key ) ) {
            return new WP_Error(
                'msh_no_key',
                __( 'No MSH API key configured. Connect your account in MSH SEO settings.', 'msh-seo' )
            );
        }

        $url = self::BASE_URL . $endpoint;

        $response = wp_remote_post( $url, array(
            'timeout' => max( 1, (int) $timeout ),
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'MSH-SEO-WP/' . MSH_SEO_VERSION,
            ),
            'body' => wp_json_encode( $data ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );

        if ( $code >= 400 ) {
            $message = isset( $json['error'] ) ? $json['error'] : __( 'API request failed.', 'msh-seo' );
            return new WP_Error( 'msh_api_error', $message, array( 'status' => $code ) );
        }

        if ( null === $json ) {
            return new WP_Error( 'msh_invalid_json', __( 'Invalid response from MSH API.', 'msh-seo' ) );
        }

        return $json;
    }

    /**
     * Verify the API key and fetch connection info.
     *
     * @return array|WP_Error { plan, org_name, limits, usage }
     */
    public static function verify() {
        // site_url → public canonical URL (home_url() — the "Site Address" in
        // WP settings). wp_url → REST API host (site_url() — the "WordPress
        // Address"). These differ on headless installs where WordPress lives
        // at wp.example.com but the public site is rendered by Next.js at
        // example.com. MSH uses site_url to match/link seo_websites rows
        // (which store the canonical URL) and wp_url as the REST API target.
        // Short timeout: the self-healing re-verify inside MSH_Auth::is_connected()
        // can run during page renders, so this must never hang a request.
        return self::request( '/verify', array(
            'site_url' => get_home_url(),
            'wp_url'   => get_site_url(),
        ), 8 );
    }

    /**
     * AI-powered content analysis.
     *
     * @param string $title   Post title.
     * @param string $content Post content (HTML).
     * @param string $keyword Focus keyword.
     * @param string $url     Post URL.
     * @return array|WP_Error
     */
    public static function analyze( $title, $content, $keyword, $url ) {
        $cache_key = 'msh_analysis_' . md5( $title . $content . $keyword );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $result = self::request( '/analyze', array(
            'title'   => $title,
            'content' => $content,
            'keyword' => $keyword,
            'url'     => $url,
        ) );

        if ( ! is_wp_error( $result ) ) {
            set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
        }

        return $result;
    }

    /**
     * Generate meta title and description using AI.
     *
     * @param string $title   Post title.
     * @param string $content Post content (HTML).
     * @param string $keyword Focus keyword.
     * @return array|WP_Error { titles: [], descriptions: [] }
     */
    public static function generate_meta( $title, $content, $keyword ) {
        return self::request( '/generate-meta', array(
            'title'   => $title,
            'content' => $content,
            'keyword' => $keyword,
        ) );
    }

    /**
     * Internal Link Mesh: ask the MSH brain for ranked internal-link targets.
     *
     * The dashboard scores the site's topical cluster graph plus the supplied
     * post inventory and returns the best contextual links with anchor text.
     *
     * @param string $title     Post title.
     * @param string $content   Post content (HTML).
     * @param string $keyword   Focus keyword.
     * @param string $url       Post permalink (may be empty for new posts).
     * @param array  $inventory List of { url, title } for the site's other posts.
     * @return array|WP_Error { suggestions: [...], source_cluster, candidate_count }
     */
    public static function link_suggestions( $title, $content, $keyword, $url, $inventory = array() ) {
        return self::request( '/link-suggestions', array(
            'title'     => $title,
            'content'   => $content,
            'keyword'   => $keyword,
            'url'       => $url,
            'inventory' => $inventory,
        ) );
    }

    /**
     * Distribute a post to social channels via MSH.
     *
     * Sends the post data and selected channels to the MSH API for
     * queuing and distribution. This is a fire-and-forget operation.
     *
     * @param int   $post_id  WordPress post ID.
     * @param array $channels Array of channel slugs (e.g. 'linkedin', 'twitter').
     * @return array|WP_Error API response or error.
     */
    public static function distribute( $post_id, $channels ) {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return new WP_Error( 'no_post', __( 'Post not found.', 'msh-seo' ) );
        }

        $excerpt = $post->post_excerpt;
        if ( empty( $excerpt ) ) {
            $excerpt = wp_trim_words( $post->post_content, 30 );
        }

        return self::request( '/distribute', array(
            'post_id'   => $post_id,
            'post_url'  => get_permalink( $post_id ),
            'title'     => $post->post_title,
            'excerpt'   => $excerpt,
            'content'   => wp_strip_all_tags( $post->post_content ),
            'channels'  => $channels,
            'image_url' => get_the_post_thumbnail_url( $post_id, 'full' ),
        ) );
    }

    /**
     * Fetch the intent-personalized CTA config from MSH (cached 1 hour).
     *
     * @return array|WP_Error { config: { variants }, buckets, target_url }
     */
    public static function cta_config() {
        $cached = get_transient( 'msh_cta_config' );
        if ( false !== $cached ) {
            // Empty array is a cached "unavailable" marker — return an error so
            // callers render nothing without re-hitting a slow/unreachable API.
            return empty( $cached ) ? new WP_Error( 'msh_cta_unavailable', 'CTA config unavailable.' ) : $cached;
        }

        $api_key = MSH_Auth::get_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'msh_no_key', 'No MSH API key configured.' );
        }

        // Short timeout: this runs during page render, so it must never hang.
        $response = wp_remote_post( self::BASE_URL . '/cta-config', array(
            'timeout' => 4,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'MSH-SEO-WP/' . MSH_SEO_VERSION,
            ),
            'body'    => wp_json_encode( array( 'site_url' => get_home_url() ) ),
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
            set_transient( 'msh_cta_config', array(), 5 * MINUTE_IN_SECONDS );
            return new WP_Error( 'msh_cta_unavailable', 'CTA config unavailable.' );
        }

        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $json ) || empty( $json['config']['variants'] ) ) {
            set_transient( 'msh_cta_config', array(), 5 * MINUTE_IN_SECONDS );
            return new WP_Error( 'msh_cta_unavailable', 'CTA config unavailable.' );
        }

        set_transient( 'msh_cta_config', $json, HOUR_IN_SECONDS );
        return $json;
    }

    /**
     * Forward CTA conversion events to MSH. Fire-and-forget (non-blocking) so it
     * never slows the visitor's page — events feed the MSH learning loop.
     *
     * @param array $events List of { url, type, bucket?, value? }.
     * @return bool
     */
    public static function conversion_event( $events ) {
        $api_key = MSH_Auth::get_key();
        if ( empty( $api_key ) || empty( $events ) ) {
            return false;
        }

        wp_remote_post( self::BASE_URL . '/conversion-event', array(
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'MSH-SEO-WP/' . MSH_SEO_VERSION,
            ),
            'body'     => wp_json_encode( array( 'events' => $events ) ),
        ) );

        return true;
    }

    /**
     * Fetch the site's growth summary (GSC traffic trend, ranking keywords,
     * content published) from MSH. Cached 2 hours. Short timeout — renders on
     * an admin page, must not hang.
     *
     * @return array|WP_Error
     */
    public static function growth_summary() {
        $cached = get_transient( 'msh_growth_summary' );
        if ( false !== $cached ) {
            return empty( $cached ) ? new WP_Error( 'msh_growth_unavailable', 'Growth data unavailable.' ) : $cached;
        }

        $result = self::request( '/growth-summary', array(
            'site_url' => get_home_url(),
        ), 8 );

        if ( is_wp_error( $result ) ) {
            set_transient( 'msh_growth_summary', array(), 10 * MINUTE_IN_SECONDS );
        } else {
            set_transient( 'msh_growth_summary', $result, 2 * HOUR_IN_SECONDS );
        }

        return $result;
    }

    /**
     * Fetch the site's CTA conversion funnel from MSH (cached 15 minutes).
     *
     * @return array|WP_Error { totals, ctr, cvr, by_intent, top_posts }
     */
    public static function cta_stats() {
        $cached = get_transient( 'msh_cta_stats' );
        if ( false !== $cached ) {
            return $cached;
        }

        $result = self::request( '/cta-stats', array(
            'site_url' => get_home_url(),
        ), 8 );

        if ( is_wp_error( $result ) ) {
            // Negative-cache briefly so a slow/down API can't stall every
            // Analytics page load.
            set_transient( 'msh_cta_stats', array(), 10 * MINUTE_IN_SECONDS );
        } else {
            set_transient( 'msh_cta_stats', $result, 15 * MINUTE_IN_SECONDS );
        }

        return $result;
    }

    /**
     * Fetch AI-visibility (is AI citing this site?) from MSH. Cached 6 hours —
     * the underlying signal refreshes weekly.
     *
     * @return array|WP_Error { checked, ai_cited, citation_rate, cited_for, competitors, opportunities }
     */
    public static function ai_visibility() {
        $cached = get_transient( 'msh_ai_visibility' );
        if ( false !== $cached ) {
            return $cached;
        }

        $result = self::request( '/ai-visibility', array(
            'site_url' => get_home_url(),
        ), 8 );

        if ( is_wp_error( $result ) ) {
            set_transient( 'msh_ai_visibility', array(), 10 * MINUTE_IN_SECONDS );
        } else {
            set_transient( 'msh_ai_visibility', $result, 6 * HOUR_IN_SECONDS );
        }

        return $result;
    }

    /**
     * Fetch dashboard summary data from MSH for the dashboard widget.
     *
     * @return array|WP_Error { recent_posts: [...] }
     */
    public static function dashboard_summary() {
        return self::request( '/dashboard-summary', array(
            'site_url' => get_home_url(),
            'wp_url'   => get_site_url(),
        ) );
    }
}
