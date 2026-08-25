<?php
/**
 * MSH Crawlers — llms.txt + Robots Enhancement
 *
 * Serves llms.txt and llms-full.txt endpoints for AI crawlers,
 * and enhances robots.txt with AI crawler directives.
 *
 * @package MSH_SEO
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Crawlers {

    /**
     * Known AI crawler user-agent names and their descriptions.
     *
     * @var array
     */
    private static $ai_crawlers = array(
        'GPTBot'           => 'OpenAI',
        'Google-Extended'  => 'Google AI',
        'CCBot'            => 'Common Crawl',
        'anthropic-ai'     => 'Anthropic',
        'ClaudeBot'        => 'Anthropic Claude',
        'Bytespider'       => 'ByteDance',
        'PerplexityBot'    => 'Perplexity AI',
        'Cohere-ai'        => 'Cohere',
        'Meta-ExternalAgent' => 'Meta AI',
    );

    /**
     * Initialize hooks for llms.txt serving and robots.txt enhancement.
     *
     * @return void
     */
    public static function init() {
        add_action( 'init', array( __CLASS__, 'serve_llms_txt' ), 5 );
        add_filter( 'robots_txt', array( __CLASS__, 'enhance_robots_txt' ), 100, 2 );
    }

    /**
     * Serve llms.txt or llms-full.txt if the current request matches.
     *
     * Checks the request URI and outputs the appropriate content
     * with text/plain content type. Content is cached in a 24-hour transient.
     *
     * @return void
     */
    public static function serve_llms_txt() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $path        = wp_parse_url( $request_uri, PHP_URL_PATH );

        if ( '/llms.txt' === $path ) {
            self::output_llms_content( false );
        } elseif ( '/llms-full.txt' === $path ) {
            self::output_llms_content( true );
        }
    }

    /**
     * Output llms.txt or llms-full.txt content and exit.
     *
     * @param bool $full Whether to serve the detailed version.
     * @return void
     */
    private static function output_llms_content( $full ) {
        $cache_key = $full ? 'msh_llms_full_txt' : 'msh_llms_txt';
        $content   = get_transient( $cache_key );

        if ( false === $content ) {
            $content = self::generate_llms_content( $full );
            set_transient( $cache_key, $content, DAY_IN_SECONDS );
        }

        // Prevent WordPress from processing further.
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Robots-Tag: noindex' );
        header( 'Cache-Control: public, max-age=86400' );
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text file output.
        exit;
    }

    /**
     * Serve llms-full.txt (detailed version with all content).
     *
     * This is a convenience alias; the actual serving happens in serve_llms_txt().
     *
     * @return void
     */
    public static function serve_llms_full_txt() {
        // Handled by serve_llms_txt() via path check.
    }

    /**
     * Generate the llms.txt content.
     *
     * Follows the llmstxt.org specification to create an AI-friendly
     * summary of the site's content and structure.
     *
     * @param bool $full If true, includes post summaries and more detail.
     * @return string The llms.txt content.
     */
    public static function generate_llms_content( $full = false ) {
        $site_name   = get_bloginfo( 'name' );
        $site_desc   = get_bloginfo( 'description' );
        $site_url    = get_site_url();
        $admin_email = get_bloginfo( 'admin_email' );

        $lines = array();

        // Header.
        $lines[] = '# ' . $site_name;
        if ( ! empty( $site_desc ) ) {
            $lines[] = '> ' . $site_desc;
        }
        $lines[] = '';

        // About section.
        $lines[] = '## About';
        $about_content = self::get_about_content();
        $lines[] = ! empty( $about_content ) ? $about_content : ( ! empty( $site_desc ) ? $site_desc : $site_name );
        $lines[] = '';

        // Topics / categories.
        $categories = get_categories( array(
            'orderby'    => 'count',
            'order'      => 'DESC',
            'number'     => 10,
            'hide_empty' => true,
        ) );

        if ( ! empty( $categories ) ) {
            $lines[] = '## Topics';
            foreach ( $categories as $cat ) {
                $cat_url  = get_category_link( $cat->term_id );
                $cat_desc = ! empty( $cat->description ) ? $cat->description : $cat->name . ' articles';
                $lines[]  = '- [' . $cat->name . '](' . $cat_url . '): ' . $cat_desc;
            }
            $lines[] = '';
        }

        // Key pages.
        $key_pages = get_pages( array(
            'sort_column' => 'menu_order',
            'number'      => 20,
            'post_status' => 'publish',
        ) );

        if ( ! empty( $key_pages ) ) {
            $lines[] = '## Key Pages';
            foreach ( $key_pages as $page ) {
                $lines[] = '- [' . $page->post_title . '](' . get_permalink( $page->ID ) . ')';
            }
            $lines[] = '';
        }

        // Recent articles.
        $post_count = $full ? 50 : 20;
        $posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $post_count,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        if ( ! empty( $posts ) ) {
            $lines[] = '## Recent Articles';
            foreach ( $posts as $post ) {
                $url = get_permalink( $post->ID );
                if ( $full ) {
                    $post_text = strip_shortcodes( $post->post_content );
                    $post_text = preg_replace( '/\[et_pb_[^\]]*\]/', '', $post_text );
                    $post_text = preg_replace( '/\[\/et_pb_[^\]]*\]/', '', $post_text );
                    $excerpt = wp_trim_words( wp_strip_all_tags( $post_text ), 30 );
                    $lines[] = '- [' . $post->post_title . '](' . $url . '): ' . $excerpt;
                } else {
                    $lines[] = '- [' . $post->post_title . '](' . $url . ')';
                }
            }
            $lines[] = '';
        }

        // Contact.
        $lines[] = '## Contact';
        $lines[] = '- Website: ' . $site_url;
        if ( ! empty( $admin_email ) ) {
            $lines[] = '- Email: ' . $admin_email;
        }

        $contact_page = get_page_by_path( 'contact' );
        if ( ! $contact_page ) {
            $contact_page = get_page_by_path( 'contact-us' );
        }
        if ( $contact_page && 'publish' === $contact_page->post_status ) {
            $lines[] = '- Contact Page: ' . get_permalink( $contact_page->ID );
        }

        return implode( "\n", $lines ) . "\n";
    }

    /**
     * Add AI crawler directives to robots.txt.
     *
     * Appends Allow/Disallow rules for known AI crawlers and adds
     * a reference to the llms.txt endpoint.
     *
     * @param string $output The existing robots.txt content.
     * @param bool   $public Whether the site is public (blog_public option).
     * @return string Modified robots.txt content.
     */
    public static function enhance_robots_txt( $output, $public ) {
        // Don't modify if the site is not public.
        if ( ! $public ) {
            return $output;
        }

        $settings = self::get_crawler_settings();
        $lines    = array();

        $lines[] = '';
        $lines[] = '# AI Crawlers — managed by MSH SEO';

        foreach ( self::$ai_crawlers as $agent => $company ) {
            $blocked = in_array( $agent, $settings['blocked'], true );

            $lines[] = 'User-agent: ' . $agent;
            if ( $blocked ) {
                $lines[] = 'Disallow: /';
            } else {
                $lines[] = 'Allow: /';
            }
            $lines[] = '';
        }

        // llms.txt reference.
        $lines[] = '# AI-friendly site summary';
        $lines[] = '# See /llms.txt for a summary of this site';
        $lines[] = '# See /llms-full.txt for detailed content listing';
        $lines[] = '';

        // Replace any existing WP core sitemap reference with our MSH sitemap.
        $sitemap_url = get_site_url() . '/sitemap_index.xml';
        $output      = preg_replace( '/Sitemap:\s*\S+\n?/', '', $output );
        $lines[]     = 'Sitemap: ' . $sitemap_url;

        $output .= implode( "\n", $lines ) . "\n";

        return $output;
    }

    /**
     * Get the about page content for llms.txt.
     *
     * @return string The about page excerpt or empty string.
     */
    private static function get_about_content() {
        $about_page = get_page_by_path( 'about' );
        if ( ! $about_page ) {
            $about_page = get_page_by_path( 'about-us' );
        }

        if ( $about_page && 'publish' === $about_page->post_status ) {
            $about_content = strip_shortcodes( $about_page->post_content );
            // Strip Divi-specific shortcodes that strip_shortcodes might miss.
            $about_content = preg_replace( '/\[et_pb_[^\]]*\]/', '', $about_content );
            $about_content = preg_replace( '/\[\/et_pb_[^\]]*\]/', '', $about_content );
            $about_content = wp_strip_all_tags( trim( $about_content ) );
            return wp_trim_words( $about_content, 50, '...' );
        }

        return '';
    }

    /**
     * Get AI crawler settings.
     *
     * Returns the user's preferences for which AI crawlers to allow
     * or block. Defaults to allowing all crawlers.
     *
     * @return array { blocked: string[] }
     */
    private static function get_crawler_settings() {
        $defaults = array(
            'blocked' => array(),
        );

        $settings = get_option( 'msh_crawler_settings', $defaults );

        if ( ! is_array( $settings ) ) {
            return $defaults;
        }

        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Get the list of known AI crawlers.
     *
     * @return array Associative array of agent => company name.
     */
    public static function get_known_crawlers() {
        return self::$ai_crawlers;
    }

    /**
     * Clear all llms.txt transient caches.
     *
     * Should be called when content changes significantly
     * (e.g., new post published, category updated).
     *
     * @return void
     */
    public static function clear_cache() {
        delete_transient( 'msh_llms_txt' );
        delete_transient( 'msh_llms_full_txt' );
    }
}
