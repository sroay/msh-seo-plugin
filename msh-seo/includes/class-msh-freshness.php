<?php
/**
 * MSH Freshness Scanner
 *
 * Scans all published posts on a weekly cron schedule, calculates a
 * freshness score (0-100) for each post, stores scores as post meta,
 * adds a colored "Freshness" column to the Posts admin list, shows an
 * admin-bar notice when stale posts exist, and reports results back to
 * the MSH dashboard API.
 *
 * @package MSH_SEO
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Freshness {

    /** Cron hook name. */
    const CRON_HOOK = 'msh_seo_freshness_scan';

    /** Post-meta keys. */
    const META_SCORE   = '_msh_freshness_score';
    const META_CHECKED = '_msh_freshness_checked';

    /**
     * Wire up all hooks.
     */
    public static function init() {
        // Schedule cron on plugin activation.
        register_activation_hook(
            dirname( __DIR__ ) . '/msh-seo.php',
            array( __CLASS__, 'schedule_scan' )
        );

        // Unschedule on deactivation.
        register_deactivation_hook(
            dirname( __DIR__ ) . '/msh-seo.php',
            array( __CLASS__, 'unschedule_scan' )
        );

        // Register the custom weekly-monday schedule.
        add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );

        // Cron callback.
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_freshness_scan' ) );

        // Admin columns on the Posts list table.
        add_filter( 'manage_posts_columns',       array( __CLASS__, 'add_freshness_column' ) );
        add_action( 'manage_posts_custom_column',  array( __CLASS__, 'render_freshness_column' ), 10, 2 );
        add_filter( 'manage_edit-post_sortable_columns', array( __CLASS__, 'make_freshness_sortable' ) );
        add_action( 'pre_get_posts',               array( __CLASS__, 'sort_by_freshness' ) );

        // Admin-bar notice for stale posts.
        add_action( 'admin_bar_menu', array( __CLASS__, 'add_admin_bar_notice' ), 999 );

        // Inline CSS for the badge and admin-bar node.
        add_action( 'admin_head', array( __CLASS__, 'admin_inline_css' ) );
    }

    /* ------------------------------------------------------------------
     * Cron scheduling
     * ----------------------------------------------------------------*/

    /**
     * Register a custom "weekly_monday_3am" interval.
     *
     * @param  array $schedules Existing schedules.
     * @return array
     */
    public static function add_cron_schedule( $schedules ) {
        $schedules['weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display'  => __( 'Once Weekly', 'msh-seo' ),
        );
        return $schedules;
    }

    /**
     * Schedule the freshness scan for next Monday at 03:00 site time.
     */
    public static function schedule_scan() {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        // Calculate next Monday 03:00 in the site's timezone.
        $tz        = wp_timezone();
        $now       = new DateTimeImmutable( 'now', $tz );
        $next_mon  = new DateTimeImmutable( 'next Monday 03:00', $tz );

        // If today IS Monday and it's before 03:00, use today.
        if ( (int) $now->format( 'N' ) === 1 && $now->format( 'H:i' ) < '03:00' ) {
            $next_mon = new DateTimeImmutable( 'today 03:00', $tz );
        }

        wp_schedule_event( $next_mon->getTimestamp(), 'weekly', self::CRON_HOOK );
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
     * Run the full freshness scan across every published post.
     */
    public static function run_freshness_scan() {
        $posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );

        if ( empty( $posts ) ) {
            return;
        }

        $results = array(
            'total'  => count( $posts ),
            'fresh'  => 0,
            'current' => 0,
            'aging'  => 0,
            'stale'  => 0,
            'stale_posts' => array(),
        );

        $now_ts = current_time( 'timestamp' );

        foreach ( $posts as $post_id ) {
            $post  = get_post( $post_id );
            $score = self::calculate_freshness_score( $post, $now_ts );

            update_post_meta( $post_id, self::META_SCORE,   $score );
            update_post_meta( $post_id, self::META_CHECKED, gmdate( 'Y-m-d\TH:i:s\Z' ) );

            // Tally buckets.
            if ( $score >= 90 ) {
                $results['fresh']++;
            } elseif ( $score >= 70 ) {
                $results['current']++;
            } elseif ( $score >= 40 ) {
                $results['aging']++;
            } else {
                $results['stale']++;

                $modified_ts       = strtotime( $post->post_modified );
                $days_since        = max( 0, floor( ( $now_ts - $modified_ts ) / DAY_IN_SECONDS ) );

                $results['stale_posts'][] = array(
                    'id'                  => $post_id,
                    'title'               => get_the_title( $post_id ),
                    'score'               => $score,
                    'days_since_modified' => (int) $days_since,
                );
            }
        }

        // Cache stale count for admin-bar notice.
        update_option( 'msh_freshness_stale_count', $results['stale'], false );

        // Report to MSH dashboard.
        self::send_report_to_msh( $results );
    }

    /**
     * Calculate the freshness score for a single post.
     *
     * Algorithm:
     *   Base  = max(0, 100 - floor(days_since_modified * 0.5))
     *   -15   for each outdated year reference found in the content
     *   -10   if no numbers/statistics appear in content
     *   -10   if word count < 500
     *
     * @param  WP_Post $post   The post object.
     * @param  int     $now_ts Current site-time timestamp.
     * @return int              Score clamped to 0-100.
     */
    private static function calculate_freshness_score( $post, $now_ts ) {
        $modified_ts = strtotime( $post->post_modified );
        $days_since  = max( 0, floor( ( $now_ts - $modified_ts ) / DAY_IN_SECONDS ) );

        // Base score: decays 0.5 points per day.
        $score = max( 0, 100 - (int) floor( $days_since * 0.5 ) );

        // --- Penalty: outdated year references --------------------------
        $content      = wp_strip_all_tags( $post->post_content );
        $current_year = (int) gmdate( 'Y' );

        // Match any 4-digit year in the content.
        if ( preg_match_all( '/\b(20[0-9]{2})\b/', $content, $matches ) ) {
            $years_found = array_unique( array_map( 'intval', $matches[1] ) );
            foreach ( $years_found as $yr ) {
                if ( $yr < $current_year ) {
                    $score -= 15;
                }
            }
        }

        // --- Penalty: no statistics / numbers ---------------------------
        // Look for any standalone number (not a year-like 20xx).
        $content_no_years = preg_replace( '/\b20[0-9]{2}\b/', '', $content );
        if ( ! preg_match( '/\d+/', $content_no_years ) ) {
            $score -= 10;
        }

        // --- Penalty: thin content (< 500 words) -----------------------
        $word_count = str_word_count( $content );
        if ( $word_count < 500 ) {
            $score -= 10;
        }

        return max( 0, min( 100, $score ) );
    }

    /* ------------------------------------------------------------------
     * MSH Dashboard API report
     * ----------------------------------------------------------------*/

    /**
     * POST a freshness summary to the MSH dashboard.
     *
     * @param array $results Aggregated scan results.
     */
    private static function send_report_to_msh( $results ) {
        $api_key = MSH_Auth::get_key();

        if ( empty( $api_key ) ) {
            return;
        }

        $payload = array(
            'website_url'  => home_url(),
            'total_posts'  => $results['total'],
            'fresh'        => $results['fresh'],
            'current'      => $results['current'],
            'aging'        => $results['aging'],
            'stale'        => $results['stale'],
            'stale_posts'  => $results['stale_posts'],
        );

        wp_remote_post( MSH_API::BASE_URL . '/freshness-report', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'User-Agent'    => 'MSH-SEO-WP/' . MSH_SEO_VERSION,
            ),
            'body'    => wp_json_encode( $payload ),
        ) );
    }

    /* ------------------------------------------------------------------
     * Admin UI — Posts list "Freshness" column
     * ----------------------------------------------------------------*/

    /**
     * Register the Freshness column.
     *
     * @param  array $columns Existing columns.
     * @return array
     */
    public static function add_freshness_column( $columns ) {
        $columns['msh_freshness'] = __( 'Freshness', 'msh-seo' );
        return $columns;
    }

    /**
     * Render the badge inside the Freshness column.
     *
     * @param string $column  Column slug.
     * @param int    $post_id Post ID.
     */
    public static function render_freshness_column( $column, $post_id ) {
        if ( 'msh_freshness' !== $column ) {
            return;
        }

        $score = (int) get_post_meta( $post_id, self::META_SCORE, true );

        if ( ! $score && '0' !== get_post_meta( $post_id, self::META_SCORE, true ) ) {
            echo '<span class="msh-freshness-badge" style="background:#999;color:#fff;">N/A</span>';
            return;
        }

        if ( $score >= 90 ) {
            $color = '#16a34a'; // green
            $label = __( 'Fresh', 'msh-seo' );
        } elseif ( $score >= 70 ) {
            $color = '#65a30d'; // light green
            $label = __( 'Current', 'msh-seo' );
        } elseif ( $score >= 40 ) {
            $color = '#d97706'; // yellow/orange
            $label = __( 'Aging', 'msh-seo' );
        } else {
            $color = '#dc2626'; // red
            $label = __( 'Stale', 'msh-seo' );
        }

        printf(
            '<span class="msh-freshness-badge" style="background:%s;color:#fff;">%d &mdash; %s</span>',
            esc_attr( $color ),
            $score,
            esc_html( $label )
        );
    }

    /**
     * Make the column sortable.
     *
     * @param  array $columns Sortable columns.
     * @return array
     */
    public static function make_freshness_sortable( $columns ) {
        $columns['msh_freshness'] = 'msh_freshness';
        return $columns;
    }

    /**
     * Handle ordering by freshness score.
     *
     * @param WP_Query $query Main query.
     */
    public static function sort_by_freshness( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( 'msh_freshness' === $query->get( 'orderby' ) ) {
            $query->set( 'meta_key', self::META_SCORE );
            $query->set( 'orderby',  'meta_value_num' );
        }
    }

    /* ------------------------------------------------------------------
     * Admin bar notice
     * ----------------------------------------------------------------*/

    /**
     * Show a warning in the admin bar when stale posts exist.
     *
     * @param WP_Admin_Bar $wp_admin_bar Admin-bar instance.
     */
    public static function add_admin_bar_notice( $wp_admin_bar ) {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        $stale = (int) get_option( 'msh_freshness_stale_count', 0 );

        if ( $stale < 1 ) {
            return;
        }

        $wp_admin_bar->add_node( array(
            'id'    => 'msh-freshness-notice',
            'title' => sprintf(
                /* translators: %d: number of stale posts */
                __( 'MSH SEO: %d posts need refresh', 'msh-seo' ),
                $stale
            ),
            'href'  => admin_url( 'edit.php?orderby=msh_freshness&order=asc' ),
            'meta'  => array(
                'class' => 'msh-freshness-alert',
            ),
        ) );
    }

    /* ------------------------------------------------------------------
     * Inline CSS
     * ----------------------------------------------------------------*/

    /**
     * Print badge + admin-bar styles.
     */
    public static function admin_inline_css() {
        ?>
        <style>
            .msh-freshness-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
                line-height: 1.6;
                white-space: nowrap;
            }
            #wp-admin-bar-msh-freshness-notice .ab-item {
                color: #fff !important;
                background: #dc2626 !important;
            }
            #wp-admin-bar-msh-freshness-notice:hover .ab-item {
                background: #b91c1c !important;
            }
        </style>
        <?php
    }
}
