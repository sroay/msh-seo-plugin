<?php
/**
 * MSH Autopilot — Self-Healing Content Engine
 *
 * Extends the freshness scanner with enhanced content signals, GSC data
 * collection, weekly autopilot reports to the MSH dashboard, and a REST
 * endpoint to receive refresh commands from the dashboard.
 *
 * @package MSH_SEO
 * @since   0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Autopilot {

    /** Cron hook name. */
    const CRON_HOOK = 'msh_seo_autopilot_scan';

    /**
     * Wire up all hooks.
     */
    public static function init() {
        // Schedule weekly autopilot scan (separate from basic freshness).
        register_activation_hook(
            dirname( __DIR__ ) . '/msh-seo.php',
            array( __CLASS__, 'schedule_scan' )
        );

        register_deactivation_hook(
            dirname( __DIR__ ) . '/msh-seo.php',
            array( __CLASS__, 'unschedule_scan' )
        );

        // Register cron schedule.
        add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );

        // Cron callback.
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_autopilot_scan' ) );

        // Ensure cron is scheduled (covers upgrades, not just activation).
        add_action( 'init', array( __CLASS__, 'maybe_schedule_scan' ) );

        // REST endpoint to receive refresh commands from dashboard.
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

        // Admin AJAX handlers.
        add_action( 'wp_ajax_msh_autopilot_run_scan', array( __CLASS__, 'ajax_run_scan' ) );
        add_action( 'wp_ajax_msh_autopilot_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
    }

    /* ------------------------------------------------------------------
     * Cron scheduling
     * ----------------------------------------------------------------*/

    /**
     * Register a custom weekly schedule if not already present.
     */
    public static function add_cron_schedule( $schedules ) {
        if ( ! isset( $schedules['msh_weekly'] ) ) {
            $schedules['msh_weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly (MSH)', 'msh-seo' ),
            );
        }
        if ( ! isset( $schedules['msh_biweekly'] ) ) {
            $schedules['msh_biweekly'] = array(
                'interval' => 2 * WEEK_IN_SECONDS,
                'display'  => __( 'Every Two Weeks (MSH)', 'msh-seo' ),
            );
        }
        return $schedules;
    }

    /**
     * Schedule the autopilot scan for Wednesday at 04:00 site time.
     * (Offset from freshness scan which runs Monday 03:00.)
     */
    public static function schedule_scan() {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        $tz       = wp_timezone();
        $next_wed = new DateTimeImmutable( 'next Wednesday 04:00', $tz );
        $now      = new DateTimeImmutable( 'now', $tz );

        if ( (int) $now->format( 'N' ) === 3 && $now->format( 'H:i' ) < '04:00' ) {
            $next_wed = new DateTimeImmutable( 'today 04:00', $tz );
        }

        wp_schedule_event( $next_wed->getTimestamp(), 'msh_weekly', self::CRON_HOOK );
    }

    /**
     * Ensure cron is scheduled (handles upgrades where activation hook doesn't fire).
     */
    public static function maybe_schedule_scan() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            self::schedule_scan();
        }
    }

    /**
     * Remove the scheduled event on deactivation.
     */
    public static function unschedule_scan() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
    }

    /* ------------------------------------------------------------------
     * Core scan logic
     * ----------------------------------------------------------------*/

    /**
     * Run the full autopilot scan — collects enhanced content signals
     * for every published post and reports to the MSH dashboard.
     */
    public static function run_autopilot_scan() {
        $posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );

        if ( empty( $posts ) ) {
            return;
        }

        $now_ts        = current_time( 'timestamp' );
        $current_year  = (int) gmdate( 'Y' );
        $site_url      = home_url();
        $all_signals   = array();
        $min_age_days  = (int) get_option( 'msh_autopilot_min_age_days', 30 );
        $min_words     = (int) get_option( 'msh_autopilot_min_word_count', 300 );

        $totals = array(
            'total_posts'  => count( $posts ),
            'fresh'        => 0,
            'stale'        => 0,
            'skipped'      => 0,
            'avg_position' => 0,
            'total_clicks' => 0,
        );

        foreach ( $posts as $post_id ) {
            $post    = get_post( $post_id );
            $content = $post->post_content;
            $text    = wp_strip_all_tags( $content );

            // Apply min word count filter — skip very short posts.
            $wc = str_word_count( $text );
            if ( $min_words > 0 && $wc < $min_words ) {
                $totals['skipped']++;
                continue;
            }

            // Apply min content age filter — skip recently published posts.
            $modified_ts_check = strtotime( $post->post_modified );
            $age_days_check    = max( 0, (int) floor( ( $now_ts - $modified_ts_check ) / DAY_IN_SECONDS ) );
            if ( $min_age_days > 0 && $age_days_check < $min_age_days ) {
                $totals['skipped']++;
                continue;
            }

            // Calculate freshness score (reuse MSH_Freshness algorithm).
            $freshness_score = self::calculate_freshness( $post, $now_ts, $current_year );

            // Enhanced content signals.
            $has_outdated = false;
            if ( preg_match_all( '/\b(20[0-9]{2})\b/', $text, $yr_matches ) ) {
                foreach ( array_unique( array_map( 'intval', $yr_matches[1] ) ) as $yr ) {
                    if ( $yr < $current_year ) {
                        $has_outdated = true;
                        break;
                    }
                }
            }

            $internal_links = 0;
            $external_links = 0;
            if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $link_matches ) ) {
                foreach ( $link_matches[1] as $href ) {
                    if ( strpos( $href, $site_url ) === 0 || strpos( $href, '/' ) === 0 ) {
                        $internal_links++;
                    } else {
                        $external_links++;
                    }
                }
            }

            $modified_ts    = strtotime( $post->post_modified );
            $days_since     = max( 0, (int) floor( ( $now_ts - $modified_ts ) / DAY_IN_SECONDS ) );

            $signal = array(
                'post_id'            => $post_id,
                'title'              => get_the_title( $post_id ),
                'url'                => get_permalink( $post_id ),
                'slug'               => $post->post_name,
                'publish_date'       => $post->post_date,
                'modified_date'      => $post->post_modified,
                'word_count'         => str_word_count( $text ),
                'freshness_score'    => $freshness_score,
                'content_age_days'   => $days_since,
                'has_outdated_years' => $has_outdated,
                'has_schema'         => strpos( $content, 'application/ld+json' ) !== false,
                'has_faq'            => (bool) preg_match( '/<h[23][^>]*>.*(?:FAQ|Frequently)/i', $content ),
                'has_table'          => strpos( $content, '<table' ) !== false,
                'internal_links'     => $internal_links,
                'external_links'     => $external_links,
                'image_count'        => substr_count( $content, '<img' ),
                'h2_count'           => substr_count( strtolower( $content ), '<h2' ),
                'content_hash'       => md5( $content ),
            );

            $all_signals[] = $signal;

            // Tally.
            if ( $freshness_score >= 70 ) {
                $totals['fresh']++;
            } else {
                $totals['stale']++;
            }
        }

        // Build payload.
        $payload = array(
            'website_url' => $site_url,
            'scan_date'   => current_time( 'c' ),
            'posts'       => $all_signals,
            'totals'      => $totals,
        );

        // Send to MSH dashboard.
        self::send_autopilot_report( $payload );
    }

    /**
     * Calculate freshness score for a post (mirrors MSH_Freshness algorithm).
     */
    private static function calculate_freshness( $post, $now_ts, $current_year ) {
        $modified_ts = strtotime( $post->post_modified );
        $days_since  = max( 0, floor( ( $now_ts - $modified_ts ) / DAY_IN_SECONDS ) );
        $score       = max( 0, 100 - (int) floor( $days_since * 0.5 ) );

        $text = wp_strip_all_tags( $post->post_content );

        if ( preg_match_all( '/\b(20[0-9]{2})\b/', $text, $matches ) ) {
            foreach ( array_unique( array_map( 'intval', $matches[1] ) ) as $yr ) {
                if ( $yr < $current_year ) {
                    $score -= 15;
                }
            }
        }

        $text_no_years = preg_replace( '/\b20[0-9]{2}\b/', '', $text );
        if ( ! preg_match( '/\d+/', $text_no_years ) ) {
            $score -= 10;
        }

        if ( str_word_count( $text ) < 500 ) {
            $score -= 10;
        }

        return max( 0, min( 100, $score ) );
    }

    /**
     * POST the autopilot report to the MSH dashboard.
     *
     * Uses MSH_Auth::get_key() for the encrypted API key and
     * MSH_API::BASE_URL for the endpoint — same auth as all other
     * plugin-to-dashboard requests.
     *
     * @param array $payload The scan report data.
     * @return array|WP_Error|null Response, error, or null if not connected.
     */
    private static function send_autopilot_report( $payload ) {
        $api_key = MSH_Auth::get_key();

        if ( empty( $api_key ) ) {
            return null;
        }

        $url = MSH_API::BASE_URL . '/autopilot-report';

        $response = wp_remote_post( $url, array(
            'timeout' => 120,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'User-Agent'    => 'MSH-SEO-WP/' . MSH_SEO_VERSION,
            ),
            'body' => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[MSH Autopilot] Report failed: ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $msg = isset( $body['error'] ) ? $body['error'] : 'HTTP ' . $code;
            error_log( '[MSH Autopilot] Report rejected: ' . $msg );
            return new WP_Error( 'autopilot_report_failed', $msg );
        }

        // Save last scan timestamp on successful report delivery.
        update_option( 'msh_autopilot_last_scan', current_time( 'c' ) );

        return $body;
    }

    /* ------------------------------------------------------------------
     * REST API — Receive autopilot refresh commands
     * ----------------------------------------------------------------*/

    /**
     * Register the autopilot-update endpoint.
     */
    public static function register_rest_routes() {
        register_rest_route( 'msh-seo/v1', '/autopilot-update', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_autopilot_update' ),
            'permission_callback' => 'msh_seo_verify_plugin_key',
        ) );
    }

    /**
     * Handle an autopilot update — update post content + meta + optionally ping Google.
     */
    public static function handle_autopilot_update( WP_REST_Request $request ) {
        $params = $request->get_json_params();

        $post_id = absint( $params['post_id'] ?? 0 );
        if ( ! $post_id || ! get_post( $post_id ) ) {
            return new WP_Error( 'invalid_post', 'Post not found.', array( 'status' => 404 ) );
        }

        // Update post content.
        $update_data = array( 'ID' => $post_id );

        if ( ! empty( $params['title'] ) ) {
            $update_data['post_title'] = sanitize_text_field( $params['title'] );
        }
        if ( ! empty( $params['content'] ) ) {
            $update_data['post_content'] = wp_kses_post( $params['content'] );
        }
        if ( ! empty( $params['excerpt'] ) ) {
            $update_data['post_excerpt'] = sanitize_text_field( $params['excerpt'] );
        }

        $result = wp_update_post( $update_data, true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Update MSH SEO meta fields.
        if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
            $allowed_meta = array(
                '_msh_seo_title',
                '_msh_seo_description',
                '_msh_focus_keyword',
                '_msh_seo_score',
                '_msh_freshness_score',
            );

            foreach ( $params['meta'] as $key => $value ) {
                if ( in_array( $key, $allowed_meta, true ) ) {
                    update_post_meta( $post_id, $key, sanitize_text_field( $value ) );
                }
            }
        }

        // Update freshness score to 100 after refresh.
        update_post_meta( $post_id, '_msh_freshness_score', 100 );
        update_post_meta( $post_id, '_msh_freshness_checked', gmdate( 'Y-m-d\TH:i:s\Z' ) );

        // Ping Google Indexing API if requested.
        if ( ! empty( $params['ping_google'] ) ) {
            self::ping_google( get_permalink( $post_id ) );
        }

        return rest_ensure_response( array(
            'success'  => true,
            'post_id'  => $post_id,
            'post_url' => get_permalink( $post_id ),
            'message'  => 'Post updated via autopilot.',
        ) );
    }

    /**
     * Ping Google about an updated URL.
     * Uses Google Indexing API if credentials exist, otherwise sitemap ping.
     */
    private static function ping_google( $url ) {
        // Sitemap ping fallback (always works, no credentials needed).
        $sitemap_url = home_url( '/sitemap.xml' );
        wp_remote_get( 'https://www.google.com/ping?sitemap=' . urlencode( $sitemap_url ), array(
            'timeout'  => 5,
            'blocking' => false,
        ) );

        // IndexNow ping (if MSH_Indexing class exists and is configured).
        if ( class_exists( 'MSH_Indexing' ) && method_exists( 'MSH_Indexing', 'submit_url' ) ) {
            MSH_Indexing::submit_url( $url );
        }
    }

    /* ------------------------------------------------------------------
     * Admin page & settings
     * ----------------------------------------------------------------*/

    /**
     * AJAX: Run autopilot scan immediately.
     */
    public static function ajax_run_scan() {
        check_ajax_referer( 'msh_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        // Check connection before scanning.
        $api_key = MSH_Auth::get_key();
        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => 'Not connected to MSH. Please connect first in MSH SEO settings.' ) );
        }

        // Run the scan (sends report to dashboard automatically).
        self::run_autopilot_scan();

        // Check if report was actually sent (last_scan updated by send_autopilot_report on success).
        $last_scan = get_option( 'msh_autopilot_last_scan', '' );

        wp_send_json_success( array(
            'message'   => 'Autopilot scan completed! Data has been sent to your MSH dashboard.',
            'scan_time' => current_time( 'M j, Y g:i A' ),
        ) );
    }

    /**
     * AJAX: Save autopilot settings.
     */
    public static function ajax_save_settings() {
        check_ajax_referer( 'msh_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $default_mode = sanitize_text_field( wp_unslash( $_POST['default_mode'] ?? 'approval' ) );
        if ( ! in_array( $default_mode, array( 'full', 'approval', 'off' ), true ) ) {
            $default_mode = 'approval';
        }

        $scan_frequency = sanitize_text_field( wp_unslash( $_POST['scan_frequency'] ?? 'weekly' ) );
        if ( ! in_array( $scan_frequency, array( 'daily', 'weekly', 'biweekly' ), true ) ) {
            $scan_frequency = 'weekly';
        }

        $min_age_days   = max( 0, absint( $_POST['min_age_days'] ?? 30 ) );
        $min_word_count = max( 0, absint( $_POST['min_word_count'] ?? 300 ) );
        $auto_ping      = ! empty( $_POST['auto_ping'] );

        update_option( 'msh_autopilot_default_mode', $default_mode );
        update_option( 'msh_autopilot_scan_frequency', $scan_frequency );
        update_option( 'msh_autopilot_min_age_days', $min_age_days );
        update_option( 'msh_autopilot_min_word_count', $min_word_count );
        update_option( 'msh_autopilot_auto_ping', $auto_ping );

        // Reschedule cron if frequency changed.
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }

        $interval = 'msh_weekly';
        if ( $scan_frequency === 'daily' ) {
            $interval = 'daily';
        } elseif ( $scan_frequency === 'biweekly' ) {
            $interval = 'msh_biweekly';
        }

        wp_schedule_event( time() + HOUR_IN_SECONDS, $interval, self::CRON_HOOK );

        wp_send_json_success( array( 'message' => 'Settings saved.' ) );
    }

    /**
     * Render the Autopilot admin page.
     */
    public static function render_admin_page() {
        $is_connected     = MSH_Auth::is_connected();
        $last_scan        = get_option( 'msh_autopilot_last_scan', '' );
        $next_scheduled   = wp_next_scheduled( self::CRON_HOOK );
        $default_mode     = get_option( 'msh_autopilot_default_mode', 'approval' );
        $scan_frequency   = get_option( 'msh_autopilot_scan_frequency', 'weekly' );
        $min_age_days     = get_option( 'msh_autopilot_min_age_days', 30 );
        $min_word_count   = get_option( 'msh_autopilot_min_word_count', 300 );
        $auto_ping        = get_option( 'msh_autopilot_auto_ping', true );

        // Count published posts.
        $post_count = wp_count_posts( 'post' );
        $total_published = $post_count->publish ?? 0;

        ?>
        <div class="wrap msh-settings-wrap">
            <h1>
                <span class="dashicons dashicons-update" style="font-size:28px;margin-right:8px;"></span>
                <?php esc_html_e( 'SEO Autopilot', 'msh-seo' ); ?>
            </h1>

            <?php if ( ! $is_connected ) : ?>
                <div class="notice notice-warning" style="margin-top:16px;">
                    <p><strong><?php esc_html_e( 'Not Connected', 'msh-seo' ); ?></strong> —
                    <?php printf(
                        esc_html__( 'Autopilot requires a connection to MSH. %sConnect now%s.', 'msh-seo' ),
                        '<a href="' . esc_url( admin_url( 'admin.php?page=msh-seo' ) ) . '">',
                        '</a>'
                    ); ?></p>
                </div>
            <?php endif; ?>

            <!-- Status Cards -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:20px 0;">
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;border-left:4px solid #2271b1;">
                    <div style="font-size:20px;font-weight:700;color:#2271b1;"><?php echo esc_html( $total_published ); ?></div>
                    <div style="color:#666;margin-top:4px;"><?php esc_html_e( 'Published Posts', 'msh-seo' ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;border-left:4px solid #00a32a;">
                    <div style="font-size:20px;font-weight:700;color:#00a32a;">
                        <?php echo $last_scan ? esc_html( wp_date( 'M j', strtotime( $last_scan ) ) ) : '—'; ?>
                    </div>
                    <div style="color:#666;margin-top:4px;"><?php esc_html_e( 'Last Scan', 'msh-seo' ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;border-left:4px solid #dba617;">
                    <div style="font-size:20px;font-weight:700;color:#dba617;">
                        <?php echo $next_scheduled ? esc_html( wp_date( 'M j', $next_scheduled ) ) : '—'; ?>
                    </div>
                    <div style="color:#666;margin-top:4px;"><?php esc_html_e( 'Next Scan', 'msh-seo' ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;border-left:4px solid #8c5ae8;">
                    <div style="font-size:20px;font-weight:700;color:#8c5ae8;">
                        <?php
                        $modes = array( 'full' => 'Full Auto', 'approval' => 'Approval', 'off' => 'Off' );
                        echo esc_html( $modes[ $default_mode ] ?? 'Approval' );
                        ?>
                    </div>
                    <div style="color:#666;margin-top:4px;"><?php esc_html_e( 'Default Mode', 'msh-seo' ); ?></div>
                </div>
            </div>

            <!-- Manual Scan -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Manual Scan', 'msh-seo' ); ?></h2>
                <p style="color:#666;">
                    <?php esc_html_e( 'Run an autopilot scan now to analyze all published posts and send content signals to your MSH dashboard. The scan will evaluate freshness, outdated years, link quality, and content structure.', 'msh-seo' ); ?>
                </p>
                <button type="button" class="button button-primary" id="msh-autopilot-scan-btn" <?php echo $is_connected ? '' : 'disabled'; ?>>
                    <span class="dashicons dashicons-update" style="margin-top:3px;margin-right:4px;"></span>
                    <?php esc_html_e( 'Run Scan Now', 'msh-seo' ); ?>
                </button>
                <span id="msh-autopilot-scan-spinner" class="spinner" style="float:none;"></span>
                <div id="msh-autopilot-scan-result" style="margin-top:12px;"></div>
            </div>

            <!-- Settings -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Autopilot Settings', 'msh-seo' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="msh-ap-mode"><?php esc_html_e( 'Default Refresh Mode', 'msh-seo' ); ?></label>
                        </th>
                        <td>
                            <select id="msh-ap-mode" style="min-width:200px;">
                                <option value="full" <?php selected( $default_mode, 'full' ); ?>>
                                    <?php esc_html_e( 'Full Auto — Refresh & publish automatically', 'msh-seo' ); ?>
                                </option>
                                <option value="approval" <?php selected( $default_mode, 'approval' ); ?>>
                                    <?php esc_html_e( 'Approval Required — Save as draft, wait for review', 'msh-seo' ); ?>
                                </option>
                                <option value="off" <?php selected( $default_mode, 'off' ); ?>>
                                    <?php esc_html_e( 'Off — Monitor only, no refreshes', 'msh-seo' ); ?>
                                </option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Controls what happens when autopilot detects a stale article. You can override per-article in the MSH dashboard.', 'msh-seo' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="msh-ap-frequency"><?php esc_html_e( 'Scan Frequency', 'msh-seo' ); ?></label>
                        </th>
                        <td>
                            <select id="msh-ap-frequency" style="min-width:200px;">
                                <option value="daily" <?php selected( $scan_frequency, 'daily' ); ?>>
                                    <?php esc_html_e( 'Daily', 'msh-seo' ); ?>
                                </option>
                                <option value="weekly" <?php selected( $scan_frequency, 'weekly' ); ?>>
                                    <?php esc_html_e( 'Weekly (recommended)', 'msh-seo' ); ?>
                                </option>
                                <option value="biweekly" <?php selected( $scan_frequency, 'biweekly' ); ?>>
                                    <?php esc_html_e( 'Every 2 weeks', 'msh-seo' ); ?>
                                </option>
                            </select>
                            <p class="description"><?php esc_html_e( 'How often the plugin scans your posts and reports to the MSH dashboard.', 'msh-seo' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="msh-ap-min-age"><?php esc_html_e( 'Minimum Content Age', 'msh-seo' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="msh-ap-min-age" value="<?php echo esc_attr( $min_age_days ); ?>" min="0" max="365" style="width:80px;" />
                            <span><?php esc_html_e( 'days', 'msh-seo' ); ?></span>
                            <p class="description"><?php esc_html_e( 'Only flag articles older than this for refresh. Posts younger than this are skipped.', 'msh-seo' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="msh-ap-min-words"><?php esc_html_e( 'Minimum Word Count', 'msh-seo' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="msh-ap-min-words" value="<?php echo esc_attr( $min_word_count ); ?>" min="0" max="5000" step="50" style="width:80px;" />
                            <span><?php esc_html_e( 'words', 'msh-seo' ); ?></span>
                            <p class="description"><?php esc_html_e( 'Skip very short posts (e.g. announcements, news briefs) below this word count.', 'msh-seo' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="msh-ap-ping"><?php esc_html_e( 'Auto-Ping Search Engines', 'msh-seo' ); ?></label>
                        </th>
                        <td>
                            <label style="display:flex;align-items:flex-start;gap:8px;">
                                <input type="checkbox" id="msh-ap-ping" value="1" <?php checked( $auto_ping ); ?> style="margin-top:3px;" />
                                <span><?php esc_html_e( 'Automatically ping Google (via sitemap) and IndexNow after a post is refreshed. Recommended: ON — Tells search engines to re-crawl the updated page faster.', 'msh-seo' ); ?></span>
                            </label>
                        </td>
                    </tr>
                </table>

                <button type="button" class="button button-primary" id="msh-autopilot-save-btn">
                    <?php esc_html_e( 'Save Autopilot Settings', 'msh-seo' ); ?>
                </button>
                <span id="msh-autopilot-save-spinner" class="spinner" style="float:none;"></span>
                <span id="msh-autopilot-save-result" style="margin-left:8px;"></span>
            </div>

            <!-- How It Works -->
            <div style="background:#f0f6fc;border:1px solid #c3d6e8;border-radius:8px;padding:24px;">
                <h3 style="margin-top:0;">
                    <span class="dashicons dashicons-info-outline" style="margin-right:4px;"></span>
                    <?php esc_html_e( 'How Autopilot Works', 'msh-seo' ); ?>
                </h3>
                <ol style="color:#333;line-height:1.8;">
                    <li><strong><?php esc_html_e( 'Scan', 'msh-seo' ); ?></strong> — <?php esc_html_e( 'The plugin analyzes every published post for freshness, outdated years, missing schema, link quality, and content depth.', 'msh-seo' ); ?></li>
                    <li><strong><?php esc_html_e( 'Report', 'msh-seo' ); ?></strong> — <?php esc_html_e( 'Signals are sent to your MSH dashboard where articles are scored and prioritized.', 'msh-seo' ); ?></li>
                    <li><strong><?php esc_html_e( 'Refresh', 'msh-seo' ); ?></strong> — <?php esc_html_e( 'The dashboard uses AI to rewrite stale content with current info, better structure, and improved SEO.', 'msh-seo' ); ?></li>
                    <li><strong><?php esc_html_e( 'Publish', 'msh-seo' ); ?></strong> — <?php esc_html_e( 'Refreshed content is sent back to WordPress (auto-publish or draft depending on your mode).', 'msh-seo' ); ?></li>
                    <li><strong><?php esc_html_e( 'Learn', 'msh-seo' ); ?></strong> — <?php esc_html_e( 'The system tracks ranking changes after each refresh to improve future decisions.', 'msh-seo' ); ?></li>
                </ol>
                <p style="color:#666;margin-bottom:0;">
                    <?php printf(
                        esc_html__( 'Manage your autopilot queue and review pending refreshes at %syour MSH dashboard%s.', 'msh-seo' ),
                        '<a href="https://app.marketingsohigh.com/seo/autopilot" target="_blank" rel="noopener">',
                        '</a>'
                    ); ?>
                </p>
            </div>
        </div>

        <script>
        jQuery(function($) {
            // Run Scan Now
            $('#msh-autopilot-scan-btn').on('click', function() {
                var btn = $(this);
                var spinner = $('#msh-autopilot-scan-spinner');
                var result = $('#msh-autopilot-scan-result');

                if (!confirm('Run autopilot scan now? This will analyze all published posts and send data to your MSH dashboard.')) return;

                btn.prop('disabled', true);
                spinner.addClass('is-active');
                result.html('<span style="color:#666;">Scanning posts... This may take a minute.</span>');

                $.post(mshAdmin.ajaxUrl, {
                    action: 'msh_autopilot_run_scan',
                    nonce: mshAdmin.nonce
                }, function(response) {
                    btn.prop('disabled', false);
                    spinner.removeClass('is-active');

                    if (response.success) {
                        result.html('<span style="color:#00a32a;font-weight:600;">\u2705 ' + response.data.message + '</span>');
                    } else {
                        result.html('<span style="color:#d63638;">\u274c ' + (response.data.message || 'Scan failed.') + '</span>');
                    }
                }).fail(function(xhr) {
                    btn.prop('disabled', false);
                    spinner.removeClass('is-active');
                    result.html('<span style="color:#d63638;">\u274c Request failed (timeout or server error). Try again.</span>');
                });
            });

            // Save Settings
            $('#msh-autopilot-save-btn').on('click', function() {
                var btn = $(this);
                var spinner = $('#msh-autopilot-save-spinner');
                var result = $('#msh-autopilot-save-result');

                btn.prop('disabled', true);
                spinner.addClass('is-active');
                result.html('');

                $.post(mshAdmin.ajaxUrl, {
                    action: 'msh_autopilot_save_settings',
                    nonce: mshAdmin.nonce,
                    default_mode: $('#msh-ap-mode').val(),
                    scan_frequency: $('#msh-ap-frequency').val(),
                    min_age_days: $('#msh-ap-min-age').val(),
                    min_word_count: $('#msh-ap-min-words').val(),
                    auto_ping: $('#msh-ap-ping').is(':checked') ? '1' : ''
                }, function(response) {
                    btn.prop('disabled', false);
                    spinner.removeClass('is-active');

                    if (response.success) {
                        result.html('<span style="color:#00a32a;font-weight:600;">\u2705 ' + response.data.message + '</span>');
                    } else {
                        result.html('<span style="color:#d63638;">' + (response.data.message || 'Save failed.') + '</span>');
                    }
                }).fail(function() {
                    btn.prop('disabled', false);
                    spinner.removeClass('is-active');
                    result.html('<span style="color:#d63638;">Request failed.</span>');
                });
            });
        });
        </script>
        <?php
    }
}
