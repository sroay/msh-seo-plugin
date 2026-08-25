<?php
/**
 * MSH Schema Markup Generator
 *
 * Outputs JSON-LD structured data for posts, pages, and archives.
 * Auto-detects FAQ and HowTo content patterns and generates the
 * appropriate schema types. Always includes Organization, WebSite,
 * and BreadcrumbList where applicable.
 *
 * @package MSH_SEO
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Schema {

    /**
     * Register wp_head hook at priority 2 (after meta tags at priority 1).
     *
     * @return void
     */
    public static function init() {
        add_action( 'wp_head', array( __CLASS__, 'output_schema' ), 2 );
    }

    /**
     * Build and output the JSON-LD script tag with a @graph array.
     *
     * @return void
     */
    public static function output_schema() {
        // If WooCommerce Product page, delegate to Product schema
        if ( function_exists( 'is_product' ) && is_product() && class_exists( 'MSH_WooCommerce' ) ) {
            $product_schema = MSH_WooCommerce::get_product_schema( get_the_ID() );
            if ( $product_schema ) {
                echo '<script type="application/ld+json">' . wp_json_encode( $product_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
                return;
            }
        }

        $graph = array();

        // Organization schema on every page (lightweight, expected by Google).
        $graph[] = self::get_organization_schema();

        // WebSite + SearchAction on the homepage only.
        if ( is_front_page() || is_home() ) {
            $graph[] = self::get_website_schema();
        }

        // BreadcrumbList on every page except the homepage.
        if ( ! is_front_page() ) {
            $breadcrumb = self::get_breadcrumb_schema();
            if ( $breadcrumb ) {
                $graph[] = $breadcrumb;
            }
        }

        // Single post / page schemas.
        if ( is_singular() ) {
            $post = get_queried_object();

            if ( $post instanceof WP_Post ) {
                $schemas = self::get_single_schemas( $post );
                foreach ( $schemas as $schema ) {
                    $graph[] = $schema;
                }
            }
        }

        // Remove any null entries.
        $graph = array_filter( $graph );

        if ( empty( $graph ) ) {
            return;
        }

        $output = array(
            '@context' => 'https://schema.org',
            '@graph'   => array_values( $graph ),
        );

        $json = wp_json_encode( $output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

        if ( ! $json ) {
            return;
        }

        echo "\n<!-- MSH SEO: Schema Markup -->\n";
        echo '<script type="application/ld+json">' . "\n";
        // JSON-LD is not HTML — it must not be entity-escaped.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $json;
        echo "\n</script>\n";
    }

    /**
     * Determine schema types for a single post or page.
     *
     * Priority:
     *  1. User-chosen _msh_schema_type override.
     *  2. Auto-detect FAQ patterns  -> FAQPage
     *  3. Auto-detect HowTo patterns -> HowTo
     *  4. Default Article / BlogPosting.
     *
     * @param WP_Post $post The current post object.
     * @return array Array of schema arrays.
     */
    private static function get_single_schemas( WP_Post $post ) {
        $schemas = array();
        $content = $post->post_content;

        // Check for user override.
        $override = get_post_meta( $post->ID, '_msh_schema_type', true );

        if ( 'faq' === $override ) {
            $faq = self::get_faq_schema( $post );
            if ( $faq ) {
                $schemas[] = $faq;
            }
        } elseif ( 'howto' === $override ) {
            $howto = self::get_howto_schema( $post );
            if ( $howto ) {
                $schemas[] = $howto;
            }
        } elseif ( ! empty( $override ) && 'none' !== $override ) {
            // A specific override that is not faq/howto — fall through to article.
        }

        // Auto-detect only when there is no override (or override is empty / "none").
        if ( empty( $schemas ) && ( empty( $override ) || 'none' === $override ) ) {
            $faq_items   = self::extract_faq_items( $content );
            $howto_steps = self::extract_howto_steps( $content );

            if ( count( $faq_items ) >= 2 ) {
                $schemas[] = self::get_faq_schema( $post, $faq_items );
            } elseif ( count( $howto_steps ) >= 2 ) {
                $schemas[] = self::get_howto_schema( $post, $howto_steps );
            }
        }

        // Always add the Article schema for single posts/pages.
        $schemas[] = self::get_article_schema( $post );

        return $schemas;
    }

    // ------------------------------------------------------------------
    // Individual schema builders
    // ------------------------------------------------------------------

    /**
     * Generate Article or BlogPosting schema.
     *
     * @param WP_Post $post The post object.
     * @return array Schema array.
     */
    private static function get_article_schema( WP_Post $post ) {
        $type = ( 'post' === $post->post_type ) ? 'BlogPosting' : 'Article';

        $schema = array(
            '@type'         => $type,
            '@id'           => esc_url( get_permalink( $post ) ) . '#article',
            'headline'      => esc_html( get_the_title( $post ) ),
            'datePublished' => get_the_date( 'c', $post ),
            'dateModified'  => get_the_modified_date( 'c', $post ),
            'mainEntityOfPage' => array(
                '@type' => 'WebPage',
                '@id'   => esc_url( get_permalink( $post ) ),
            ),
            'publisher'     => array(
                '@type' => 'Organization',
                'name'  => esc_html( get_bloginfo( 'name' ) ),
                'url'   => esc_url( home_url( '/' ) ),
            ),
        );

        // Author.
        $author = get_userdata( $post->post_author );
        if ( $author ) {
            $schema['author'] = array(
                '@type' => 'Person',
                'name'  => esc_html( $author->display_name ),
                'url'   => esc_url( get_author_posts_url( $author->ID ) ),
            );
        }

        // Featured image.
        $image_url = self::get_post_image_url( $post->ID );
        if ( $image_url ) {
            $schema['image'] = esc_url( $image_url );
        }

        // Word count.
        $word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );
        if ( $word_count > 0 ) {
            $schema['wordCount'] = $word_count;
        }

        // Description from SEO meta or excerpt.
        $description = get_post_meta( $post->ID, '_msh_seo_description', true );
        if ( empty( $description ) ) {
            $description = get_the_excerpt( $post );
        }
        if ( empty( $description ) ) {
            $description = $post->post_content;
        }
        if ( ! empty( $description ) ) {
            // Strip shortcodes (including Divi page builder shortcodes).
            $description = strip_shortcodes( $description );
            $description = preg_replace( '/\[et_pb_[^\]]*\]/', '', $description );
            $description = preg_replace( '/\[\/et_pb_[^\]]*\]/', '', $description );
            $description = wp_strip_all_tags( trim( $description ) );
            $description = wp_trim_words( $description, 25, '...' );
        }
        if ( ! empty( $description ) ) {
            $schema['description'] = esc_html( $description );
        }

        // AEO: mark the focus keyword as the entity this article is "about" and
        // expose it as keywords — helps answer engines map the page to a topic.
        // NOTE: JSON-LD is not an HTML context — wp_json_encode() handles all
        // escaping. esc_html() here would bake literal entities (&amp;) into
        // the values answer engines read.
        $focus_keyword = get_post_meta( $post->ID, '_msh_focus_keyword', true );
        if ( ! empty( $focus_keyword ) ) {
            $clean_kw           = wp_strip_all_tags( (string) $focus_keyword );
            $schema['about']    = array(
                '@type' => 'Thing',
                'name'  => $clean_kw,
            );
            $schema['keywords'] = $clean_kw;
        }

        // AEO: Speakable — point voice/answer engines at the quotable answer
        // block, but only when the post actually contains one ([msh_answer]
        // shortcode, the msh-seo/answer block, or raw .msh-answer markup).
        if ( false !== strpos( $post->post_content, 'msh_answer' ) || false !== strpos( $post->post_content, 'msh-answer' ) || false !== strpos( $post->post_content, 'msh-seo/answer' ) ) {
            $schema['speakable'] = array(
                '@type'       => 'SpeakableSpecification',
                'cssSelector' => array( '.msh-answer' ),
            );
        }

        return $schema;
    }

    /**
     * Generate FAQPage schema.
     *
     * @param WP_Post    $post  The post object.
     * @param array|null $items Pre-extracted FAQ items or null to extract now.
     * @return array|null Schema array or null if no items found.
     */
    private static function get_faq_schema( WP_Post $post, $items = null ) {
        if ( null === $items ) {
            $items = self::extract_faq_items( $post->post_content );
        }

        if ( empty( $items ) ) {
            return null;
        }

        $main_entity = array();
        foreach ( $items as $item ) {
            $main_entity[] = array(
                '@type'          => 'Question',
                'name'           => esc_html( $item['question'] ),
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => wp_kses_post( $item['answer'] ),
                ),
            );
        }

        return array(
            '@type'      => 'FAQPage',
            '@id'        => esc_url( get_permalink( $post ) ) . '#faq',
            'mainEntity' => $main_entity,
        );
    }

    /**
     * Generate HowTo schema.
     *
     * @param WP_Post    $post  The post object.
     * @param array|null $steps Pre-extracted steps or null to extract now.
     * @return array|null Schema array or null if no steps found.
     */
    private static function get_howto_schema( WP_Post $post, $steps = null ) {
        if ( null === $steps ) {
            $steps = self::extract_howto_steps( $post->post_content );
        }

        if ( empty( $steps ) ) {
            return null;
        }

        $step_items = array();
        foreach ( $steps as $index => $step ) {
            $step_items[] = array(
                '@type'    => 'HowToStep',
                'position' => $index + 1,
                'name'     => esc_html( $step['name'] ),
                'text'     => wp_kses_post( $step['text'] ),
            );
        }

        return array(
            '@type' => 'HowTo',
            '@id'   => esc_url( get_permalink( $post ) ) . '#howto',
            'name'  => esc_html( get_the_title( $post ) ),
            'step'  => $step_items,
        );
    }

    /**
     * Generate BreadcrumbList schema from the page hierarchy / categories.
     *
     * @return array|null Schema array or null if on homepage.
     */
    private static function get_breadcrumb_schema() {
        $items = array();

        // Home is always first.
        $items[] = array(
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => esc_html__( 'Home', 'msh-seo' ),
            'item'     => esc_url( home_url( '/' ) ),
        );

        $position = 2;

        if ( is_singular() ) {
            $post = get_queried_object();

            // For posts, add the primary category.
            if ( $post instanceof WP_Post && 'post' === $post->post_type ) {
                $categories = get_the_category( $post->ID );
                if ( ! empty( $categories ) ) {
                    $cat = $categories[0];
                    // Add parent categories first.
                    $ancestors = get_ancestors( $cat->term_id, 'category' );
                    $ancestors = array_reverse( $ancestors );
                    foreach ( $ancestors as $ancestor_id ) {
                        $ancestor_cat = get_category( $ancestor_id );
                        if ( $ancestor_cat && ! is_wp_error( $ancestor_cat ) ) {
                            $items[] = array(
                                '@type'    => 'ListItem',
                                'position' => $position++,
                                'name'     => esc_html( $ancestor_cat->name ),
                                'item'     => esc_url( get_category_link( $ancestor_cat->term_id ) ),
                            );
                        }
                    }
                    $items[] = array(
                        '@type'    => 'ListItem',
                        'position' => $position++,
                        'name'     => esc_html( $cat->name ),
                        'item'     => esc_url( get_category_link( $cat->term_id ) ),
                    );
                }
            }

            // For hierarchical pages, add parent pages.
            if ( $post instanceof WP_Post && is_post_type_hierarchical( $post->post_type ) && $post->post_parent ) {
                $parent_ids = get_post_ancestors( $post->ID );
                $parent_ids = array_reverse( $parent_ids );
                foreach ( $parent_ids as $parent_id ) {
                    $items[] = array(
                        '@type'    => 'ListItem',
                        'position' => $position++,
                        'name'     => esc_html( get_the_title( $parent_id ) ),
                        'item'     => esc_url( get_permalink( $parent_id ) ),
                    );
                }
            }

            // Current page (no item URL for the last breadcrumb per Google spec).
            if ( $post instanceof WP_Post ) {
                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $position,
                    'name'     => esc_html( get_the_title( $post ) ),
                );
            }
        } elseif ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();
            if ( $term instanceof WP_Term ) {
                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $position,
                    'name'     => esc_html( $term->name ),
                );
            }
        } elseif ( is_search() ) {
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $position,
                'name'     => esc_html__( 'Search Results', 'msh-seo' ),
            );
        }

        if ( count( $items ) < 2 ) {
            return null;
        }

        return array(
            '@type'           => 'BreadcrumbList',
            '@id'             => esc_url( home_url( add_query_arg( array() ) ) ) . '#breadcrumb',
            'itemListElement' => $items,
        );
    }

    /**
     * Generate Organization schema from WordPress site settings.
     *
     * @return array Schema array.
     */
    private static function get_organization_schema() {
        $schema = array(
            '@type' => 'Organization',
            '@id'   => esc_url( home_url( '/' ) ) . '#organization',
            'name'  => esc_html( get_bloginfo( 'name' ) ),
            'url'   => esc_url( home_url( '/' ) ),
        );

        $description = get_bloginfo( 'description' );
        if ( ! empty( $description ) ) {
            $schema['description'] = esc_html( $description );
        }

        // Use the site icon as logo if available.
        $site_icon_id = get_option( 'site_icon' );
        if ( $site_icon_id ) {
            $icon_url = wp_get_attachment_image_url( $site_icon_id, 'full' );
            if ( $icon_url ) {
                $schema['logo'] = array(
                    '@type' => 'ImageObject',
                    'url'   => esc_url( $icon_url ),
                );
            }
        }

        // Entity graph: sameAs social/authority profiles (set in MSH SEO
        // settings). Strengthens the brand's knowledge-graph identity.
        $profiles = get_option( 'msh_seo_social_profiles', array() );
        if ( is_string( $profiles ) ) {
            $profiles = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $profiles ) ) );
        }
        if ( ! empty( $profiles ) && is_array( $profiles ) ) {
            $same_as = array();
            foreach ( $profiles as $profile ) {
                $u = esc_url_raw( $profile );
                if ( $u ) {
                    $same_as[] = $u;
                }
            }
            if ( ! empty( $same_as ) ) {
                $schema['sameAs'] = array_values( $same_as );
            }
        }

        return $schema;
    }

    /**
     * Generate WebSite schema with SearchAction.
     *
     * @return array Schema array.
     */
    private static function get_website_schema() {
        return array(
            '@type'           => 'WebSite',
            '@id'             => esc_url( home_url( '/' ) ) . '#website',
            'name'            => esc_html( get_bloginfo( 'name' ) ),
            'url'             => esc_url( home_url( '/' ) ),
            'potentialAction' => array(
                '@type'       => 'SearchAction',
                'target'      => esc_url( home_url( '/' ) ) . '?s={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ),
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Get the featured image URL for a post.
     *
     * @param int $post_id The post ID.
     * @return string|false Image URL or false.
     */
    private static function get_post_image_url( $post_id ) {
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( ! $thumb_id ) {
            return false;
        }

        $url = wp_get_attachment_image_url( $thumb_id, 'full' );
        return $url ? $url : false;
    }

    /**
     * Extract FAQ items from post content.
     *
     * Looks for patterns:
     *  - <h2>question?</h2> followed by <p>answer</p>
     *  - <h3>question?</h3> followed by <p>answer</p>
     *  - <strong>Q:</strong> text / <p>A: text</p>
     *
     * @param string $content The raw post content.
     * @return array Array of associative arrays with 'question' and 'answer' keys.
     */
    private static function extract_faq_items( $content ) {
        $items = array();

        // Pattern 1: heading with question mark followed by paragraph(s).
        // Use [^<]{3,300} to only match text content within the heading (no nested tags),
        // capped at 300 chars to prevent gobbling malformed HTML.
        if ( preg_match_all(
            '/<h[23][^>]*>\s*([^<]{3,300}\?)\s*<\/h[23]>\s*(?:<p[^>]*>(.*?)<\/p>)/is',
            $content,
            $matches,
            PREG_SET_ORDER
        ) ) {
            foreach ( $matches as $match ) {
                $question = wp_strip_all_tags( trim( $match[1] ) );
                $answer   = wp_strip_all_tags( trim( $match[2] ) );
                // Skip if question is too long (likely malformed content).
                if ( mb_strlen( $question ) > 300 || empty( $question ) || empty( $answer ) ) {
                    continue;
                }
                $items[] = array(
                    'question' => $question,
                    'answer'   => $answer,
                );
            }
        }

        // Pattern 2: Q: / A: pattern.
        if ( empty( $items ) && preg_match_all(
            '/<(?:strong|b)[^>]*>\s*Q:\s*<\/(?:strong|b)>\s*([^<]{3,300})\s*<(?:p|div)[^>]*>\s*(?:<(?:strong|b)[^>]*>\s*)?A:\s*(?:<\/(?:strong|b)>\s*)?(.*?)\s*<\/(?:p|div)>/is',
            $content,
            $matches,
            PREG_SET_ORDER
        ) ) {
            foreach ( $matches as $match ) {
                $question = wp_strip_all_tags( trim( $match[1] ) );
                $answer   = wp_strip_all_tags( trim( $match[2] ) );
                if ( mb_strlen( $question ) > 300 || empty( $question ) || empty( $answer ) ) {
                    continue;
                }
                $items[] = array(
                    'question' => $question,
                    'answer'   => $answer,
                );
            }
        }

        return $items;
    }

    /**
     * Extract HowTo steps from post content.
     *
     * Looks for patterns:
     *  - <h2>Step N: title</h2> or <h3>Step N: title</h3>
     *  - Ordered list items <ol><li>...</li></ol>
     *
     * @param string $content The raw post content.
     * @return array Array of associative arrays with 'name' and 'text' keys.
     */
    private static function extract_howto_steps( $content ) {
        $steps = array();

        // Pattern 1: "Step N:" headings followed by paragraph.
        if ( preg_match_all(
            '/<h[23][^>]*>\s*(?:Step\s+\d+[:\.\)]\s*)(.*?)\s*<\/h[23]>\s*(<p[^>]*>.*?<\/p>)/is',
            $content,
            $matches,
            PREG_SET_ORDER
        ) ) {
            foreach ( $matches as $match ) {
                $name = wp_strip_all_tags( $match[1] );
                $text = wp_strip_all_tags( $match[2] );
                if ( ! empty( $name ) && ! empty( $text ) ) {
                    $steps[] = array(
                        'name' => trim( $name ),
                        'text' => trim( $text ),
                    );
                }
            }
        }

        // Pattern 2: Ordered list items (only if no heading steps found).
        if ( empty( $steps ) && preg_match_all(
            '/<ol[^>]*>(.*?)<\/ol>/is',
            $content,
            $ol_matches
        ) ) {
            // Use only the first ordered list.
            $ol_content = $ol_matches[1][0];
            if ( preg_match_all(
                '/<li[^>]*>(.*?)<\/li>/is',
                $ol_content,
                $li_matches
            ) ) {
                foreach ( $li_matches[1] as $li ) {
                    $text = wp_strip_all_tags( $li );
                    if ( ! empty( $text ) ) {
                        // Use the first sentence as the name, full text as text.
                        $first_sentence = strtok( $text, '.' );
                        $steps[] = array(
                            'name' => trim( $first_sentence ),
                            'text' => trim( $text ),
                        );
                    }
                }
            }
        }

        return $steps;
    }
}
