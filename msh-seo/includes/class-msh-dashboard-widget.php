<?php
/**
 * MSH Dashboard Widget — Compact SEO Command Center on the WP Dashboard.
 *
 * Shows site-wide SEO score, top issues with fix buttons, content freshness,
 * and quick links to the full SEO Command Center and MSH dashboard.
 *
 * @package MSH_SEO
 * @since   0.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_SEO_Dashboard_Widget {

    /**
     * Initialize dashboard widget hooks.
     */
    public static function init() {
        add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * Load the widget styles on the dashboard screen.
     *
     * @param string $hook Current admin page.
     */
    public static function enqueue_assets( $hook ) {
        if ( 'index.php' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'msh-seo-dashboard-widget', MSH_SEO_URL . 'assets/css/dashboard-widget.css', array(), MSH_SEO_VERSION );
    }

    /**
     * Register the dashboard widget.
     */
    public static function register_widget() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'msh_seo_marketing_overview',
            __( 'MSH SEO Command Center', 'msh-seo' ),
            array( __CLASS__, 'render_widget' )
        );

        // Move widget to the top of the main column
        global $wp_meta_boxes;
        if ( isset( $wp_meta_boxes['dashboard']['normal']['core']['msh_seo_marketing_overview'] ) ) {
            $widget = $wp_meta_boxes['dashboard']['normal']['core']['msh_seo_marketing_overview'];
            unset( $wp_meta_boxes['dashboard']['normal']['core']['msh_seo_marketing_overview'] );
            $wp_meta_boxes['dashboard']['normal']['core'] = array_merge(
                array( 'msh_seo_marketing_overview' => $widget ),
                $wp_meta_boxes['dashboard']['normal']['core']
            );
        }
    }

    /**
     * Render the dashboard widget content.
     */
    public static function render_widget() {
        $is_connected = class_exists( 'MSH_SEO_Auth' ) && MSH_SEO_Auth::is_connected();

        if ( ! $is_connected ) {
            self::render_disconnected();
            return;
        }

        // Reuse analytics data if available.
        $health = class_exists( 'MSH_SEO_Analytics' ) ? MSH_SEO_Analytics::get_seo_health() : null;
        $ext    = class_exists( 'MSH_SEO_Analytics' ) ? MSH_SEO_Analytics::get_extended_data() : null;

        if ( ! $health || ! $ext ) {
            self::render_disconnected();
            return;
        }

        self::render_score_header( $health, $ext );
        self::render_top_issues( $health, $ext );
        self::render_quick_stats( $health, $ext );
        self::render_quick_actions();
    }

    /**
     * Render the disconnected state.
     */
    private static function render_disconnected() {
        ?>
        <div class="msh-dw-disconnected">
            <span class="dashicons dashicons-chart-bar" style="font-size:36px;color:#94a3b8;display:block;margin-bottom:8px;"></span>
            <p><?php esc_html_e( 'Connect to MSH to see your SEO overview.', 'msh-seo' ); ?></p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=msh-seo' ) ); ?>" class="button button-primary">
                <?php esc_html_e( 'Connect to MSH', 'msh-seo' ); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Render the score header with ring visualization.
     */
    private static function render_score_header( $health, $ext ) {
        // Calculate site score using same algorithm as full analytics.
        $site_score = 0;
        $w_total = 100;

        // SEO coverage (30).
        if ( $health['total_content'] > 0 && $health['scored_count'] > 0 ) {
            $site_score += 30 * min( 1, ( $health['score_good'] / $health['total_content'] ) * 1.5 );
        }
        // Meta coverage (20).
        if ( $health['total_content'] > 0 ) {
            $site_score += 20 * ( 1 - ( $health['missing_meta'] / $health['total_content'] ) );
        }
        // Image alt (15).
        if ( $ext['images_total'] > 0 ) {
            $site_score += 15 * ( 1 - ( $ext['images_no_alt'] / $ext['images_total'] ) );
        } else {
            $site_score += 15;
        }
        // Freshness (15).
        $ft = $ext['freshness']['total'];
        if ( $ft > 0 ) {
            $site_score += 15 * ( ( $ext['freshness']['fresh'] + $ext['freshness']['current'] ) / $ft );
        } else {
            $site_score += 7;
        }
        // 404s (10).
        if ( $ext['total_404'] === 0 ) { $site_score += 10; }
        elseif ( $ext['total_404'] < 5 ) { $site_score += 5; }
        // Internal links (10).
        if ( $ext['links']['sample_size'] > 0 ) {
            $site_score += 10 * ( ( $ext['links']['sample_size'] - $ext['links']['posts_no_internal'] ) / $ext['links']['sample_size'] );
        } else {
            $site_score += 5;
        }

        $site_score = (int) round( $site_score );
        if ( $site_score >= 80 )      { $color = '#16a34a'; $label = 'Excellent'; }
        elseif ( $site_score >= 60 )  { $color = '#65a30d'; $label = 'Good'; }
        elseif ( $site_score >= 40 )  { $color = '#d97706'; $label = 'Needs Work'; }
        else                          { $color = '#dc2626'; $label = 'Critical'; }
        ?>
        <div class="msh-dw-header">
            <div class="msh-dw-ring" style="--score:<?php echo esc_attr( $site_score ); ?>;--color:<?php echo esc_attr( $color ); ?>;">
                <span class="msh-dw-ring-num"><?php echo esc_html( $site_score ); ?></span>
            </div>
            <div class="msh-dw-header-text">
                <strong style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $label ); ?></strong>
                <span class="msh-dw-sub"><?php echo esc_html( $health['total_content'] ); ?> pages &middot; <?php echo esc_html( $health['avg_score'] ); ?> avg score</span>
            </div>
        </div>
        <?php
    }

    /**
     * Render top 3 issues with severity indicators.
     */
    private static function render_top_issues( $health, $ext ) {
        $issues = array();

        if ( $health['no_score'] > 0 ) {
            $issues[] = array( 'critical', sprintf( '%d posts never analyzed', $health['no_score'] ) );
        }
        if ( $health['missing_meta'] > 0 ) {
            $issues[] = array( $health['missing_meta'] > 10 ? 'critical' : 'warning', sprintf( '%d missing meta descriptions', $health['missing_meta'] ) );
        }
        if ( $ext['images_no_alt'] > 0 ) {
            $issues[] = array( $ext['images_no_alt'] > 20 ? 'critical' : 'warning', sprintf( '%d images without alt text', $ext['images_no_alt'] ) );
        }
        if ( $ext['total_404'] > 0 ) {
            $issues[] = array( 'warning', sprintf( '%d broken URLs (404s)', $ext['total_404'] ) );
        }
        if ( $ext['freshness']['stale'] > 0 ) {
            $issues[] = array( 'info', sprintf( '%d stale posts need refresh', $ext['freshness']['stale'] ) );
        }
        if ( $ext['links']['posts_no_internal'] > 0 ) {
            $issues[] = array( 'info', sprintf( '%d posts with no internal links', $ext['links']['posts_no_internal'] ) );
        }

        if ( empty( $issues ) ) {
            ?>
            <div class="msh-dw-section">
                <span style="color:#16a34a;font-weight:600;">&#x2713; No issues found!</span>
            </div>
            <?php
            return;
        }

        $top = array_slice( $issues, 0, 4 );
        ?>
        <div class="msh-dw-section">
            <h4><?php esc_html_e( 'Top Issues', 'msh-seo' ); ?></h4>
            <?php foreach ( $top as $issue ) :
                $dot_color = 'critical' === $issue[0] ? '#dc2626' : ( 'warning' === $issue[0] ? '#d97706' : '#3b82f6' );
            ?>
                <div class="msh-dw-issue">
                    <span style="color:<?php echo esc_attr( $dot_color ); ?>;">&#9679;</span>
                    <span><?php echo esc_html( $issue[1] ); ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ( count( $issues ) > 4 ) : ?>
                <p class="msh-dw-more">+<?php echo esc_html( count( $issues ) - 4 ); ?> more issues</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render compact stats row.
     */
    private static function render_quick_stats( $health, $ext ) {
        ?>
        <div class="msh-dw-section">
            <div class="msh-dw-stats-row">
                <div class="msh-dw-stat-item">
                    <span class="msh-dw-stat-n" style="color:#16a34a;"><?php echo esc_html( $health['score_good'] ); ?></span>
                    <span class="msh-dw-stat-l">Good</span>
                </div>
                <div class="msh-dw-stat-item">
                    <span class="msh-dw-stat-n" style="color:#d97706;"><?php echo esc_html( $health['score_needs_work'] ); ?></span>
                    <span class="msh-dw-stat-l">Okay</span>
                </div>
                <div class="msh-dw-stat-item">
                    <span class="msh-dw-stat-n" style="color:#dc2626;"><?php echo esc_html( $health['score_poor'] ); ?></span>
                    <span class="msh-dw-stat-l">Poor</span>
                </div>
                <div class="msh-dw-stat-item">
                    <span class="msh-dw-stat-n"><?php echo esc_html( $ext['content_depth']['avg_words'] ); ?></span>
                    <span class="msh-dw-stat-l">Avg Words</span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render quick action links.
     */
    private static function render_quick_actions() {
        ?>
        <div class="msh-dw-actions">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=msh-seo-analytics' ) ); ?>" class="button button-primary button-small">
                Open Command Center
            </a>
            <a href="https://app.marketingsohigh.com/seo" target="_blank" class="button button-small">
                Full Dashboard
            </a>
        </div>
        <?php
    }

}
