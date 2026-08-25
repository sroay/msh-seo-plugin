<?php
/**
 * MSH SEO Command Center
 *
 * Best-in-class analytics dashboard: site-wide SEO score, prioritized
 * issues with one-click auto-fixes, content health, technical SEO,
 * 404 monitor, image audit, AI readiness, and contextual upgrade CTAs.
 *
 * @package MSH_SEO
 * @since   0.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Analytics {

    /** Cache duration for heavy queries (30 min). */
    const CACHE_TTL = 1800;

    /**
     * Wire up AJAX handlers.
     */
    public static function init() {
        add_action( 'wp_ajax_msh_refresh_analytics',   array( __CLASS__, 'ajax_refresh' ) );
        add_action( 'wp_ajax_msh_fix_meta_descriptions', array( __CLASS__, 'ajax_fix_meta_descriptions' ) );
        add_action( 'wp_ajax_msh_fix_image_alt',       array( __CLASS__, 'ajax_fix_image_alt' ) );
        add_action( 'wp_ajax_msh_bulk_analyze',        array( __CLASS__, 'ajax_bulk_analyze' ) );
        add_action( 'wp_ajax_msh_create_redirect',     array( __CLASS__, 'ajax_create_redirect' ) );
        add_action( 'wp_ajax_msh_clear_404_log',       array( __CLASS__, 'ajax_clear_404_log' ) );
        add_action( 'wp_ajax_msh_run_freshness_scan',  array( __CLASS__, 'ajax_run_freshness_scan' ) );
    }

    /**
     * AJAX: run a content-freshness scan now (instead of waiting for the
     * weekly cron), then clear the analytics caches so the page shows the
     * fresh buckets on reload.
     */
    public static function ajax_run_freshness_scan() {
        check_ajax_referer( 'msh_analytics_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }
        if ( ! class_exists( 'MSH_Freshness' ) || ! method_exists( 'MSH_Freshness', 'run_freshness_scan' ) ) {
            wp_send_json_error( array( 'message' => 'Freshness module unavailable.' ) );
        }
        MSH_Freshness::run_freshness_scan();
        delete_transient( 'msh_analytics_health' );
        delete_transient( 'msh_analytics_extended' );
        wp_send_json_success( array( 'message' => 'Freshness scan complete.' ) );
    }

    /**
     * Append today's site score to the rolling history (option-backed, 90-day
     * cap) and return the series — powers the score-trend sparkline. One
     * snapshot per day, taken whenever the page renders.
     *
     * @param int $score Composite site score 0-100.
     * @return array<int,array{d:string,s:int}>
     */
    private static function update_score_history( $score ) {
        $history = get_option( 'msh_seo_score_history', array() );
        if ( ! is_array( $history ) ) {
            $history = array();
        }
        $today = current_time( 'Y-m-d' );
        $last  = end( $history );
        if ( ! is_array( $last ) || ! isset( $last['d'] ) || $last['d'] !== $today ) {
            $history[] = array( 'd' => $today, 's' => (int) $score );
            $history   = array_slice( $history, -90 );
            update_option( 'msh_seo_score_history', $history, false );
        } else {
            // Same-day rerender: keep the latest value.
            $history[ count( $history ) - 1 ]['s'] = (int) $score;
            update_option( 'msh_seo_score_history', $history, false );
        }
        return $history;
    }

    /**
     * Count published posts carrying a quotable answer block ([msh_answer] /
     * .msh-answer) — the unit AI engines cite and Speakable schema targets.
     * Cached 30 minutes.
     *
     * @return int
     */
    private static function count_speakable_posts() {
        $cached = get_transient( 'msh_speakable_count' );
        if ( false !== $cached ) {
            return (int) $cached;
        }
        global $wpdb;
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ('post','page')
               AND (post_content LIKE '%msh_answer%' OR post_content LIKE '%msh-answer%')"
        );
        set_transient( 'msh_speakable_count', $count, 30 * MINUTE_IN_SECONDS );
        return $count;
    }

    /* ==================================================================
     * AJAX Handlers — Auto-fix actions
     * ================================================================*/

    /**
     * Refresh all analytics caches.
     */
    public static function ajax_refresh() {
        check_ajax_referer( 'msh_analytics_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }
        delete_transient( 'msh_analytics_health' );
        delete_transient( 'msh_analytics_dashboard' );
        delete_transient( 'msh_analytics_extended' );
        wp_send_json_success( array( 'message' => 'Cache cleared.' ) );
    }

    /**
     * Auto-generate meta descriptions from post content for posts missing them.
     */
    public static function ajax_fix_meta_descriptions() {
        check_ajax_referer( 'msh_analytics_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        // Find posts that either have NO description or have a GARBLED one.
        // First: posts with no description at all.
        $posts_no_desc = get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'meta_query'     => array(
                'relation' => 'OR',
                array( 'key' => '_msh_seo_description', 'compare' => 'NOT EXISTS' ),
                array( 'key' => '_msh_seo_description', 'value' => '', 'compare' => '=' ),
            ),
        ) );

        // Second: posts with garbled descriptions (from old Divi shortcode stripping).
        $posts_with_desc = get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'meta_query'     => array(
                array( 'key' => '_msh_seo_description', 'value' => '', 'compare' => '!=' ),
            ),
        ) );

        // Filter to only garbled ones: must have BOTH a lowercase→uppercase
        // transition AND a long unbroken letter run (13+ chars).
        $posts_garbled = array();
        foreach ( $posts_with_desc as $p ) {
            $stored = get_post_meta( $p->ID, '_msh_seo_description', true );
            $has_transition = preg_match_all( '/[a-z][A-Z]/', $stored ) >= 1;
            $has_long_run   = preg_match_all( '/[a-zA-Z]{13,}/', $stored ) >= 1;
            if ( $has_transition && $has_long_run ) {
                $posts_garbled[] = $p;
            }
        }

        $posts = array_merge( $posts_no_desc, $posts_garbled );

        $fixed = 0;
        foreach ( $posts as $post ) {
            // Start from the raw post content.
            $content = $post->post_content;
            // Replace ALL shortcode tags with spaces to prevent word concatenation.
            $content = preg_replace( '/\[[^\]]*\]/', ' ', $content );
            // Add spaces after closing HTML tags to prevent word concatenation.
            $content = preg_replace( '/>(\s*)/', '> ', $content );
            $content = wp_strip_all_tags( $content );
            // Remove Table of Contents text.
            $content = preg_replace( '/^Table of Contents\s*(Show|Hide)?\s*((\d+(\.\d+)*\s+[^\d]+\s*)+)/i', '', $content );
            $content = preg_replace( '/^Table of Contents\s*/i', '', $content );
            // Clean up excessive whitespace.
            $content = preg_replace( '/\s+/', ' ', trim( $content ) );

            if ( empty( $content ) ) {
                continue;
            }

            // Take first ~155 chars, break at sentence or word boundary.
            if ( mb_strlen( $content ) > 155 ) {
                $desc = mb_substr( $content, 0, 155 );
                $last_period = mb_strrpos( $desc, '.' );
                $last_space  = mb_strrpos( $desc, ' ' );
                if ( $last_period && $last_period > 80 ) {
                    $desc = mb_substr( $desc, 0, $last_period + 1 );
                } elseif ( $last_space ) {
                    $desc = mb_substr( $desc, 0, $last_space ) . '...';
                }
            } else {
                $desc = $content;
            }

            update_post_meta( $post->ID, '_msh_seo_description', sanitize_text_field( $desc ) );
            $fixed++;
        }

        delete_transient( 'msh_analytics_health' );
        delete_transient( 'msh_analytics_extended' );

        wp_send_json_success( array(
            'fixed'     => $fixed,
            'remaining' => max( 0, count( $posts ) - $fixed ),
            'message'   => sprintf( '%d meta descriptions generated.', $fixed ),
        ) );
    }

    /**
     * Auto-fix missing alt text on images using filename or post context.
     */
    public static function ajax_fix_image_alt() {
        check_ajax_referer( 'msh_analytics_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        global $wpdb;

        // Get images without alt text (up to 50).
        $image_ids = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment'
             AND p.post_mime_type LIKE 'image/%'
             AND p.post_status = 'inherit'
             AND p.ID NOT IN (
                 SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 WHERE pm.meta_key = '_wp_attachment_image_alt'
                 AND pm.meta_value != ''
             )
             ORDER BY p.post_date DESC
             LIMIT 50"
        );

        $fixed = 0;
        foreach ( $image_ids as $img_id ) {
            $file = get_attached_file( $img_id );
            if ( ! $file ) {
                continue;
            }

            // Derive alt text from filename.
            $filename = pathinfo( $file, PATHINFO_FILENAME );
            $alt = str_replace( array( '-', '_' ), ' ', $filename );
            $alt = ucfirst( trim( preg_replace( '/\s+/', ' ', $alt ) ) );

            // Remove common prefixes like IMG_, DSC_, etc.
            $alt = preg_replace( '/^(IMG|DSC|Screenshot|Photo|Image)\s*/i', '', $alt );
            $alt = trim( $alt );

            if ( empty( $alt ) ) {
                $alt = get_the_title( $img_id );
            }

            if ( ! empty( $alt ) ) {
                update_post_meta( $img_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
                $fixed++;
            }
        }

        delete_transient( 'msh_analytics_extended' );

        wp_send_json_success( array(
            'fixed'   => $fixed,
            'message' => sprintf( '%d image alt texts generated.', $fixed ),
        ) );
    }

    /**
     * Bulk-analyze unscored posts.
     */
    public static function ajax_bulk_analyze() {
        check_ajax_referer( 'msh_analytics_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        if ( ! class_exists( 'MSH_SEO_Analysis' ) ) {
            wp_send_json_error( 'SEO Analysis module not available.' );
        }

        $posts = get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => 30,
            'meta_query'     => array(
                array( 'key' => '_msh_seo_score', 'compare' => 'NOT EXISTS' ),
            ),
        ) );

        $analyzed = 0;
        foreach ( $posts as $post ) {
            $keyword = get_post_meta( $post->ID, '_msh_focus_keyword', true );
            $result  = MSH_SEO_Analysis::analyze_post( $post->ID, $keyword ?: '' );
            if ( $result && isset( $result['score'] ) ) {
                update_post_meta( $post->ID, '_msh_seo_score', (int) $result['score'] );
                $analyzed++;
            }
        }

        delete_transient( 'msh_analytics_health' );
        delete_transient( 'msh_analytics_extended' );

        wp_send_json_success( array(
            'analyzed' => $analyzed,
            'message'  => sprintf( '%d posts analyzed and scored.', $analyzed ),
        ) );
    }

    /**
     * Create a redirect from a 404 URL.
     */
    public static function ajax_create_redirect() {
        check_ajax_referer( 'msh_analytics_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
        $target = isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( $_POST['target'] ) ) : '';

        if ( empty( $source ) || empty( $target ) ) {
            wp_send_json_error( 'Source and target URLs are required.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'msh_redirects';

        // Create table if it doesn't exist (handles fresh installs and ZIP upgrades).
        $charset_collate = $wpdb->get_charset_collate();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_url VARCHAR(500) NOT NULL,
            target_url VARCHAR(500) NOT NULL,
            redirect_type INT(3) NOT NULL DEFAULT 301,
            hits BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_url (source_url(191))
        ) {$charset_collate}" );

        $wpdb->replace(
            $table,
            array(
                'source_url'    => $source,
                'target_url'    => $target,
                'redirect_type' => 301,
            ),
            array( '%s', '%s', '%d' )
        );

        // Remove from 404 log.
        $log_table = $wpdb->prefix . 'msh_404_log';
        $wpdb->delete( $log_table, array( 'url' => $source ), array( '%s' ) );

        delete_transient( 'msh_analytics_extended' );

        wp_send_json_success( array( 'message' => 'Redirect created.' ) );
    }

    /**
     * Clear the 404 log.
     */
    public static function ajax_clear_404_log() {
        check_ajax_referer( 'msh_analytics_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'msh_404_log';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            $wpdb->query( "TRUNCATE TABLE {$table}" );
        }

        delete_transient( 'msh_analytics_extended' );
        wp_send_json_success( array( 'message' => '404 log cleared.' ) );
    }

    /* ==================================================================
     * Data collection
     * ================================================================*/

    /**
     * Get core SEO health stats (cached).
     */
    public static function get_seo_health() {
        $cached = get_transient( 'msh_analytics_health' );
        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;

        $post_types = array( 'post', 'page' );
        if ( post_type_exists( 'product' ) ) {
            $post_types[] = 'product';
        }
        $ph = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        // Total counts per type.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $counts = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_type, COUNT(*) as cnt FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ({$ph}) GROUP BY post_type",
            ...$post_types
        ), OBJECT_K );

        $total_posts    = isset( $counts['post'] ) ? (int) $counts['post']->cnt : 0;
        $total_pages    = isset( $counts['page'] ) ? (int) $counts['page']->cnt : 0;
        $total_products = isset( $counts['product'] ) ? (int) $counts['product']->cnt : 0;
        $total_content  = $total_posts + $total_pages + $total_products;

        // SEO scores.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $scores = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE pm.meta_key='_msh_seo_score' AND p.post_status='publish' AND p.post_type IN ({$ph})",
            ...$post_types
        ) );

        $score_good = $score_needs_work = $score_poor = $score_sum = 0;
        foreach ( $scores as $v ) {
            $val = (int) $v;
            $score_sum += $val;
            if ( $val >= 70 ) { $score_good++; }
            elseif ( $val >= 40 ) { $score_needs_work++; }
            else { $score_poor++; }
        }

        $scored_count = count( $scores );
        $no_score     = $total_content - $scored_count;
        $avg_score    = $scored_count > 0 ? round( $score_sum / $scored_count ) : 0;

        // Missing meta desc.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $has_meta = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE pm.meta_key='_msh_seo_description' AND pm.meta_value!='' AND p.post_status='publish' AND p.post_type IN ({$ph})",
            ...$post_types
        ) );
        $missing_meta = $total_content - $has_meta;

        // Missing focus keyword.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $has_kw = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE pm.meta_key='_msh_focus_keyword' AND pm.meta_value!='' AND p.post_status='publish' AND p.post_type IN ({$ph})",
            ...$post_types
        ) );
        $missing_kw = $total_content - $has_kw;

        // Noindex count.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $noindex = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE pm.meta_key='_msh_seo_noindex' AND pm.meta_value='1' AND p.post_status='publish' AND p.post_type IN ({$ph})",
            ...$post_types
        ) );

        $health = array(
            'total_posts'      => $total_posts,
            'total_pages'      => $total_pages,
            'total_products'   => $total_products,
            'total_content'    => $total_content,
            'scored_count'     => $scored_count,
            'score_good'       => $score_good,
            'score_needs_work' => $score_needs_work,
            'score_poor'       => $score_poor,
            'no_score'         => $no_score,
            'avg_score'        => $avg_score,
            'missing_meta'     => $missing_meta,
            'missing_kw'       => $missing_kw,
            'noindex'          => $noindex,
        );

        set_transient( 'msh_analytics_health', $health, self::CACHE_TTL );
        return $health;
    }

    /**
     * Get extended analytics data (images, 404s, content depth, links, freshness, schema).
     * Cached separately because these queries are heavier.
     */
    public static function get_extended_data() {
        $cached = get_transient( 'msh_analytics_extended' );
        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;

        $data = array();

        // --- Images missing alt text ---
        $data['images_no_alt'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type='attachment' AND p.post_mime_type LIKE 'image/%' AND p.post_status='inherit'
             AND p.ID NOT IN (
                 SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 WHERE pm.meta_key='_wp_attachment_image_alt' AND pm.meta_value!=''
             )"
        );
        $data['images_total'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%' AND post_status='inherit'"
        );

        // --- 404 log ---
        $log_table = $wpdb->prefix . 'msh_404_log';
        $data['has_404_table'] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) ) === $log_table );
        $data['errors_404']    = array();
        $data['total_404']     = 0;
        if ( $data['has_404_table'] ) {
            $data['total_404'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log_table}" );
            $data['errors_404'] = $wpdb->get_results(
                "SELECT url, hits, referrer, last_hit FROM {$log_table} ORDER BY hits DESC, last_hit DESC LIMIT 10",
                ARRAY_A
            );
        }

        // --- Redirect stats ---
        $redir_table = $wpdb->prefix . 'msh_redirects';
        $data['has_redirect_table'] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $redir_table ) ) === $redir_table );
        $data['total_redirects'] = 0;
        $data['redirect_hits']   = 0;
        if ( $data['has_redirect_table'] ) {
            $data['total_redirects'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$redir_table}" );
            $data['redirect_hits']   = (int) $wpdb->get_var( "SELECT COALESCE(SUM(hits),0) FROM {$redir_table}" );
        }

        // --- Content depth (word count distribution) ---
        $posts_sample = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post' ORDER BY post_date DESC LIMIT 200"
        );
        $wc_thin = $wc_medium = $wc_long = $wc_comprehensive = 0;
        $word_counts = array();
        foreach ( $posts_sample as $pid ) {
            $content = get_post_field( 'post_content', $pid );
            $wc = str_word_count( wp_strip_all_tags( strip_shortcodes( $content ) ) );
            $word_counts[] = $wc;
            if ( $wc < 300 )      { $wc_thin++; }
            elseif ( $wc < 1000 ) { $wc_medium++; }
            elseif ( $wc < 2000 ) { $wc_long++; }
            else                  { $wc_comprehensive++; }
        }
        $data['content_depth'] = array(
            'thin'          => $wc_thin,
            'medium'        => $wc_medium,
            'long'          => $wc_long,
            'comprehensive' => $wc_comprehensive,
            'avg_words'     => count( $word_counts ) > 0 ? round( array_sum( $word_counts ) / count( $word_counts ) ) : 0,
            'sample_size'   => count( $word_counts ),
        );

        // --- Internal/external link stats (sample of 50 recent posts) ---
        $link_sample = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post' ORDER BY post_date DESC LIMIT 50"
        );
        $int_links = $ext_links = $no_int_links = 0;
        $home = home_url();
        foreach ( $link_sample as $pid ) {
            $content = get_post_field( 'post_content', $pid );
            preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );
            $post_int = 0;
            $post_ext = 0;
            foreach ( $matches[1] as $href ) {
                if ( strpos( $href, $home ) === 0 || strpos( $href, '/' ) === 0 ) {
                    $post_int++;
                } elseif ( strpos( $href, 'http' ) === 0 ) {
                    $post_ext++;
                }
            }
            $int_links += $post_int;
            $ext_links += $post_ext;
            if ( $post_int === 0 ) { $no_int_links++; }
        }
        $link_count = count( $link_sample );
        $data['links'] = array(
            'avg_internal'      => $link_count > 0 ? round( $int_links / $link_count, 1 ) : 0,
            'avg_external'      => $link_count > 0 ? round( $ext_links / $link_count, 1 ) : 0,
            'posts_no_internal' => $no_int_links,
            'sample_size'       => $link_count,
        );

        // --- Freshness stats ---
        $data['freshness'] = array( 'fresh' => 0, 'current' => 0, 'aging' => 0, 'stale' => 0 );
        $fresh_scores = $wpdb->get_col(
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE pm.meta_key='_msh_freshness_score' AND p.post_status='publish' AND p.post_type='post'"
        );
        foreach ( $fresh_scores as $fs ) {
            $v = (int) $fs;
            if ( $v >= 90 )     { $data['freshness']['fresh']++; }
            elseif ( $v >= 70 ) { $data['freshness']['current']++; }
            elseif ( $v >= 40 ) { $data['freshness']['aging']++; }
            else                { $data['freshness']['stale']++; }
        }
        $data['freshness']['total'] = count( $fresh_scores );

        // --- Schema coverage ---
        $data['schema_with'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE pm.meta_key='_msh_schema_type' AND pm.meta_value!='' AND pm.meta_value!='none'
             AND p.post_status='publish'"
        );

        // --- AI crawler settings ---
        $data['crawler_settings'] = get_option( 'msh_crawler_settings', array( 'blocked' => array() ) );

        // --- IndexNow ---
        $data['indexnow'] = array();
        if ( class_exists( 'MSH_Indexing' ) ) {
            $raw = MSH_Indexing::get_recent_submissions();
            foreach ( $raw as $entry ) {
                $urls = isset( $entry['urls'] ) && is_array( $entry['urls'] ) ? $entry['urls'] : array();
                foreach ( $urls as $url ) {
                    $data['indexnow'][] = array(
                        'url'    => $url,
                        'date'   => $entry['timestamp'] ?? '',
                        'ok'     => ! empty( $entry['success'] ),
                    );
                }
            }
        }

        set_transient( 'msh_analytics_extended', $data, self::CACHE_TTL );
        return $data;
    }

    /**
     * Build the prioritized issues list with actions.
     */
    private static function get_issues( $health, $ext ) {
        $issues = array();
        $is_connected = class_exists( 'MSH_Auth' ) && MSH_Auth::is_connected();

        // Critical issues first.
        if ( $health['no_score'] > 0 ) {
            $issues[] = array(
                'severity' => 'critical',
                'icon'     => 'dashicons-warning',
                'title'    => sprintf( '%d posts have never been analyzed', $health['no_score'] ),
                'desc'     => 'Unscored content cannot be optimized. Run bulk analysis to score all posts instantly.',
                'action'   => 'ajax',
                'btn'      => 'Analyze All Now',
                'ajax'     => 'msh_bulk_analyze',
                'upgrade'  => '',
            );
        }

        if ( $health['missing_meta'] > 0 ) {
            $issues[] = array(
                'severity' => $health['missing_meta'] > 10 ? 'critical' : 'warning',
                'icon'     => 'dashicons-edit',
                'title'    => sprintf( '%d posts missing meta description', $health['missing_meta'] ),
                'desc'     => 'Search engines may show random content for these pages. Auto-fix extracts a clean summary from each post.',
                'action'   => 'ajax',
                'btn'      => 'Auto-Generate All',
                'ajax'     => 'msh_fix_meta_descriptions',
                'upgrade'  => $is_connected ? '' : 'Connect to MSH for AI-powered meta descriptions that convert better.',
            );
        }

        if ( $ext['images_no_alt'] > 0 ) {
            $issues[] = array(
                'severity' => $ext['images_no_alt'] > 20 ? 'critical' : 'warning',
                'icon'     => 'dashicons-format-image',
                'title'    => sprintf( '%d images missing alt text', $ext['images_no_alt'] ),
                'desc'     => 'Alt text helps search engines understand images and improves accessibility. Auto-fix derives alt from filenames.',
                'action'   => 'ajax',
                'btn'      => 'Auto-Fix Alt Text',
                'ajax'     => 'msh_fix_image_alt',
                'upgrade'  => $is_connected ? '' : 'Connect to MSH for AI-powered alt text that describes images accurately.',
            );
        }

        if ( $health['missing_kw'] > 0 ) {
            $issues[] = array(
                'severity' => 'warning',
                'icon'     => 'dashicons-search',
                'title'    => sprintf( '%d posts missing focus keyword', $health['missing_kw'] ),
                'desc'     => 'Without a focus keyword, SEO analysis cannot fully optimize your content.',
                'action'   => 'link',
                'btn'      => 'View Posts',
                'url'      => admin_url( 'edit.php?orderby=meta_value_num&meta_key=_msh_seo_score&order=asc' ),
                'upgrade'  => $is_connected ? '' : 'Connect to MSH for AI keyword suggestions based on your content.',
            );
        }

        if ( $ext['total_404'] > 0 ) {
            $issues[] = array(
                'severity' => $ext['total_404'] > 10 ? 'critical' : 'warning',
                'icon'     => 'dashicons-dismiss',
                'title'    => sprintf( '%d broken URLs returning 404 errors', $ext['total_404'] ),
                'desc'     => 'Visitors and search engines are hitting dead pages. Create redirects below to rescue this traffic.',
                'action'   => 'scroll',
                'btn'      => 'View 404s Below',
                'target'   => 'msh-404-section',
                'upgrade'  => '',
            );
        }

        if ( $ext['content_depth']['thin'] > 0 ) {
            $issues[] = array(
                'severity' => 'warning',
                'icon'     => 'dashicons-editor-alignleft',
                'title'    => sprintf( '%d posts have thin content (under 300 words)', $ext['content_depth']['thin'] ),
                'desc'     => 'Thin content rarely ranks well. Consider expanding these posts or merging them.',
                'action'   => 'link',
                'btn'      => 'View Thin Posts',
                'url'      => admin_url( 'edit.php?orderby=meta_value_num&meta_key=_msh_seo_score&order=asc' ),
                'upgrade'  => $is_connected ? '' : 'Connect to MSH Autopilot to automatically refresh thin content with AI.',
            );
        }

        if ( $ext['links']['posts_no_internal'] > 0 ) {
            $issues[] = array(
                'severity' => 'warning',
                'icon'     => 'dashicons-admin-links',
                'title'    => sprintf( '%d posts have zero internal links', $ext['links']['posts_no_internal'] ),
                'desc'     => 'Internal links help search engines discover your content and pass authority between pages.',
                'action'   => 'link',
                'btn'      => 'View Posts',
                'url'      => admin_url( 'edit.php' ),
                'upgrade'  => '',
            );
        }

        if ( $ext['freshness']['stale'] > 0 ) {
            $issues[] = array(
                'severity' => 'info',
                'icon'     => 'dashicons-clock',
                'title'    => sprintf( '%d posts are stale and need refreshing', $ext['freshness']['stale'] ),
                'desc'     => 'Content older than 6 months with outdated references loses ranking over time.',
                'action'   => 'link',
                'btn'      => 'View Stale Posts',
                'url'      => admin_url( 'edit.php?orderby=msh_freshness&order=asc' ),
                'upgrade'  => $is_connected ? '' : 'Connect to MSH Autopilot for automatic content refreshing.',
            );
        }

        if ( $health['score_poor'] > 0 ) {
            $issues[] = array(
                'severity' => 'info',
                'icon'     => 'dashicons-thumbs-down',
                'title'    => sprintf( '%d posts scored below 40 (poor SEO)', $health['score_poor'] ),
                'desc'     => 'These posts need significant optimization to rank in search results.',
                'action'   => 'link',
                'btn'      => 'Optimize Now',
                'url'      => admin_url( 'edit.php?orderby=meta_value_num&meta_key=_msh_seo_score&order=asc' ),
                'upgrade'  => '',
            );
        }

        return $issues;
    }

    /**
     * Calculate site-wide SEO score (0-100) from all sub-metrics.
     */
    private static function calculate_site_score( $health, $ext ) {
        $score   = 0;
        $weights = 0;

        // 1. SEO score coverage (30 points).
        $w = 30;
        $weights += $w;
        if ( $health['total_content'] > 0 && $health['scored_count'] > 0 ) {
            $good_pct = $health['score_good'] / $health['total_content'];
            $score += $w * min( 1, $good_pct * 1.5 );
        }

        // 2. Meta description coverage (20 points).
        $w = 20;
        $weights += $w;
        if ( $health['total_content'] > 0 ) {
            $meta_pct = 1 - ( $health['missing_meta'] / $health['total_content'] );
            $score += $w * $meta_pct;
        }

        // 3. Image alt coverage (15 points).
        $w = 15;
        $weights += $w;
        if ( $ext['images_total'] > 0 ) {
            $alt_pct = 1 - ( $ext['images_no_alt'] / $ext['images_total'] );
            $score += $w * $alt_pct;
        } else {
            $score += $w;
        }

        // 4. Content freshness (15 points).
        $w = 15;
        $weights += $w;
        $ft = $ext['freshness']['total'];
        if ( $ft > 0 ) {
            $fresh_pct = ( $ext['freshness']['fresh'] + $ext['freshness']['current'] ) / $ft;
            $score += $w * $fresh_pct;
        } else {
            $score += $w * 0.5;
        }

        // 5. No 404s (10 points).
        $w = 10;
        $weights += $w;
        if ( $ext['total_404'] === 0 ) {
            $score += $w;
        } elseif ( $ext['total_404'] < 5 ) {
            $score += $w * 0.5;
        }

        // 6. Internal linking (10 points).
        $w = 10;
        $weights += $w;
        if ( $ext['links']['sample_size'] > 0 ) {
            $link_ok = $ext['links']['sample_size'] - $ext['links']['posts_no_internal'];
            $score += $w * ( $link_ok / $ext['links']['sample_size'] );
        } else {
            $score += $w * 0.5;
        }

        return $weights > 0 ? (int) round( ( $score / $weights ) * 100 ) : 0;
    }

    /* ==================================================================
     * Render — SEO Command Center
     * ================================================================*/

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $health       = self::get_seo_health();
        $ext          = self::get_extended_data();
        $site_score   = self::calculate_site_score( $health, $ext );
        $issues       = self::get_issues( $health, $ext );
        $is_connected = class_exists( 'MSH_Auth' ) && MSH_Auth::is_connected();
        $nonce        = wp_create_nonce( 'msh_analytics_nonce' );
        $history      = self::update_score_history( $site_score );
        $speakable    = self::count_speakable_posts();

        // Brain data (MSH-connected only). Both calls are transient-cached with
        // short HTTP timeouts + negative caching, so they can't stall the page.
        $ai_vis    = null;
        $cta_stats = null;
        $growth    = null;
        if ( $is_connected && class_exists( 'MSH_API' ) ) {
            $ai  = MSH_API::ai_visibility();
            $cta = MSH_API::cta_stats();
            $gr  = MSH_API::growth_summary();
            if ( ! is_wp_error( $ai ) && is_array( $ai ) && isset( $ai['checked'] ) ) {
                $ai_vis = $ai;
            }
            if ( ! is_wp_error( $cta ) && is_array( $cta ) && isset( $cta['totals'] ) ) {
                $cta_stats = $cta;
            }
            if ( ! is_wp_error( $gr ) && is_array( $gr ) && isset( $gr['totals'] ) ) {
                $growth = $gr;
            }
        }

        // Score color.
        if ( $site_score >= 80 )      { $score_color = '#16a34a'; $score_label = 'Excellent'; }
        elseif ( $site_score >= 60 )  { $score_color = '#65a30d'; $score_label = 'Good'; }
        elseif ( $site_score >= 40 )  { $score_color = '#d97706'; $score_label = 'Needs Work'; }
        else                          { $score_color = '#dc2626'; $score_label = 'Critical'; }

        self::render_styles();
        ?>
        <div class="wrap msh-command-center">

        <!-- HERO: Site-wide SEO Score -->
        <div class="msh-hero">
            <div class="msh-hero-score">
                <div class="msh-score-ring" style="--score:<?php echo esc_attr( $site_score ); ?>;--color:<?php echo esc_attr( $score_color ); ?>;">
                    <span class="msh-score-number"><?php echo esc_html( $site_score ); ?></span>
                </div>
                <div class="msh-score-meta">
                    <h1 style="margin:0;font-size:22px;">SEO Command Center</h1>
                    <p class="msh-score-label" style="color:<?php echo esc_attr( $score_color ); ?>;"><?php echo esc_html( $score_label ); ?></p>
                    <p class="msh-score-sub"><?php echo esc_html( $health['total_content'] ); ?> pages analyzed &middot; <?php echo esc_html( count( $issues ) ); ?> issues found</p>
                </div>
            </div>
            <div class="msh-hero-actions">
                <button type="button" class="button" id="msh-refresh-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                    <span class="dashicons dashicons-update" style="margin-top:3px;"></span> Refresh Data
                </button>
                <?php if ( $is_connected ) : ?>
                    <a href="https://app.marketingsohigh.com/seo" target="_blank" class="button button-primary">Open Full Dashboard</a>
                <?php else : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=msh-seo' ) ); ?>" class="button button-primary">Connect to MSH</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Stats Bar -->
        <div class="msh-stats-bar">
            <div class="msh-stat">
                <span class="msh-stat-num"><?php echo esc_html( $health['total_posts'] ); ?></span>
                <span class="msh-stat-label">Posts</span>
            </div>
            <div class="msh-stat">
                <span class="msh-stat-num"><?php echo esc_html( $health['total_pages'] ); ?></span>
                <span class="msh-stat-label">Pages</span>
            </div>
            <?php if ( $health['total_products'] > 0 ) : ?>
            <div class="msh-stat">
                <span class="msh-stat-num"><?php echo esc_html( $health['total_products'] ); ?></span>
                <span class="msh-stat-label">Products</span>
            </div>
            <?php endif; ?>
            <div class="msh-stat">
                <span class="msh-stat-num"><?php echo esc_html( $health['avg_score'] ); ?></span>
                <span class="msh-stat-label">Avg SEO Score</span>
            </div>
            <div class="msh-stat">
                <span class="msh-stat-num"><?php echo esc_html( $ext['content_depth']['avg_words'] ); ?></span>
                <span class="msh-stat-label">Avg Words</span>
            </div>
            <div class="msh-stat">
                <span class="msh-stat-num"><?php echo esc_html( $ext['links']['avg_internal'] ); ?></span>
                <span class="msh-stat-label">Avg Int. Links</span>
            </div>
            <div class="msh-stat">
                <span class="msh-stat-num"><?php echo esc_html( $ext['total_redirects'] ); ?></span>
                <span class="msh-stat-label">Redirects</span>
            </div>
        </div>

        <!-- Score Trend (SVG sparkline from the daily history) -->
        <?php
        $trend_pts = array_slice( $history, -30 );
        $trend_n   = count( $trend_pts );
        ?>
        <div class="msh-card msh-trend-card">
            <div class="msh-trend-head">
                <h3><span class="dashicons dashicons-chart-line"></span> Site Score Trend</h3>
                <?php if ( $trend_n >= 2 ) :
                    $trend_delta = (int) $trend_pts[ $trend_n - 1 ]['s'] - (int) $trend_pts[0]['s'];
                ?>
                    <span class="msh-trend-delta <?php echo $trend_delta >= 0 ? 'msh-trend-delta--up' : 'msh-trend-delta--down'; ?>">
                        <?php echo esc_html( ( $trend_delta >= 0 ? '+' : '' ) . $trend_delta ); ?> pts
                        <span class="msh-trend-range">since <?php echo esc_html( date_i18n( 'M j', strtotime( $trend_pts[0]['d'] ) ) ); ?></span>
                    </span>
                <?php endif; ?>
            </div>
            <?php if ( $trend_n >= 2 ) :
                $line_points = array();
                $area_points = array();
                foreach ( $trend_pts as $i => $tp ) {
                    $x = round( 20 + ( $i * ( 560 / max( 1, $trend_n - 1 ) ) ), 1 );
                    $y = round( 15 + ( 100 - max( 0, min( 100, (int) $tp['s'] ) ) ), 1 );
                    $line_points[] = $x . ',' . $y;
                    $area_points[] = $x . ',' . $y;
                }
                $area_points[] = round( 20 + 560, 1 ) . ',115';
                $area_points[] = '20,115';
                $last_xy = explode( ',', $line_points[ $trend_n - 1 ] );
            ?>
            <svg class="msh-trend-svg" viewBox="0 0 600 130" preserveAspectRatio="none" role="img" aria-label="SEO score trend">
                <defs>
                    <linearGradient id="mshTrendFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#ff5c8a" stop-opacity="0.25"/>
                        <stop offset="100%" stop-color="#ff9a3c" stop-opacity="0.02"/>
                    </linearGradient>
                    <linearGradient id="mshTrendLine" x1="0" y1="0" x2="1" y2="0">
                        <stop offset="0%" stop-color="#ff5c8a"/>
                        <stop offset="100%" stop-color="#ff9a3c"/>
                    </linearGradient>
                </defs>
                <line x1="20" y1="15" x2="580" y2="15" class="msh-trend-grid"/>
                <line x1="20" y1="65" x2="580" y2="65" class="msh-trend-grid"/>
                <line x1="20" y1="115" x2="580" y2="115" class="msh-trend-grid"/>
                <polygon points="<?php echo esc_attr( implode( ' ', $area_points ) ); ?>" fill="url(#mshTrendFill)"/>
                <polyline points="<?php echo esc_attr( implode( ' ', $line_points ) ); ?>" fill="none" stroke="url(#mshTrendLine)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
                <circle cx="<?php echo esc_attr( $last_xy[0] ); ?>" cy="<?php echo esc_attr( $last_xy[1] ); ?>" r="5" fill="#ff5c8a" stroke="#fff" stroke-width="2"/>
            </svg>
            <div class="msh-trend-axis">
                <span><?php echo esc_html( date_i18n( 'M j', strtotime( $trend_pts[0]['d'] ) ) ); ?></span>
                <span>Today &middot; <strong style="color:<?php echo esc_attr( $score_color ); ?>;"><?php echo esc_html( $site_score ); ?></strong></span>
            </div>
            <?php else : ?>
                <p class="msh-muted">Collecting daily snapshots &mdash; your score trend will draw itself here after a couple of days.</p>
            <?php endif; ?>
        </div>

        <?php if ( $is_connected && $growth ) :
            self::render_growth_section( $growth );
        endif; ?>

        <?php if ( $is_connected ) : ?>
        <!-- MSH Growth Engine (brain data) -->
        <div class="msh-section">
            <h2><span class="dashicons dashicons-superhero-alt" style="color:#ff5c8a;"></span> MSH Growth Engine <span class="msh-live-pill">LIVE</span></h2>
            <div class="msh-grid msh-grid--3">

                <div class="msh-card">
                    <h3><span class="dashicons dashicons-format-chat"></span> AI Visibility</h3>
                    <?php if ( $ai_vis && (int) $ai_vis['checked'] > 0 ) :
                        $ai_rate  = max( 0, min( 100, (int) $ai_vis['citation_rate'] ) );
                        $ai_color = $ai_rate >= 25 ? '#16a34a' : ( $ai_rate >= 10 ? '#d97706' : '#dc2626' );
                    ?>
                    <div class="msh-donut-row">
                        <svg class="msh-donut" viewBox="0 0 42 42" role="img" aria-label="AI citation rate">
                            <circle cx="21" cy="21" r="15.915" fill="none" stroke="#f1f5f9" stroke-width="4.5"/>
                            <circle cx="21" cy="21" r="15.915" fill="none" stroke="<?php echo esc_attr( $ai_color ); ?>" stroke-width="4.5" stroke-linecap="round"
                                stroke-dasharray="<?php echo esc_attr( max( 0.5, $ai_rate ) ); ?> 100" transform="rotate(-90 21 21)"/>
                            <text x="21" y="21.5" text-anchor="middle" dominant-baseline="middle" class="msh-donut-num"><?php echo esc_html( $ai_rate ); ?>%</text>
                        </svg>
                        <div class="msh-donut-meta">
                            <strong>AI cites you for <?php echo esc_html( (int) $ai_vis['ai_cited'] ); ?> of <?php echo esc_html( (int) $ai_vis['checked'] ); ?></strong>
                            <span class="msh-muted-sm">tracked queries (checked weekly via live Google AI)</span>
                        </div>
                    </div>
                    <?php if ( ! empty( $ai_vis['cited_for'] ) && is_array( $ai_vis['cited_for'] ) ) : ?>
                        <p class="msh-chip-label">Cited for</p>
                        <div class="msh-chips-row">
                            <?php foreach ( array_slice( $ai_vis['cited_for'], 0, 3 ) as $ckw ) : ?>
                                <span class="msh-pill msh-pill--good"><?php echo esc_html( $ckw ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ( ! empty( $ai_vis['competitors'] ) && is_array( $ai_vis['competitors'] ) ) : ?>
                        <p class="msh-chip-label">AI cites these instead</p>
                        <div class="msh-chips-row">
                            <?php foreach ( array_slice( $ai_vis['competitors'], 0, 4 ) as $comp ) :
                                $comp_domain = is_array( $comp ) ? ( isset( $comp['domain'] ) ? $comp['domain'] : '' ) : $comp;
                                if ( ! $comp_domain ) { continue; }
                            ?>
                                <span class="msh-pill"><?php echo esc_html( $comp_domain ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <p class="msh-card-footer">
                        <?php if ( ! empty( $ai_vis['opportunities'] ) ) : ?>
                            <?php echo esc_html( count( $ai_vis['opportunities'] ) ); ?> un-won queries to target &middot;
                        <?php endif; ?>
                        <a href="https://app.marketingsohigh.com/seo" target="_blank" rel="noopener">Full report &rarr;</a>
                    </p>
                    <?php else : ?>
                        <p class="msh-muted">No AI-citation data yet. MSH runs your top keywords through live Google AI every week and records who gets cited &mdash; check back after Sunday's scan.</p>
                    <?php endif; ?>
                </div>

                <div class="msh-card">
                    <h3><span class="dashicons dashicons-filter"></span> Smart CTA Conversions</h3>
                    <?php
                    $cta_tot = $cta_stats && ! empty( $cta_stats['totals'] ) ? $cta_stats['totals'] : null;
                    if ( $cta_tot && (int) $cta_tot['impressions'] > 0 ) :
                        $f_imp = (int) $cta_tot['impressions'];
                        $f_clk = (int) $cta_tot['clicks'];
                        $f_cnv = (int) $cta_tot['conversions'];
                        $w_clk = max( 6, $f_imp > 0 ? round( ( $f_clk / $f_imp ) * 100 ) : 0 );
                        $w_cnv = max( 4, $f_imp > 0 ? round( ( $f_cnv / $f_imp ) * 100 ) : 0 );
                    ?>
                    <div class="msh-funnel">
                        <div class="msh-funnel-row">
                            <span class="msh-funnel-label">Seen</span>
                            <div class="msh-funnel-track"><div class="msh-funnel-bar" style="width:100%;"></div></div>
                            <strong class="msh-funnel-val"><?php echo esc_html( number_format_i18n( $f_imp ) ); ?></strong>
                        </div>
                        <div class="msh-funnel-row">
                            <span class="msh-funnel-label">Clicked</span>
                            <div class="msh-funnel-track"><div class="msh-funnel-bar msh-funnel-bar--mid" style="width:<?php echo esc_attr( $w_clk ); ?>%;"></div></div>
                            <strong class="msh-funnel-val"><?php echo esc_html( number_format_i18n( $f_clk ) ); ?></strong>
                        </div>
                        <div class="msh-funnel-row">
                            <span class="msh-funnel-label">Converted</span>
                            <div class="msh-funnel-track"><div class="msh-funnel-bar msh-funnel-bar--deep" style="width:<?php echo esc_attr( $w_cnv ); ?>%;"></div></div>
                            <strong class="msh-funnel-val"><?php echo esc_html( number_format_i18n( $f_cnv ) ); ?></strong>
                        </div>
                    </div>
                    <div class="msh-chips-row" style="margin-top:10px;">
                        <span class="msh-pill msh-pill--brand">CTR <?php echo esc_html( isset( $cta_stats['ctr'] ) ? $cta_stats['ctr'] : 0 ); ?>%</span>
                        <span class="msh-pill msh-pill--brand">CVR <?php echo esc_html( isset( $cta_stats['cvr'] ) ? $cta_stats['cvr'] : 0 ); ?>%</span>
                        <?php if ( ! empty( $cta_stats['by_intent'] ) && is_array( $cta_stats['by_intent'] ) ) :
                            foreach ( $cta_stats['by_intent'] as $bucket => $bc ) :
                                if ( empty( $bc['clicks'] ) ) { continue; }
                        ?>
                            <span class="msh-pill"><?php echo esc_html( str_replace( '_', ' ', $bucket ) ); ?> &middot; <?php echo esc_html( (int) $bc['clicks'] ); ?> clicks</span>
                        <?php endforeach; endif; ?>
                    </div>
                    <?php if ( ! empty( $cta_stats['top_posts'][0]['clicks'] ) ) :
                        $top_path = wp_parse_url( 'https://' . $cta_stats['top_posts'][0]['url'], PHP_URL_PATH );
                    ?>
                        <p class="msh-card-footer">Top converter: <code><?php echo esc_html( $top_path ? $top_path : '/' ); ?></code></p>
                    <?php endif; ?>
                    <?php else : ?>
                        <p class="msh-muted">No CTA data yet. The Smart CTA on your posts records views, clicks and conversions as visitors read &mdash; numbers appear here within a day of traffic.</p>
                    <?php endif; ?>
                </div>

                <div class="msh-card">
                    <h3><span class="dashicons dashicons-rss"></span> Indexing Pulse</h3>
                    <?php
                    $idx_recent  = class_exists( 'MSH_Indexing' ) ? MSH_Indexing::get_recent_submissions() : array();
                    $idx_gstatus = class_exists( 'MSH_Indexing' ) ? MSH_Indexing::google_status() : 'off';
                    if ( 'local' === $idx_gstatus ) {
                        $idx_glabel = 'Google API on';
                    } elseif ( 'central' === $idx_gstatus ) {
                        $idx_glabel = 'Google via MSH';
                    } else {
                        $idx_glabel = 'Google off';
                    }
                    ?>
                    <div class="msh-chips-row" style="margin-bottom:10px;">
                        <span class="msh-pill msh-pill--good">IndexNow on</span>
                        <span class="msh-pill <?php echo 'off' !== $idx_gstatus ? 'msh-pill--good' : ''; ?>"><?php echo esc_html( $idx_glabel ); ?></span>
                    </div>
                    <?php if ( ! empty( $idx_recent ) ) : ?>
                        <div class="msh-kv-list">
                            <?php foreach ( array_slice( $idx_recent, 0, 4 ) as $sub ) :
                                $sub_url = ! empty( $sub['urls'][0] ) ? $sub['urls'][0] : '';
                                $sub_ok  = ! empty( $sub['success'] );
                            ?>
                            <div class="msh-kv">
                                <span class="msh-idx-url" title="<?php echo esc_attr( $sub_url ); ?>"><?php echo esc_html( wp_parse_url( $sub_url, PHP_URL_PATH ) ? wp_parse_url( $sub_url, PHP_URL_PATH ) : $sub_url ); ?></span>
                                <strong style="color:<?php echo $sub_ok ? '#16a34a' : '#dc2626'; ?>;"><?php echo $sub_ok ? 'Sent' : 'Failed'; ?></strong>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <p class="msh-muted">No submissions yet &mdash; publish a post or re-submit everything now.</p>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
                        <input type="hidden" name="action" value="msh_seo_bulk_index" />
                        <?php wp_nonce_field( 'msh_seo_bulk_index' ); ?>
                        <button type="submit" class="button">Re-submit all URLs</button>
                    </form>
                </div>

            </div>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $issues ) ) : ?>
        <!-- Issues & Opportunities -->
        <div class="msh-section">
            <h2><span class="dashicons dashicons-flag" style="color:#dc2626;"></span> Issues &amp; Opportunities</h2>
            <p class="description">Sorted by impact. Click action buttons to fix issues instantly.</p>
            <div class="msh-issues-list">
                <?php foreach ( $issues as $issue ) : ?>
                <div class="msh-issue msh-issue--<?php echo esc_attr( $issue['severity'] ); ?>">
                    <div class="msh-issue-icon">
                        <span class="dashicons <?php echo esc_attr( $issue['icon'] ); ?>"></span>
                    </div>
                    <div class="msh-issue-body">
                        <strong><?php echo esc_html( $issue['title'] ); ?></strong>
                        <p><?php echo esc_html( $issue['desc'] ); ?></p>
                        <?php if ( ! empty( $issue['upgrade'] ) ) : ?>
                            <p class="msh-upgrade-hint"><span class="dashicons dashicons-star-filled"></span> <?php echo esc_html( $issue['upgrade'] ); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="msh-issue-action">
                        <?php if ( 'ajax' === $issue['action'] ) : ?>
                            <button type="button" class="button button-primary msh-action-btn" data-ajax="<?php echo esc_attr( $issue['ajax'] ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                <?php echo esc_html( $issue['btn'] ); ?>
                            </button>
                        <?php elseif ( 'link' === $issue['action'] ) : ?>
                            <a href="<?php echo esc_url( $issue['url'] ); ?>" class="button"><?php echo esc_html( $issue['btn'] ); ?></a>
                        <?php elseif ( 'scroll' === $issue['action'] ) : ?>
                            <button type="button" class="button" onclick="document.getElementById('<?php echo esc_attr( $issue['target'] ); ?>').scrollIntoView({behavior:'smooth'})">
                                <?php echo esc_html( $issue['btn'] ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Content Health Grid -->
        <div class="msh-section">
            <h2><span class="dashicons dashicons-heart" style="color:#16a34a;"></span> Content Health</h2>
            <div class="msh-grid msh-grid--4">
                <div class="msh-card">
                    <h3>SEO Scores</h3>
                    <?php self::render_mini_bars( array(
                        array( 'label' => 'Good (70+)',   'value' => $health['score_good'],       'color' => '#16a34a' ),
                        array( 'label' => 'Okay (40-69)', 'value' => $health['score_needs_work'], 'color' => '#d97706' ),
                        array( 'label' => 'Poor (<40)',   'value' => $health['score_poor'],        'color' => '#dc2626' ),
                        array( 'label' => 'Unscored',     'value' => $health['no_score'],          'color' => '#94a3b8' ),
                    ) ); ?>
                </div>
                <div class="msh-card">
                    <h3>Content Freshness</h3>
                    <?php if ( $ext['freshness']['total'] > 0 ) :
                        self::render_mini_bars( array(
                            array( 'label' => 'Fresh (90+)',   'value' => $ext['freshness']['fresh'],   'color' => '#16a34a' ),
                            array( 'label' => 'Current (70+)', 'value' => $ext['freshness']['current'], 'color' => '#65a30d' ),
                            array( 'label' => 'Aging (40+)',   'value' => $ext['freshness']['aging'],   'color' => '#d97706' ),
                            array( 'label' => 'Stale (<40)',   'value' => $ext['freshness']['stale'],   'color' => '#dc2626' ),
                        ) );
                    else : ?>
                        <p class="msh-muted">No freshness data yet &mdash; run your first scan.</p>
                    <?php endif; ?>
                    <p style="margin:10px 0 0;">
                        <button type="button" class="button button-small msh-action-btn" data-ajax="msh_run_freshness_scan" data-nonce="<?php echo esc_attr( $nonce ); ?>">Scan now</button>
                    </p>
                </div>
                <div class="msh-card">
                    <h3>Content Depth</h3>
                    <?php self::render_mini_bars( array(
                        array( 'label' => 'Thin (<300)',     'value' => $ext['content_depth']['thin'],          'color' => '#dc2626' ),
                        array( 'label' => 'Medium (300-1K)', 'value' => $ext['content_depth']['medium'],        'color' => '#d97706' ),
                        array( 'label' => 'Long (1K-2K)',    'value' => $ext['content_depth']['long'],          'color' => '#65a30d' ),
                        array( 'label' => 'Deep (2K+)',      'value' => $ext['content_depth']['comprehensive'], 'color' => '#16a34a' ),
                    ) ); ?>
                    <p class="msh-card-footer">Avg: <?php echo esc_html( $ext['content_depth']['avg_words'] ); ?> words</p>
                </div>
                <div class="msh-card">
                    <h3>Link Health</h3>
                    <div class="msh-kv-list">
                        <div class="msh-kv"><span>Avg internal links</span><strong><?php echo esc_html( $ext['links']['avg_internal'] ); ?></strong></div>
                        <div class="msh-kv"><span>Avg external links</span><strong><?php echo esc_html( $ext['links']['avg_external'] ); ?></strong></div>
                        <div class="msh-kv"><span>Posts with no internal links</span><strong style="color:<?php echo $ext['links']['posts_no_internal'] > 0 ? '#dc2626' : '#16a34a'; ?>;"><?php echo esc_html( $ext['links']['posts_no_internal'] ); ?></strong></div>
                    </div>
                    <p class="msh-card-footer">Based on <?php echo esc_html( $ext['links']['sample_size'] ); ?> recent posts</p>
                </div>
            </div>
        </div>

        <!-- Technical SEO -->
        <div class="msh-section">
            <h2><span class="dashicons dashicons-admin-tools" style="color:#6366f1;"></span> Technical SEO</h2>
            <div class="msh-grid msh-grid--3">
                <div class="msh-card">
                    <h3><span class="dashicons dashicons-format-image"></span> Image SEO</h3>
                    <?php
                    $img_ok = $ext['images_total'] - $ext['images_no_alt'];
                    $img_pct = $ext['images_total'] > 0 ? round( ( $img_ok / $ext['images_total'] ) * 100 ) : 100;
                    $img_color = $img_pct >= 90 ? '#16a34a' : ( $img_pct >= 60 ? '#d97706' : '#dc2626' );
                    ?>
                    <div class="msh-progress-wrap">
                        <div class="msh-progress-bar">
                            <div class="msh-progress-fill" style="width:<?php echo esc_attr( $img_pct ); ?>%;background:<?php echo esc_attr( $img_color ); ?>;"></div>
                        </div>
                        <span style="color:<?php echo esc_attr( $img_color ); ?>;font-weight:600;"><?php echo esc_html( $img_pct ); ?>% have alt text</span>
                    </div>
                    <div class="msh-kv-list" style="margin-top:8px;">
                        <div class="msh-kv"><span>Total images</span><strong><?php echo esc_html( $ext['images_total'] ); ?></strong></div>
                        <div class="msh-kv"><span>With alt text</span><strong style="color:#16a34a;"><?php echo esc_html( $img_ok ); ?></strong></div>
                        <div class="msh-kv"><span>Missing alt text</span><strong style="color:#dc2626;"><?php echo esc_html( $ext['images_no_alt'] ); ?></strong></div>
                    </div>
                </div>
                <div class="msh-card">
                    <h3><span class="dashicons dashicons-editor-code"></span> Schema Markup</h3>
                    <div class="msh-kv-list">
                        <div class="msh-kv"><span>Posts with explicit schema</span><strong><?php echo esc_html( $ext['schema_with'] ); ?></strong></div>
                        <div class="msh-kv"><span>Auto-detected (FAQ/HowTo)</span><strong style="color:#16a34a;">Active</strong></div>
                        <div class="msh-kv"><span>Organization schema</span><strong style="color:#16a34a;">Active</strong></div>
                        <div class="msh-kv"><span>Breadcrumb schema</span><strong style="color:#16a34a;">Active</strong></div>
                        <?php
                        $same_as_raw = get_option( 'msh_seo_social_profiles', '' );
                        $same_as_n   = is_array( $same_as_raw )
                            ? count( array_filter( $same_as_raw ) )
                            : count( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $same_as_raw ) ) ) );
                        ?>
                        <div class="msh-kv"><span>Brand sameAs profiles</span>
                            <?php if ( $same_as_n > 0 ) : ?>
                                <strong style="color:#16a34a;"><?php echo esc_html( $same_as_n ); ?> linked</strong>
                            <?php else : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=msh-seo' ) ); ?>" style="font-weight:600;">Add profiles</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p class="msh-card-footer">MSH auto-detects FAQ and HowTo patterns for all posts.</p>
                </div>
                <div class="msh-card">
                    <h3><span class="dashicons dashicons-randomize"></span> Redirects &amp; Indexing</h3>
                    <div class="msh-kv-list">
                        <div class="msh-kv"><span>Active redirects</span><strong><?php echo esc_html( $ext['total_redirects'] ); ?></strong></div>
                        <div class="msh-kv"><span>Total redirect hits</span><strong><?php echo esc_html( number_format( $ext['redirect_hits'] ) ); ?></strong></div>
                        <div class="msh-kv"><span>IndexNow submissions</span><strong><?php echo esc_html( count( $ext['indexnow'] ) ); ?></strong></div>
                        <div class="msh-kv"><span>Noindex pages</span><strong><?php echo esc_html( $health['noindex'] ); ?></strong></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 404 Monitor -->
        <div class="msh-section" id="msh-404-section">
            <h2><span class="dashicons dashicons-dismiss" style="color:#dc2626;"></span> 404 Error Monitor</h2>
            <?php if ( ! empty( $ext['errors_404'] ) ) : ?>
            <p class="description"><?php echo esc_html( $ext['total_404'] ); ?> broken URLs detected. Create redirects to rescue lost traffic.</p>
            <table class="widefat striped msh-table">
                <thead>
                    <tr>
                        <th>Broken URL</th>
                        <th style="width:70px;text-align:center;">Hits</th>
                        <th style="width:150px;">Last Seen</th>
                        <th style="width:250px;">Redirect To</th>
                        <th style="width:120px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $ext['errors_404'] as $err ) : ?>
                    <tr>
                        <td class="msh-url-cell"><?php echo esc_html( $err['url'] ); ?></td>
                        <td style="text-align:center;font-weight:600;color:#dc2626;"><?php echo esc_html( $err['hits'] ); ?></td>
                        <td><?php echo esc_html( $err['last_hit'] ); ?></td>
                        <td>
                            <input type="text" class="msh-redirect-target" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" value="<?php echo esc_attr( home_url( '/' ) ); ?>" style="width:100%;">
                        </td>
                        <td>
                            <button type="button" class="button msh-create-redirect" data-source="<?php echo esc_attr( $err['url'] ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                Create 301
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ( $ext['total_404'] > 10 ) : ?>
                <p style="margin-top:8px;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=msh-seo-redirects' ) ); ?>" class="button">View All <?php echo esc_html( $ext['total_404'] ); ?> Errors</a>
                    <button type="button" class="button msh-action-btn" data-ajax="msh_clear_404_log" data-nonce="<?php echo esc_attr( $nonce ); ?>" style="margin-left:8px;color:#dc2626;">Clear 404 Log</button>
                </p>
            <?php endif; ?>
            <?php elseif ( $ext['has_404_table'] ) : ?>
                <div class="msh-card" style="text-align:center;padding:32px;">
                    <span class="dashicons dashicons-yes-alt" style="font-size:48px;color:#16a34a;display:block;margin-bottom:8px;"></span>
                    <strong>No 404 errors detected!</strong>
                    <p class="msh-muted">MSH is monitoring all page requests. Broken links will appear here.</p>
                </div>
            <?php else : ?>
                <div class="msh-card" style="padding:20px;">
                    <p class="msh-muted">404 monitoring will activate after your first site visit. The redirects module creates the tracking table automatically.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- AI Readiness -->
        <div class="msh-section">
            <h2><span class="dashicons dashicons-superhero" style="color:#8b5cf6;"></span> AI Readiness</h2>
            <div class="msh-grid msh-grid--2">
                <div class="msh-card">
                    <h3>AI Crawler Access</h3>
                    <p class="description" style="margin-bottom:12px;">Control which AI services can crawl your content for training and citation.</p>
                    <?php
                    $known_crawlers = array(
                        'GPTBot'             => 'OpenAI',
                        'Google-Extended'    => 'Google AI',
                        'CCBot'              => 'Common Crawl',
                        'anthropic-ai'       => 'Anthropic',
                        'ClaudeBot'          => 'Anthropic Claude',
                        'Bytespider'         => 'ByteDance',
                        'PerplexityBot'      => 'Perplexity AI',
                        'Cohere-ai'          => 'Cohere',
                        'Meta-ExternalAgent' => 'Meta AI',
                    );
                    $blocked = isset( $ext['crawler_settings']['blocked'] ) ? $ext['crawler_settings']['blocked'] : array();
                    ?>
                    <div class="msh-crawler-grid">
                        <?php foreach ( $known_crawlers as $agent => $company ) :
                            $is_blocked = in_array( $agent, $blocked, true );
                        ?>
                        <div class="msh-crawler-item">
                            <span class="msh-crawler-status" style="color:<?php echo $is_blocked ? '#dc2626' : '#16a34a'; ?>;">
                                <?php echo $is_blocked ? '&#x2715;' : '&#x2713;'; ?>
                            </span>
                            <span class="msh-crawler-name"><?php echo esc_html( $company ); ?></span>
                            <span class="msh-crawler-badge msh-crawler-badge--<?php echo $is_blocked ? 'blocked' : 'allowed'; ?>">
                                <?php echo $is_blocked ? 'Blocked' : 'Allowed'; ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="msh-card-footer">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=msh-seo' ) ); ?>">Manage in Settings</a> &middot;
                        <code>llms.txt</code> served at <code>/llms.txt</code>
                    </p>
                </div>
                <div class="msh-card">
                    <h3>AI Engine Optimization</h3>
                    <p class="description" style="margin-bottom:12px;">How well your content is structured for AI assistants like ChatGPT, Perplexity, and Google AI.</p>
                    <div class="msh-kv-list">
                        <div class="msh-kv"><span>FAQ auto-detection</span><strong style="color:#16a34a;">Active</strong></div>
                        <div class="msh-kv"><span>HowTo auto-detection</span><strong style="color:#16a34a;">Active</strong></div>
                        <div class="msh-kv"><span>Quotable answer blocks</span>
                            <strong style="color:<?php echo $speakable > 0 ? '#16a34a' : '#d97706'; ?>;">
                                <?php echo $speakable > 0 ? esc_html( $speakable ) . ' posts' : 'None yet'; ?>
                            </strong>
                        </div>
                        <div class="msh-kv"><span>Speakable schema</span>
                            <strong style="color:<?php echo $speakable > 0 ? '#16a34a' : '#94a3b8'; ?>;">
                                <?php echo $speakable > 0 ? 'Active on ' . esc_html( $speakable ) : 'Awaiting answer blocks'; ?>
                            </strong>
                        </div>
                        <div class="msh-kv"><span>LLMs.txt for AI citation</span><strong style="color:#16a34a;">Active</strong></div>
                    </div>
                    <?php if ( ! $is_connected ) : ?>
                    <div class="msh-upgrade-box">
                        <span class="dashicons dashicons-star-filled"></span>
                        <div>
                            <strong>Unlock AI Content Scoring</strong>
                            <p>Connect to MSH for per-post AEO scores with 11 optimization checks.</p>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=msh-seo' ) ); ?>" class="button button-small button-primary">Connect Now</a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ( ! $is_connected ) : ?>
        <!-- Upgrade CTA -->
        <div class="msh-upgrade-banner">
            <div class="msh-upgrade-banner-content">
                <h2>Unlock the Full Power of MSH SEO</h2>
                <p>Connect your free MSH account to unlock AI-powered features:</p>
                <ul>
                    <li><span class="dashicons dashicons-yes"></span> AI meta descriptions that boost click-through rates</li>
                    <li><span class="dashicons dashicons-yes"></span> AI alt text that accurately describes your images</li>
                    <li><span class="dashicons dashicons-yes"></span> Content Autopilot that refreshes stale posts automatically</li>
                    <li><span class="dashicons dashicons-yes"></span> Keyword research and content generation</li>
                    <li><span class="dashicons dashicons-yes"></span> Google Search Console integration with ranking tracking</li>
                    <li><span class="dashicons dashicons-yes"></span> Competitor analysis and content gap detection</li>
                </ul>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=msh-seo' ) ); ?>" class="button button-hero button-primary" style="margin-top:8px;">
                    Connect Free Account
                </a>
            </div>
        </div>
        <?php endif; ?>

        </div><!-- .msh-command-center -->

        <?php self::render_scripts( $nonce ); ?>
        <?php
    }

    /* ==================================================================
     * Rendering helpers
     * ================================================================*/

    /**
     * "Your Growth" — the real Google-traffic story from MSH: impressions/clicks
     * trend, 28-day deltas, keywords ranking, and content the engine published.
     *
     * @param array $g Growth summary payload from MSH_API::growth_summary().
     */
    private static function render_growth_section( $g ) {
        $totals   = isset( $g['totals'] ) ? $g['totals'] : array();
        $weekly   = isset( $g['weekly'] ) && is_array( $g['weekly'] ) ? $g['weekly'] : array();
        $topkw    = isset( $g['top_keywords'] ) && is_array( $g['top_keywords'] ) ? $g['top_keywords'] : array();
        $content  = isset( $g['content'] ) ? $g['content'] : array();
        $has_data = ! empty( $g['has_data'] );

        $imp   = isset( $totals['impressions'] ) ? (int) $totals['impressions'] : 0;
        $clk   = isset( $totals['clicks'] ) ? (int) $totals['clicks'] : 0;
        $imp_d = isset( $totals['impressions_delta_pct'] ) ? $totals['impressions_delta_pct'] : null;
        $clk_d = isset( $totals['clicks_delta_pct'] ) ? $totals['clicks_delta_pct'] : null;
        ?>
        <div class="msh-section">
            <h2><span class="dashicons dashicons-chart-area" style="color:#ff5c8a;"></span> Your Growth <span class="msh-live-pill">GOOGLE</span></h2>
            <p class="description" style="margin:-6px 0 12px;">Real Search traffic MSH pulls from Google Search Console — the last 28 days vs the 28 before.</p>

            <div class="msh-grid msh-grid--4" style="margin-bottom:16px;">
                <div class="msh-metric-card">
                    <span class="msh-metric-card__label">Impressions (28d)</span>
                    <span class="msh-metric-card__value"><?php echo esc_html( number_format_i18n( $imp ) ); ?></span>
                    <?php self::render_delta( $imp_d ); ?>
                </div>
                <div class="msh-metric-card">
                    <span class="msh-metric-card__label">Clicks (28d)</span>
                    <span class="msh-metric-card__value"><?php echo esc_html( number_format_i18n( $clk ) ); ?></span>
                    <?php self::render_delta( $clk_d ); ?>
                </div>
                <div class="msh-metric-card">
                    <span class="msh-metric-card__label">Keywords ranking</span>
                    <span class="msh-metric-card__value"><?php echo esc_html( number_format_i18n( isset( $g['keywords_ranking'] ) ? (int) $g['keywords_ranking'] : 0 ) ); ?></span>
                    <span class="msh-metric-card__sub"><?php echo esc_html( isset( $g['keywords_top10'] ) ? (int) $g['keywords_top10'] : 0 ); ?> in top 10<?php echo isset( $totals['avg_position'] ) && $totals['avg_position'] ? ' · avg pos ' . esc_html( $totals['avg_position'] ) : ''; ?></span>
                </div>
                <div class="msh-metric-card">
                    <span class="msh-metric-card__label">Content published</span>
                    <span class="msh-metric-card__value"><?php echo esc_html( number_format_i18n( isset( $content['published_total'] ) ? (int) $content['published_total'] : 0 ) ); ?></span>
                    <span class="msh-metric-card__sub"><?php echo esc_html( isset( $content['published_28d'] ) ? (int) $content['published_28d'] : 0 ); ?> in last 28 days</span>
                </div>
            </div>

            <?php
            // Impressions trend chart from the weekly series.
            $vals = array();
            foreach ( $weekly as $w ) {
                $vals[] = isset( $w['impressions'] ) ? (int) $w['impressions'] : 0;
            }
            $n   = count( $vals );
            $max = $n ? max( $vals ) : 0;
            if ( $has_data && $n >= 2 ) :
                $line = array();
                $area = array();
                foreach ( $vals as $i => $v ) {
                    $x = round( 20 + ( $i * ( 560 / max( 1, $n - 1 ) ) ), 1 );
                    $y = $max > 0 ? round( 130 - ( $v / $max ) * 105, 1 ) : 130;
                    $line[] = $x . ',' . $y;
                    $area[] = $x . ',' . $y;
                }
                $area[] = round( 20 + 560, 1 ) . ',130';
                $area[] = '20,130';
                $last   = explode( ',', $line[ $n - 1 ] );
            ?>
            <div class="msh-card" style="margin-bottom:16px;">
                <h3 style="margin-bottom:4px;">Impressions trend <span style="font-weight:400;color:#94a3b8;font-size:12px;">(last <?php echo esc_html( $n ); ?> weeks)</span></h3>
                <svg class="msh-growth-svg" viewBox="0 0 600 145" preserveAspectRatio="none" role="img" aria-label="Impressions trend">
                    <defs>
                        <linearGradient id="mshGrowthFill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#ff5c8a" stop-opacity="0.28"/>
                            <stop offset="100%" stop-color="#ff9a3c" stop-opacity="0.03"/>
                        </linearGradient>
                        <linearGradient id="mshGrowthLine" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0%" stop-color="#ff5c8a"/><stop offset="100%" stop-color="#ff9a3c"/>
                        </linearGradient>
                    </defs>
                    <polygon points="<?php echo esc_attr( implode( ' ', $area ) ); ?>" fill="url(#mshGrowthFill)"/>
                    <polyline points="<?php echo esc_attr( implode( ' ', $line ) ); ?>" fill="none" stroke="url(#mshGrowthLine)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
                    <circle cx="<?php echo esc_attr( $last[0] ); ?>" cy="<?php echo esc_attr( $last[1] ); ?>" r="5" fill="#ff5c8a" stroke="#fff" stroke-width="2"/>
                </svg>
                <div class="msh-trend-axis">
                    <span><?php echo esc_html( date_i18n( 'M j', strtotime( $weekly[0]['week_start'] ) ) ); ?></span>
                    <span>now · peak <?php echo esc_html( number_format_i18n( $max ) ); ?>/wk</span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $has_data && ! empty( $topkw ) ) : ?>
            <div class="msh-card">
                <h3>Keywords bringing you traffic</h3>
                <table class="msh-kw-table">
                    <thead><tr><th>Keyword</th><th>Position</th><th>Impressions</th><th>Clicks</th></tr></thead>
                    <tbody>
                        <?php foreach ( array_slice( $topkw, 0, 8 ) as $k ) :
                            $pos = isset( $k['position'] ) && $k['position'] ? (int) $k['position'] : null;
                            $pos_color = $pos === null ? '#94a3b8' : ( $pos <= 10 ? '#16a34a' : ( $pos <= 20 ? '#d97706' : '#64748b' ) );
                        ?>
                        <tr>
                            <td class="msh-kw-name"><?php echo esc_html( $k['keyword'] ); ?></td>
                            <td><strong style="color:<?php echo esc_attr( $pos_color ); ?>;"><?php echo $pos === null ? '&mdash;' : esc_html( $pos ); ?></strong></td>
                            <td><?php echo esc_html( number_format_i18n( (int) ( $k['impressions'] ?? 0 ) ) ); ?></td>
                            <td><?php echo esc_html( number_format_i18n( (int) ( $k['clicks'] ?? 0 ) ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php elseif ( ! $has_data ) : ?>
            <div class="msh-card" style="text-align:center;padding:28px;">
                <span class="dashicons dashicons-chart-area" style="font-size:40px;color:#ff9a3c;display:block;margin-bottom:8px;"></span>
                <strong>Your growth data is on its way.</strong>
                <p class="msh-muted" style="max-width:480px;margin:6px auto 0;">Google reports Search traffic a few days after content is crawled. MSH pulls it in daily and it&rsquo;ll appear here automatically &mdash; keep publishing.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a percentage-delta pill (↑ green / ↓ red / new).
     */
    private static function render_delta( $delta ) {
        if ( null === $delta ) {
            echo '<span class="msh-delta msh-delta--flat">new</span>';
            return;
        }
        $delta = (int) $delta;
        if ( $delta >= 0 ) {
            echo '<span class="msh-delta msh-delta--up">&#9650; ' . esc_html( $delta ) . '%</span>';
        } else {
            echo '<span class="msh-delta msh-delta--down">&#9660; ' . esc_html( abs( $delta ) ) . '%</span>';
        }
    }

    private static function render_mini_bars( $data ) {
        $max = 0;
        foreach ( $data as $item ) {
            if ( $item['value'] > $max ) { $max = $item['value']; }
        }
        echo '<div class="msh-mini-bars">';
        foreach ( $data as $item ) {
            $pct = $max > 0 ? round( ( $item['value'] / $max ) * 100 ) : 0;
            if ( $pct < 3 && $item['value'] > 0 ) { $pct = 3; }
            echo '<div class="msh-mini-bar-row">';
            echo '<span class="msh-mini-bar-label">' . esc_html( $item['label'] ) . '</span>';
            echo '<div class="msh-mini-bar-track"><div class="msh-mini-bar-fill" style="width:' . esc_attr( $pct ) . '%;background:' . esc_attr( $item['color'] ) . ';"></div></div>';
            echo '<span class="msh-mini-bar-val">' . esc_html( $item['value'] ) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }

    /* ==================================================================
     * CSS
     * ================================================================*/

    private static function render_styles() {
        ?>
        <style>
        .msh-command-center { max-width: 1200px; }
        .msh-hero {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;
            background: linear-gradient(135deg, #ff5c8a 0%, #ff7a5c 55%, #ff9a3c 100%);
            color: #fff; padding: 28px 32px; border-radius: 16px; margin-bottom: 20px;
            box-shadow: 0 8px 24px rgba(255, 92, 138, 0.25);
        }
        .msh-hero, .msh-hero h1, .msh-hero h2, .msh-hero span { color: #fff !important; }
        .msh-hero p { color: #fff; }
        .msh-hero .msh-score-label { text-shadow: 0 1px 2px rgba(0,0,0,0.3); }
        .msh-hero-score { display: flex; align-items: center; gap: 24px; }
        .msh-score-ring {
            width: 90px; height: 90px; border-radius: 50%; position: relative;
            background: conic-gradient(var(--color) calc(var(--score) * 3.6deg), rgba(255,255,255,0.35) 0);
            display: flex; align-items: center; justify-content: center;
        }
        .msh-score-ring::before {
            content: ''; position: absolute; width: 72px; height: 72px; border-radius: 50%;
            background: #fff;
        }
        .msh-hero .msh-score-number { position: relative; z-index: 1; font-size: 28px; font-weight: 800; color: var(--color, #1e293b) !important; }
        .msh-score-meta { display: flex; flex-direction: column; }
        .msh-score-label { font-size: 18px; font-weight: 700; margin: 2px 0 0; }
        .msh-score-sub { color: rgba(255,255,255,0.7) !important; font-size: 13px; margin: 2px 0 0; }
        .msh-hero-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .msh-hero-actions .button { border-color: rgba(255,255,255,0.5) !important; color: #fff !important; background: rgba(255,255,255,0.1) !important; }
        .msh-hero-actions .button:hover { border-color: #fff !important; background: rgba(255,255,255,0.2) !important; color: #fff !important; }
        .msh-hero-actions .button .dashicons { color: #fff !important; }
        .msh-hero-actions .button-primary { background: #fff !important; border-color: #fff !important; color: #d63c68 !important; font-weight: 600; }
        .msh-hero-actions .button-primary:hover { background: #fff0f4 !important; color: #b82d55 !important; }
        .msh-stats-bar {
            display: flex; flex-wrap: wrap; gap: 0; background: #fff;
            border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 24px; overflow: hidden;
        }
        .msh-stat { flex: 1; min-width: 100px; text-align: center; padding: 16px 12px; border-right: 1px solid #f1f5f9; }
        .msh-stat:last-child { border-right: none; }
        .msh-stat-num { display: block; font-size: 24px; font-weight: 700; color: #1e293b; line-height: 1.2; }
        .msh-stat-label { display: block; font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
        .msh-section { margin-bottom: 28px; }
        .msh-section h2 { display: flex; align-items: center; gap: 8px; font-size: 17px; margin-bottom: 12px; }
        .msh-section h2 .dashicons { font-size: 20px; }
        .msh-grid { display: grid; gap: 16px; }
        .msh-grid--4 { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
        .msh-grid--3 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
        .msh-grid--2 { grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); }
        .msh-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; }
        .msh-card h3 { margin: 0 0 12px; font-size: 14px; display: flex; align-items: center; gap: 6px; }
        .msh-card h3 .dashicons { font-size: 16px; color: #6366f1; }
        .msh-card-footer { font-size: 11px; color: #94a3b8; margin: 10px 0 0; }
        .msh-issues-list { display: flex; flex-direction: column; gap: 8px; }
        .msh-issue {
            display: flex; align-items: center; gap: 14px; background: #fff;
            border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px;
            border-left: 4px solid #94a3b8;
        }
        .msh-issue--critical { border-left-color: #dc2626; }
        .msh-issue--warning  { border-left-color: #d97706; }
        .msh-issue--info     { border-left-color: #3b82f6; }
        .msh-issue-icon .dashicons { font-size: 22px; color: #64748b; }
        .msh-issue--critical .msh-issue-icon .dashicons { color: #dc2626; }
        .msh-issue--warning .msh-issue-icon .dashicons  { color: #d97706; }
        .msh-issue--info .msh-issue-icon .dashicons      { color: #3b82f6; }
        .msh-issue-body { flex: 1; }
        .msh-issue-body p { margin: 4px 0 0; font-size: 12px; color: #64748b; }
        .msh-issue-action { flex-shrink: 0; }
        .msh-upgrade-hint { margin: 6px 0 0; font-size: 11px; color: #7c3aed; display: flex; align-items: center; gap: 4px; }
        .msh-upgrade-hint .dashicons { font-size: 13px; width: 13px; height: 13px; }
        .msh-kv-list { display: flex; flex-direction: column; gap: 6px; }
        .msh-kv { display: flex; justify-content: space-between; align-items: center; font-size: 13px; padding: 4px 0; border-bottom: 1px solid #f8fafc; }
        .msh-kv:last-child { border-bottom: none; }
        .msh-mini-bars { display: flex; flex-direction: column; gap: 8px; }
        .msh-mini-bar-row { display: flex; align-items: center; gap: 8px; }
        .msh-mini-bar-label { min-width: 100px; font-size: 12px; color: #475569; }
        .msh-mini-bar-track { flex: 1; height: 18px; background: #f1f5f9; border-radius: 4px; overflow: hidden; }
        .msh-mini-bar-fill { height: 100%; border-radius: 4px; transition: width 0.4s ease; }
        .msh-mini-bar-val { min-width: 28px; text-align: right; font-size: 12px; font-weight: 700; color: #1e293b; }
        .msh-progress-wrap { margin-bottom: 4px; }
        .msh-progress-bar { height: 10px; background: #f1f5f9; border-radius: 5px; overflow: hidden; margin-bottom: 6px; }
        .msh-progress-fill { height: 100%; border-radius: 5px; transition: width 0.4s ease; }
        .msh-table { border-radius: 8px; overflow: hidden; }
        .msh-url-cell { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: monospace; font-size: 12px; }
        .msh-redirect-target { font-size: 12px !important; }
        .msh-crawler-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
        .msh-crawler-item { display: flex; align-items: center; gap: 6px; font-size: 12px; padding: 4px 0; }
        .msh-crawler-status { font-weight: 700; font-size: 14px; }
        .msh-crawler-name { flex: 1; }
        .msh-crawler-badge { font-size: 10px; padding: 1px 6px; border-radius: 3px; font-weight: 600; }
        .msh-crawler-badge--allowed { background: #dcfce7; color: #166534; }
        .msh-crawler-badge--blocked { background: #fee2e2; color: #991b1b; }
        .msh-upgrade-box {
            display: flex; align-items: flex-start; gap: 10px; margin-top: 14px;
            background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 8px; padding: 12px;
        }
        .msh-upgrade-box .dashicons { color: #7c3aed; font-size: 20px; margin-top: 2px; }
        .msh-upgrade-box strong { display: block; margin-bottom: 2px; }
        .msh-upgrade-box p { font-size: 12px; color: #6b21a8; margin: 0 0 8px; }
        .msh-upgrade-banner {
            background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%);
            border-radius: 12px; padding: 32px 36px; color: #fff; margin-top: 8px;
        }
        .msh-upgrade-banner-content { max-width: 600px; }
        .msh-upgrade-banner h2 { color: #fff; margin: 0 0 8px; font-size: 20px; }
        .msh-upgrade-banner p { color: rgba(255,255,255,0.9); margin: 0 0 12px; }
        .msh-upgrade-banner ul { margin: 0 0 8px; padding: 0; list-style: none; }
        .msh-upgrade-banner li { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; font-size: 14px; }
        .msh-upgrade-banner li .dashicons { color: #a5f3fc; }
        .msh-upgrade-banner .button-hero { background: #fff !important; color: #7c3aed !important; border: none !important; font-weight: 700; }
        .msh-upgrade-banner .button-hero:hover { background: #f5f3ff !important; }
        .msh-muted { color: #94a3b8; font-size: 13px; font-style: italic; }
        .msh-action-btn.msh-done { background: #16a34a !important; border-color: #16a34a !important; color: #fff !important; }
        /* — Score trend sparkline — */
        .msh-trend-card { margin-bottom: 24px; padding: 18px 20px 14px; }
        .msh-trend-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .msh-trend-head h3 { margin: 0; font-size: 14px; display: flex; align-items: center; gap: 6px; }
        .msh-trend-head h3 .dashicons { color: #ff5c8a; font-size: 16px; }
        .msh-trend-delta { font-size: 13px; font-weight: 700; padding: 3px 10px; border-radius: 9999px; }
        .msh-trend-delta--up { background: #dcfce7; color: #166534; }
        .msh-trend-delta--down { background: #fee2e2; color: #991b1b; }
        .msh-trend-range { font-weight: 400; color: inherit; opacity: 0.75; font-size: 11px; }
        .msh-trend-svg { width: 100%; height: 130px; display: block; }
        .msh-trend-grid { stroke: #f1f5f9; stroke-width: 1; }
        .msh-trend-axis { display: flex; justify-content: space-between; font-size: 11px; color: #94a3b8; margin-top: 2px; }
        /* — Growth Engine — */
        .msh-live-pill {
            font-size: 10px; font-weight: 700; letter-spacing: 0.08em; color: #fff;
            background: linear-gradient(135deg, #ff5c8a, #ff9a3c); padding: 2px 9px; border-radius: 9999px;
        }
        .msh-donut-row { display: flex; align-items: center; gap: 14px; margin-bottom: 10px; }
        .msh-donut { width: 84px; height: 84px; flex-shrink: 0; }
        .msh-donut-num { font-size: 9.5px; font-weight: 800; fill: #1e293b; }
        .msh-donut-meta { display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
        .msh-muted-sm { color: #94a3b8; font-size: 11px; }
        .msh-chip-label { margin: 10px 0 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: #94a3b8; }
        .msh-chips-row { display: flex; flex-wrap: wrap; gap: 5px; }
        .msh-pill {
            display: inline-block; font-size: 11px; padding: 2px 9px; border-radius: 9999px;
            background: #f1f5f9; color: #475569; font-weight: 500; max-width: 100%;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .msh-pill--good { background: #dcfce7; color: #166534; }
        .msh-pill--brand { background: #ffe4ec; color: #be185d; font-weight: 700; }
        .msh-funnel { display: flex; flex-direction: column; gap: 8px; }
        .msh-funnel-row { display: flex; align-items: center; gap: 8px; }
        .msh-funnel-label { min-width: 68px; font-size: 12px; color: #475569; }
        .msh-funnel-track { flex: 1; height: 20px; background: #f8fafc; border-radius: 5px; overflow: hidden; }
        .msh-funnel-bar { height: 100%; border-radius: 5px; background: linear-gradient(90deg, #ff5c8a, #ff9a3c); transition: width 0.4s ease; }
        .msh-funnel-bar--mid { opacity: 0.8; }
        .msh-funnel-bar--deep { opacity: 0.6; }
        .msh-funnel-val { min-width: 48px; text-align: right; font-size: 12px; }
        .msh-idx-url { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: monospace; font-size: 11px; }
        /* — Your Growth — */
        .msh-metric-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 18px; display: flex; flex-direction: column; gap: 2px; }
        .msh-metric-card__label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: .03em; }
        .msh-metric-card__value { font-size: 30px; font-weight: 800; color: #0f172a; line-height: 1.05; }
        .msh-metric-card__sub { font-size: 11px; color: #94a3b8; }
        .msh-delta { align-self: flex-start; font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 9999px; margin-top: 2px; }
        .msh-delta--up { background: #dcfce7; color: #166534; }
        .msh-delta--down { background: #fee2e2; color: #991b1b; }
        .msh-delta--flat { background: #f1f5f9; color: #64748b; }
        .msh-growth-svg { width: 100%; height: 145px; display: block; }
        .msh-kw-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .msh-kw-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: #94a3b8; padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        .msh-kw-table th:not(:first-child), .msh-kw-table td:not(:first-child) { text-align: right; width: 90px; }
        .msh-kw-table td { padding: 8px; border-bottom: 1px solid #f1f5f9; }
        .msh-kw-table tr:last-child td { border-bottom: none; }
        .msh-kw-name { font-weight: 600; color: #1e293b; max-width: 340px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        @media (max-width: 782px) {
            .msh-hero { flex-direction: column; text-align: center; }
            .msh-hero-score { flex-direction: column; }
            .msh-stats-bar { flex-direction: column; }
            .msh-stat { border-right: none; border-bottom: 1px solid #f1f5f9; }
            .msh-grid--4, .msh-grid--3, .msh-grid--2 { grid-template-columns: 1fr; }
            .msh-issue { flex-direction: column; align-items: flex-start; }
            .msh-crawler-grid { grid-template-columns: 1fr; }
        }
        </style>
        <?php
    }

    /* ==================================================================
     * JavaScript — AJAX auto-fix handlers
     * ================================================================*/

    private static function render_scripts( $nonce ) {
        ?>
        <script>
        (function() {
            var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce   = '<?php echo esc_js( $nonce ); ?>';

            // Refresh button.
            var refreshBtn = document.getElementById('msh-refresh-btn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', function() {
                    refreshBtn.disabled = true;
                    refreshBtn.textContent = 'Refreshing...';
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() { location.reload(); };
                    xhr.onerror = function() { location.reload(); };
                    xhr.send('action=msh_refresh_analytics&nonce=' + nonce);
                });
            }

            // Auto-fix action buttons.
            document.querySelectorAll('.msh-action-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var action = btn.getAttribute('data-ajax');
                    var btnNonce = btn.getAttribute('data-nonce') || nonce;
                    var origText = btn.textContent;

                    btn.disabled = true;
                    btn.textContent = 'Working...';

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success) {
                                btn.textContent = resp.data.message || 'Done!';
                                btn.classList.add('msh-done');
                                setTimeout(function() { location.reload(); }, 1500);
                            } else {
                                btn.textContent = resp.data || 'Error';
                                btn.disabled = false;
                                setTimeout(function() { btn.textContent = origText; }, 3000);
                            }
                        } catch (e) {
                            btn.textContent = 'Error';
                            btn.disabled = false;
                        }
                    };
                    xhr.onerror = function() {
                        btn.textContent = 'Network error';
                        btn.disabled = false;
                    };
                    xhr.send('action=' + action + '&nonce=' + btnNonce);
                });
            });

            // 404 redirect create buttons.
            document.querySelectorAll('.msh-create-redirect').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var row = btn.closest('tr');
                    var source = btn.getAttribute('data-source');
                    var target = row.querySelector('.msh-redirect-target').value;
                    var btnNonce = btn.getAttribute('data-nonce') || nonce;

                    if (!target) { alert('Please enter a target URL.'); return; }

                    btn.disabled = true;
                    btn.textContent = 'Creating...';

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success) {
                                btn.textContent = 'Created!';
                                btn.classList.add('msh-done');
                                row.style.opacity = '0.5';
                            } else {
                                btn.textContent = resp.data || 'Error';
                                btn.disabled = false;
                            }
                        } catch (e) {
                            btn.textContent = 'Error';
                            btn.disabled = false;
                        }
                    };
                    xhr.send('action=msh_create_redirect&nonce=' + btnNonce + '&source=' + encodeURIComponent(source) + '&target=' + encodeURIComponent(target));
                });
            });
        })();
        </script>
        <?php
    }
}
