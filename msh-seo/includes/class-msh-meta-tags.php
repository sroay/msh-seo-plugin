<?php
/**
 * MSH Meta Tags - Output SEO meta tags in wp_head.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Meta_Tags {

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action( 'wp_head', array( __CLASS__, 'output_meta_tags' ), 1 );
        add_filter( 'document_title_parts', array( __CLASS__, 'filter_document_title' ) );

        // Remove WordPress default canonical to prevent duplicates
        remove_action( 'wp_head', 'rel_canonical' );

        // Remove Divi's canonical/OG output if the theme is active
        add_action( 'wp_head', array( __CLASS__, 'remove_conflicting_tags' ), 0 );
    }

    /**
     * Remove meta tags from themes/plugins that conflict with MSH SEO.
     */
    public static function remove_conflicting_tags() {
        // WP core canonical — we output our own.
        remove_action( 'wp_head', 'rel_canonical' );

        // Divi theme canonical
        if ( function_exists( 'et_add_canonical' ) ) {
            remove_action( 'wp_head', 'et_add_canonical' );
        }

        // WP core robots — we handle it ourselves
        remove_filter( 'wp_robots', 'wp_robots_max_image_preview_large' );
    }

    /**
     * Output all meta tags.
     */
    public static function output_meta_tags() {
        // Google site verification — output site-wide and BEFORE the meta
        // module toggle: Google fetches the homepage for META verification,
        // and disabling the meta module must not silently break ownership.
        $google_verification = get_option( 'msh_seo_google_site_verification', '' );
        if ( ! empty( $google_verification ) ) {
            printf( '<meta name="google-site-verification" content="%s" />' . "\n", esc_attr( $google_verification ) );
        }
        $bing_verification = get_option( 'msh_seo_bing_site_verification', '' );
        if ( ! empty( $bing_verification ) ) {
            printf( '<meta name="msvalidate.01" content="%s" />' . "\n", esc_attr( $bing_verification ) );
        }

        if ( ! get_option( 'msh_seo_enable_meta', true ) ) {
            return;
        }

        if ( is_singular() ) {
            $post = get_queried_object();
            if ( ! $post ) {
                return;
            }

            echo "\n<!-- MSH SEO -->\n";

            // Meta description
            $description = self::get_meta_description( $post );
            if ( $description ) {
                printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
            }

            // Canonical URL
            $canonical = self::get_canonical_url( $post );
            if ( $canonical ) {
                printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
            }

            // Robots tag
            $robots = self::get_robots_tag( $post );
            if ( $robots ) {
                printf( '<meta name="robots" content="%s, max-image-preview:large" />' . "\n", esc_attr( $robots ) );
            }

            // Open Graph
            self::output_open_graph( $post );

            // Twitter Card
            self::output_twitter_card( $post );

            echo "<!-- /MSH SEO -->\n\n";

        } elseif ( is_home() ) {
            // Blog listing page (posts page)
            self::output_blog_page_meta();

        } elseif ( is_category() || is_tag() || is_author() || is_date() || is_archive() ) {
            // Archive pages — full meta output
            self::output_archive_meta();
        }
    }

    /**
     * Check if a stored description looks garbled (words concatenated without
     * spaces, typically caused by Divi shortcode stripping).
     *
     * Detects patterns like "BELIEVEWork" or "HarderIntelligent" where a
     * lowercase letter is immediately followed by an uppercase letter.
     *
     * @param string $text The description to check.
     * @return bool True if the text appears garbled.
     */
    private static function is_garbled( $text ) {
        // Two signals that together indicate garbled Divi output:
        // 1. lowercase→uppercase transition (e.g. "rI" in "HarderIntelligent")
        // 2. Long unbroken letter run (13+ chars, e.g. "HarderIntelligent")
        // Both must be present to avoid false positives on normal text
        // like "McDonald's" (has transition but no long run) or
        // "Uncategorized" (has long run but no transition).
        $has_transition = preg_match_all( '/[a-z][A-Z]/', $text ) >= 1;
        $has_long_run   = preg_match_all( '/[a-zA-Z]{13,}/', $text ) >= 1;
        return $has_transition && $has_long_run;
    }

    /**
     * Output meta tags for the blog listing page (is_home()).
     */
    private static function output_blog_page_meta() {
        echo "\n<!-- MSH SEO -->\n";

        $site_name   = get_bloginfo( 'name' );
        $description = get_bloginfo( 'description' );
        $blog_url    = get_permalink( get_option( 'page_for_posts' ) );

        // If a static page is set as the posts page, use its meta
        $posts_page_id = (int) get_option( 'page_for_posts' );
        if ( $posts_page_id ) {
            $page_desc = get_post_meta( $posts_page_id, '_msh_seo_description', true );
            // Only use stored description if it's not garbled
            if ( ! empty( $page_desc ) && ! self::is_garbled( $page_desc ) ) {
                $description = $page_desc;
            }
            $blog_url = get_permalink( $posts_page_id );
        }

        if ( empty( $blog_url ) ) {
            $blog_url = home_url( '/' );
        }

        if ( empty( $description ) ) {
            $description = sprintf( 'Read the latest articles from %s.', $site_name );
        }

        // Trim description
        $description = self::trim_description( $description );

        printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
        printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $blog_url ) );
        printf( '<meta name="robots" content="index, follow, max-image-preview:large" />' . "\n" );

        // OG tags
        printf( '<meta property="og:title" content="%s - Blog" />' . "\n", esc_attr( $site_name ) );
        printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
        printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $blog_url ) );
        echo '<meta property="og:type" content="website" />' . "\n";
        printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( $site_name ) );
        printf( '<meta property="og:locale" content="%s" />' . "\n", esc_attr( get_locale() ) );

        // Fallback OG image
        self::output_fallback_og_image();

        // Twitter card
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        printf( '<meta name="twitter:title" content="%s - Blog" />' . "\n", esc_attr( $site_name ) );
        printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $description ) );
        self::output_twitter_handle();
        self::output_fallback_twitter_image();

        echo "<!-- /MSH SEO -->\n\n";
    }

    /**
     * Output meta tags for archive pages (category, tag, author, date).
     */
    private static function output_archive_meta() {
        // Check noindex settings first
        $noindex = false;
        if ( is_date() && get_option( 'msh_seo_noindex_archives', false ) ) {
            $noindex = true;
        }
        if ( is_tag() && get_option( 'msh_seo_noindex_tags', false ) ) {
            $noindex = true;
        }
        if ( is_author() && get_option( 'msh_seo_noindex_author', false ) ) {
            $noindex = true;
        }
        // Category archives — use the general archives setting
        if ( is_category() && get_option( 'msh_seo_noindex_archives', false ) ) {
            $noindex = true;
        }

        echo "\n<!-- MSH SEO -->\n";

        $site_name = get_bloginfo( 'name' );
        $title       = '';
        $description = '';
        $canonical   = '';

        if ( is_category() ) {
            $cat         = get_queried_object();
            $title       = $cat->name;
            $description = ! empty( $cat->description ) ? $cat->description : sprintf( 'Articles about %s from %s.', $cat->name, $site_name );
            $canonical   = get_category_link( $cat->term_id );
        } elseif ( is_tag() ) {
            $tag         = get_queried_object();
            $title       = $tag->name;
            $description = ! empty( $tag->description ) ? $tag->description : sprintf( 'Articles tagged with %s on %s.', $tag->name, $site_name );
            $canonical   = get_tag_link( $tag->term_id );
        } elseif ( is_author() ) {
            $author      = get_queried_object();
            $title       = $author->display_name;
            $description = sprintf( 'Articles by %s on %s.', $author->display_name, $site_name );
            $canonical   = get_author_posts_url( $author->ID );
        } elseif ( is_date() ) {
            if ( is_year() ) {
                $title = get_the_date( 'Y' );
            } elseif ( is_month() ) {
                $title = get_the_date( 'F Y' );
            } elseif ( is_day() ) {
                $title = get_the_date( 'F j, Y' );
            }
            $description = sprintf( 'Archive for %s on %s.', $title, $site_name );
            $canonical   = '';  // Let WP handle date archive URLs
        }

        // Trim description
        $description = self::trim_description( $description );

        // Meta description
        if ( $description ) {
            printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
        }

        // Canonical
        if ( $canonical ) {
            printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
        }

        // Robots
        if ( $noindex ) {
            echo '<meta name="robots" content="noindex, follow" />' . "\n";
        } else {
            echo '<meta name="robots" content="index, follow, max-image-preview:large" />' . "\n";
        }

        // OG tags
        $og_title = ! empty( $title ) ? $title . ' - ' . $site_name : $site_name;
        printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $og_title ) );
        if ( $description ) {
            printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
        }
        if ( $canonical ) {
            printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $canonical ) );
        }
        echo '<meta property="og:type" content="website" />' . "\n";
        printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( $site_name ) );
        printf( '<meta property="og:locale" content="%s" />' . "\n", esc_attr( get_locale() ) );

        // Fallback OG image
        self::output_fallback_og_image();

        // Twitter card
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $og_title ) );
        if ( $description ) {
            printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $description ) );
        }
        self::output_twitter_handle();
        self::output_fallback_twitter_image();

        echo "<!-- /MSH SEO -->\n\n";
    }

    /**
     * Get the SEO title from post meta or fall back to post title.
     *
     * @param WP_Post $post The post object.
     * @return string
     */
    private static function get_seo_title( $post ) {
        $seo_title = get_post_meta( $post->ID, '_msh_seo_title', true );
        if ( ! empty( $seo_title ) ) {
            return $seo_title;
        }

        // For front page, use site name instead of "Home"
        if ( is_front_page() ) {
            $site_name = get_bloginfo( 'name' );
            $tagline   = get_bloginfo( 'description' );
            return $tagline ? $site_name . ' - ' . $tagline : $site_name;
        }

        return get_the_title( $post );
    }

    /**
     * Get meta description from post meta or excerpt.
     * Returns 150-160 character description optimized for SERP display.
     *
     * @param WP_Post $post The post object.
     * @return string
     */
    private static function get_meta_description( $post ) {
        // Check for a stored (manually set or auto-generated) description.
        $stored_desc = get_post_meta( $post->ID, '_msh_seo_description', true );

        // Use stored description ONLY if it exists and isn't garbled.
        // Garbled descriptions were created by old auto-fix that concatenated
        // words when stripping Divi shortcodes (e.g. "BELIEVEWork").
        if ( ! empty( $stored_desc ) && ! self::is_garbled( $stored_desc ) ) {
            // Stored description is clean — use it directly (it's already plain text).
            $desc = self::trim_description( $stored_desc );
            return $desc;
        }

        // Fall through to content extraction.
        $desc = $post->post_excerpt;

        if ( empty( $desc ) ) {
            $desc = $post->post_content;
        }

        // Replace ALL shortcode-like tags with spaces.
        // This prevents "[/et_pb_text][et_pb_text]" from concatenating words.
        $desc = preg_replace( '/\[[^\]]*\]/', ' ', $desc );

        // Add spaces after closing HTML tags before stripping them.
        // This prevents "word1</p><p>word2" becoming "word1word2".
        $desc = preg_replace( '/>(\s*)/', '> ', $desc );

        $desc = wp_strip_all_tags( trim( $desc ) );

        // Remove Table of Contents text that often appears at the start.
        $desc = preg_replace( '/^Table of Contents\s*(Show|Hide)?\s*((\d+(\.\d+)*\s+[^\d]+\s*)+)/i', '', $desc );
        $desc = preg_replace( '/^Table of Contents\s*/i', '', $desc );

        // Clean up excessive whitespace.
        $desc = preg_replace( '/\s+/', ' ', trim( $desc ) );

        // For front page with no content, use tagline.
        if ( empty( $desc ) && is_front_page() ) {
            $desc = get_bloginfo( 'description' );
        }

        // Trim to 160 characters at word boundary.
        $desc = self::trim_description( $desc );

        return $desc;
    }

    /**
     * Trim a description to 160 characters at a word boundary.
     *
     * @param string $desc The description text.
     * @return string
     */
    private static function trim_description( $desc ) {
        if ( mb_strlen( $desc ) > 160 ) {
            $desc = mb_substr( $desc, 0, 157 );
            // Cut at last word boundary
            $last_space = mb_strrpos( $desc, ' ' );
            if ( $last_space > 120 ) {
                $desc = mb_substr( $desc, 0, $last_space );
            }
            $desc .= '...';
        }
        return $desc;
    }

    /**
     * Get the canonical URL for a post.
     *
     * @param WP_Post $post The post object.
     * @return string
     */
    private static function get_canonical_url( $post ) {
        return get_permalink( $post );
    }

    /**
     * Build the robots meta tag value.
     *
     * @param WP_Post $post The post object.
     * @return string
     */
    private static function get_robots_tag( $post ) {
        $noindex = get_post_meta( $post->ID, '_msh_seo_noindex', true );

        if ( $noindex ) {
            return 'noindex, follow';
        }

        return 'index, follow';
    }

    /**
     * Output Open Graph meta tags.
     *
     * @param WP_Post $post The post object.
     */
    private static function output_open_graph( $post ) {
        $title       = self::get_seo_title( $post );
        $description = self::get_meta_description( $post );
        $url         = get_permalink( $post );
        $type        = is_front_page() ? 'website' : 'article';

        printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
        printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
        printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
        printf( '<meta property="og:type" content="%s" />' . "\n", esc_attr( $type ) );
        printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
        printf( '<meta property="og:locale" content="%s" />' . "\n", esc_attr( get_locale() ) );

        $image = get_the_post_thumbnail_url( $post, 'large' );
        if ( $image ) {
            printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
            // Get image dimensions for proper social preview
            $thumb_id = get_post_thumbnail_id( $post );
            if ( $thumb_id ) {
                $img_data = wp_get_attachment_image_src( $thumb_id, 'large' );
                if ( $img_data ) {
                    printf( '<meta property="og:image:width" content="%d" />' . "\n", $img_data[1] );
                    printf( '<meta property="og:image:height" content="%d" />' . "\n", $img_data[2] );
                }
            }
        } else {
            // Fallback to site logo
            self::output_fallback_og_image();
        }

        if ( 'article' === $type ) {
            printf( '<meta property="article:published_time" content="%s" />' . "\n", esc_attr( get_the_date( 'c', $post ) ) );
            printf( '<meta property="article:modified_time" content="%s" />' . "\n", esc_attr( get_the_modified_date( 'c', $post ) ) );
        }
    }

    /**
     * Output a fallback OG image using the site logo or custom logo.
     */
    private static function output_fallback_og_image() {
        // Try custom logo first
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $logo_url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
            if ( $logo_url ) {
                printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $logo_url ) );
                return;
            }
        }

        // Try site icon
        $site_icon_id = get_option( 'site_icon' );
        if ( $site_icon_id ) {
            $icon_url = wp_get_attachment_image_url( $site_icon_id, 'full' );
            if ( $icon_url ) {
                printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $icon_url ) );
                return;
            }
        }
    }

    /**
     * Output a fallback Twitter image using the site logo.
     */
    private static function output_fallback_twitter_image() {
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $logo_url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
            if ( $logo_url ) {
                printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $logo_url ) );
                return;
            }
        }

        $site_icon_id = get_option( 'site_icon' );
        if ( $site_icon_id ) {
            $icon_url = wp_get_attachment_image_url( $site_icon_id, 'full' );
            if ( $icon_url ) {
                printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $icon_url ) );
                return;
            }
        }
    }

    /**
     * Output Twitter Card meta tags.
     *
     * @param WP_Post $post The post object.
     */
    private static function output_twitter_card( $post ) {
        $title       = self::get_seo_title( $post );
        $description = self::get_meta_description( $post );
        $image       = get_the_post_thumbnail_url( $post, 'large' );

        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $title ) );
        printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $description ) );

        // Twitter site/creator from settings
        self::output_twitter_handle();

        if ( $image ) {
            printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $image ) );
        } else {
            // Fallback to site logo
            self::output_fallback_twitter_image();
        }
    }

    /**
     * Output twitter:site and twitter:creator handles if configured.
     */
    private static function output_twitter_handle() {
        $twitter_handle = get_option( 'msh_seo_twitter_handle', '' );
        if ( ! empty( $twitter_handle ) ) {
            $handle = '@' . ltrim( $twitter_handle, '@' );
            printf( '<meta name="twitter:site" content="%s" />' . "\n", esc_attr( $handle ) );
            printf( '<meta name="twitter:creator" content="%s" />' . "\n", esc_attr( $handle ) );
        }
    }

    /**
     * Filter the document title to use MSH SEO title if set.
     *
     * @param array $title_parts The document title parts.
     * @return array
     */
    public static function filter_document_title( $title_parts ) {
        if ( ! is_singular() ) {
            return $title_parts;
        }

        $post = get_queried_object();
        if ( ! $post ) {
            return $title_parts;
        }

        $seo_title = get_post_meta( $post->ID, '_msh_seo_title', true );
        if ( ! empty( $seo_title ) ) {
            // A SERP title is written to fit Google's ~60 characters on its
            // own. Appending " – Site Name" pushed every one past the cut.
            return array( 'title' => $seo_title );
        }

        return $title_parts;
    }
}

// Note: init() is NOT called here because output_meta_tags is already
// hooked directly in msh-seo.php via add_action('wp_head', ...).
// Calling init() here would register output_meta_tags TWICE.
// Only register the document_title filter here.
add_filter( 'document_title_parts', array( 'MSH_Meta_Tags', 'filter_document_title' ) );
