<?php
/**
 * MSH XML Sitemap Generator
 *
 * Replaces the WordPress core sitemap with custom XML sitemaps that
 * respect MSH SEO noindex settings. Generates a sitemap index plus
 * sub-sitemaps for posts, pages, categories, and tags.
 *
 * @package MSH_SEO
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Sitemap {

    /** @var int Maximum URLs per sub-sitemap. */
    const MAX_URLS = 1000;

    /**
     * Register all hooks for sitemap generation.
     *
     * @return void
     */
    public static function init() {
        // Disable WordPress core sitemaps.
        add_filter( 'wp_sitemaps_enabled', '__return_false' );

        // Register custom rewrite rules (backup mechanism).
        add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );

        // Primary: intercept sitemap requests by URI on init (works without rewrite flush).
        add_action( 'init', array( __CLASS__, 'intercept_sitemap_by_uri' ), 1 );

        // Backup: intercept via rewrite query var on template_redirect.
        add_action( 'template_redirect', array( __CLASS__, 'handle_sitemap_request' ) );

        // Ping search engines on publish / delete.
        add_action( 'publish_post', array( __CLASS__, 'ping_search_engines' ) );
        add_action( 'delete_post', array( __CLASS__, 'ping_search_engines' ) );
    }

    /**
     * Intercept sitemap requests by checking REQUEST_URI directly.
     *
     * This works even when rewrite rules haven't been flushed (e.g., manual
     * plugin upload via ZIP). Hooked to 'init' at priority 1.
     *
     * @return void
     */
    public static function intercept_sitemap_by_uri() {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return;
        }

        $request_uri = trim( $_SERVER['REQUEST_URI'], '/' );
        // Remove query string.
        $request_uri = strtok( $request_uri, '?' );

        // Prevent caching plugins from wrapping our output.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        switch ( $request_uri ) {
            case 'sitemap.xml':
            case 'sitemap_index.xml':
                status_header( 200 );
                header( 'Content-Type: application/xml; charset=UTF-8' );
                header( 'X-Robots-Tag: noindex, follow' );
                self::render_index();
                exit;
            case 'sitemap-posts.xml':
                status_header( 200 );
                header( 'Content-Type: application/xml; charset=UTF-8' );
                header( 'X-Robots-Tag: noindex, follow' );
                self::render_posts();
                exit;
            case 'sitemap-pages.xml':
                status_header( 200 );
                header( 'Content-Type: application/xml; charset=UTF-8' );
                header( 'X-Robots-Tag: noindex, follow' );
                self::render_pages();
                exit;
            case 'sitemap-categories.xml':
                status_header( 200 );
                header( 'Content-Type: application/xml; charset=UTF-8' );
                header( 'X-Robots-Tag: noindex, follow' );
                self::render_categories();
                exit;
            case 'sitemap-tags.xml':
                status_header( 200 );
                header( 'Content-Type: application/xml; charset=UTF-8' );
                header( 'X-Robots-Tag: noindex, follow' );
                self::render_tags();
                exit;
        }
    }

    /**
     * Register rewrite rules for sitemap URLs.
     *
     * @return void
     */
    public static function add_rewrite_rules() {
        add_rewrite_rule( 'sitemap_index\.xml$', 'index.php?msh_sitemap=index', 'top' );
        add_rewrite_rule( 'sitemap-posts\.xml$', 'index.php?msh_sitemap=posts', 'top' );
        add_rewrite_rule( 'sitemap-pages\.xml$', 'index.php?msh_sitemap=pages', 'top' );
        add_rewrite_rule( 'sitemap-categories\.xml$', 'index.php?msh_sitemap=categories', 'top' );
        add_rewrite_rule( 'sitemap-tags\.xml$', 'index.php?msh_sitemap=tags', 'top' );

        add_rewrite_tag( '%msh_sitemap%', '([a-z]+)' );
    }

    /**
     * Intercept the request if a sitemap query var is present and render the XML.
     *
     * @return void
     */
    public static function handle_sitemap_request() {
        $sitemap = get_query_var( 'msh_sitemap' );

        if ( empty( $sitemap ) ) {
            return;
        }

        // Prevent caching plugins from wrapping our output.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        status_header( 200 );
        header( 'Content-Type: application/xml; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex, follow' );

        switch ( $sitemap ) {
            case 'index':
                self::render_index();
                break;
            case 'posts':
                self::render_posts();
                break;
            case 'pages':
                self::render_pages();
                break;
            case 'categories':
                self::render_categories();
                break;
            case 'tags':
                self::render_tags();
                break;
            default:
                status_header( 404 );
                break;
        }

        exit;
    }

    // ------------------------------------------------------------------
    // Renderers
    // ------------------------------------------------------------------

    /**
     * Render the sitemap index listing all sub-sitemaps.
     *
     * @return void
     */
    private static function render_index() {
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        $sitemaps = array(
            'sitemap-posts.xml',
            'sitemap-pages.xml',
        );

        // Include categories sub-sitemap only if archives are not globally noindexed.
        if ( ! get_option( 'msh_seo_noindex_archives', false ) ) {
            $sitemaps[] = 'sitemap-categories.xml';
        }

        // Include tags sub-sitemap only if tags are not globally noindexed.
        if ( ! get_option( 'msh_seo_noindex_tags', false ) ) {
            $sitemaps[] = 'sitemap-tags.xml';
        }

        foreach ( $sitemaps as $file ) {
            echo '  <sitemap>' . "\n";
            echo '    <loc>' . esc_url( home_url( '/' . $file ) ) . '</loc>' . "\n";
            echo '    <lastmod>' . esc_html( gmdate( 'c' ) ) . '</lastmod>' . "\n";
            echo '  </sitemap>' . "\n";
        }

        echo '</sitemapindex>' . "\n";
    }

    /**
     * Render the posts sub-sitemap.
     *
     * @return void
     */
    private static function render_posts() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_modified_gmt
                 FROM {$wpdb->posts} AS p
                 LEFT JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id AND pm.meta_key = '_msh_seo_noindex'
                 WHERE p.post_type = 'post'
                   AND p.post_status = 'publish'
                   AND ( pm.meta_value IS NULL OR pm.meta_value != %s )
                 ORDER BY p.post_modified_gmt DESC
                 LIMIT %d",
                '1',
                self::MAX_URLS
            )
        );

        self::render_urlset( $posts );
    }

    /**
     * Render the pages sub-sitemap.
     *
     * @return void
     */
    private static function render_pages() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $pages = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_modified_gmt
                 FROM {$wpdb->posts} AS p
                 LEFT JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id AND pm.meta_key = '_msh_seo_noindex'
                 WHERE p.post_type = 'page'
                   AND p.post_status = 'publish'
                   AND ( pm.meta_value IS NULL OR pm.meta_value != %s )
                 ORDER BY p.post_modified_gmt DESC
                 LIMIT %d",
                '1',
                self::MAX_URLS
            )
        );

        self::render_urlset( $pages );
    }

    /**
     * Render the categories sub-sitemap.
     *
     * @return void
     */
    private static function render_categories() {
        $noindex_archives = get_option( 'msh_seo_noindex_archives', false );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        if ( ! $noindex_archives ) {
            $categories = get_categories( array(
                'hide_empty' => true,
                'number'     => self::MAX_URLS,
                'taxonomy'   => 'category',
            ) );

            if ( is_array( $categories ) && ! is_wp_error( $categories ) ) {
                foreach ( $categories as $category ) {
                    echo '  <url>' . "\n";
                    echo '    <loc>' . esc_url( get_category_link( $category->term_id ) ) . '</loc>' . "\n";
                    echo '    <changefreq>weekly</changefreq>' . "\n";
                    echo '    <priority>0.6</priority>' . "\n";
                    echo '  </url>' . "\n";
                }
            }
        }

        echo '</urlset>' . "\n";
    }

    /**
     * Render the tags sub-sitemap.
     *
     * @return void
     */
    private static function render_tags() {
        $noindex_tags = get_option( 'msh_seo_noindex_tags', false );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        if ( ! $noindex_tags ) {
            $tags = get_tags( array(
                'hide_empty' => true,
                'number'     => self::MAX_URLS,
            ) );

            if ( is_array( $tags ) ) {
                foreach ( $tags as $tag ) {
                    echo '  <url>' . "\n";
                    echo '    <loc>' . esc_url( get_tag_link( $tag->term_id ) ) . '</loc>' . "\n";
                    echo '    <changefreq>weekly</changefreq>' . "\n";
                    echo '    <priority>0.4</priority>' . "\n";
                    echo '  </url>' . "\n";
                }
            }
        }

        echo '</urlset>' . "\n";
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Render a <urlset> from an array of post rows (must have ->ID and ->post_modified_gmt).
     *
     * @param array $posts Array of database row objects.
     * @return void
     */
    private static function render_urlset( $posts ) {
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Add homepage as the first entry if rendering posts.
        $front_page_id = (int) get_option( 'page_on_front' );
        if ( ! $front_page_id || 'page' !== get_option( 'show_on_front' ) ) {
            echo '  <url>' . "\n";
            echo '    <loc>' . esc_url( home_url( '/' ) ) . '</loc>' . "\n";
            echo '    <changefreq>daily</changefreq>' . "\n";
            echo '    <priority>1.0</priority>' . "\n";
            echo '  </url>' . "\n";
        }

        if ( $posts ) {
            foreach ( $posts as $row ) {
                $permalink = get_permalink( $row->ID );
                if ( ! $permalink ) {
                    continue;
                }

                $priority   = self::get_post_priority( $row );
                $changefreq = self::get_post_changefreq( $row );

                echo '  <url>' . "\n";
                echo '    <loc>' . esc_url( $permalink ) . '</loc>' . "\n";
                echo '    <lastmod>' . esc_html( gmdate( 'c', strtotime( $row->post_modified_gmt ) ) ) . '</lastmod>' . "\n";
                echo '    <changefreq>' . esc_html( $changefreq ) . '</changefreq>' . "\n";
                echo '    <priority>' . esc_html( number_format( $priority, 1 ) ) . '</priority>' . "\n";
                echo '  </url>' . "\n";
            }
        }

        echo '</urlset>' . "\n";
    }

    /**
     * Calculate the priority for a post based on recency.
     *
     * @param object $post Row object with post_modified_gmt.
     * @return float Priority value between 0.5 and 0.8.
     */
    private static function get_post_priority( $post ) {
        $modified   = strtotime( $post->post_modified_gmt );
        $days_ago   = ( time() - $modified ) / DAY_IN_SECONDS;

        if ( $days_ago < 30 ) {
            return 0.8;
        } elseif ( $days_ago < 180 ) {
            return 0.6;
        }

        return 0.5;
    }

    /**
     * Calculate the changefreq for a post based on recency.
     *
     * @param object $post Row object with post_modified_gmt.
     * @return string Sitemap changefreq value.
     */
    private static function get_post_changefreq( $post ) {
        $modified = strtotime( $post->post_modified_gmt );
        $days_ago = ( time() - $modified ) / DAY_IN_SECONDS;

        if ( $days_ago < 7 ) {
            return 'daily';
        } elseif ( $days_ago < 30 ) {
            return 'weekly';
        }

        return 'monthly';
    }

    /**
     * Ping Google and Bing with the sitemap URL after content changes.
     *
     * @param int $post_id The post ID that was published or deleted.
     * @return void
     */
    public static function ping_search_engines( $post_id ) {
        // Only ping for published posts.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return;
        }

        $sitemap_url = home_url( '/sitemap_index.xml' );

        // Google.
        wp_remote_get(
            'https://www.google.com/ping?sitemap=' . rawurlencode( $sitemap_url ),
            array(
                'timeout'  => 3,
                'blocking' => false,
            )
        );

        // Bing (IndexNow style, but legacy ping still works).
        wp_remote_get(
            'https://www.bing.com/ping?sitemap=' . rawurlencode( $sitemap_url ),
            array(
                'timeout'  => 3,
                'blocking' => false,
            )
        );
    }
}
