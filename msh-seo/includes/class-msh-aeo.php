<?php
/**
 * MSH AEO (Answer Engine Optimization) Module
 *
 * Scores content for AI search engine citability and provides
 * actionable tips to improve visibility in AI-generated answers.
 * Also generates llms.txt for AI crawler discoverability.
 *
 * @package MSH_SEO
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_AEO {

    /**
     * Local AEO scoring — no API needed.
     *
     * Scores content for AI search engine citability across 11 checks
     * totaling 100 points.
     *
     * @param string $content Post content (HTML).
     * @param string $title   Post title.
     * @return array { score: int, max_score: int, checks: array[] }
     */
    public static function analyze( $content, $title ) {
        $checks    = array();
        $plain     = wp_strip_all_tags( $content );
        $lower     = strtolower( $plain );
        $word_count = str_word_count( $plain );

        // 1. FAQ section with Q&A format (15pts).
        $checks[] = self::check_faq_section( $content );

        // 2. Concise definition paragraph (10pts).
        $checks[] = self::check_definition_paragraph( $content, $title );

        // 3. Numbered / ordered lists (10pts).
        $checks[] = self::check_ordered_lists( $content );

        // 4. Data / statistics with sources (10pts).
        $checks[] = self::check_statistics( $plain );

        // 5. Comparison table or structured data (10pts).
        $checks[] = self::check_comparison_tables( $content );

        // 6. TL;DR or summary section (8pts).
        $checks[] = self::check_summary_section( $content, $lower );

        // 7. Content length > 1500 words (5pts).
        $checks[] = self::check_content_length( $word_count );

        // 8. Author bio / E-E-A-T signals (8pts).
        $checks[] = self::check_eeat_signals( $content, $plain );

        // 9. Schema markup matching content type (8pts).
        $checks[] = self::check_schema_presence( $content );

        // 10. Clear heading hierarchy (8pts).
        $checks[] = self::check_heading_hierarchy( $content );

        // 11. External citations / references (8pts).
        $checks[] = self::check_external_citations( $content );

        $total = 0;
        $max   = 0;
        foreach ( $checks as $check ) {
            $total += $check['score'];
            $max   += $check['max'];
        }

        return array(
            'score'     => $total,
            'max_score' => $max,
            'checks'    => $checks,
        );
    }

    /**
     * Generate llms.txt content for the site.
     *
     * Follows the https://llmstxt.org specification to help AI crawlers
     * understand the site's structure and content.
     *
     * @param bool $full Whether to include detailed post summaries.
     * @return string The llms.txt content.
     */
    public static function generate_llms_txt( $full = false ) {
        $site_name    = get_bloginfo( 'name' );
        $site_desc    = get_bloginfo( 'description' );
        $site_url     = get_site_url();
        $admin_email  = get_bloginfo( 'admin_email' );

        $output = '# ' . $site_name . "\n";
        if ( ! empty( $site_desc ) ) {
            $output .= '> ' . $site_desc . "\n";
        }
        $output .= "\n";

        // About section.
        $about_page = get_page_by_path( 'about' );
        if ( ! $about_page ) {
            $about_page = get_page_by_path( 'about-us' );
        }

        $output .= "## About\n";
        if ( $about_page && 'publish' === $about_page->post_status ) {
            $about_text = strip_shortcodes( $about_page->post_content );
            $about_text = preg_replace( '/\[et_pb_[^\]]*\]/', '', $about_text );
            $about_text = preg_replace( '/\[\/et_pb_[^\]]*\]/', '', $about_text );
            $excerpt = wp_trim_words( wp_strip_all_tags( trim( $about_text ) ), 50, '...' );
            $output .= $excerpt . "\n";
        } else {
            $output .= ( ! empty( $site_desc ) ? $site_desc : $site_name ) . "\n";
        }
        $output .= "\n";

        // Topics / categories.
        $categories = get_categories( array(
            'orderby'    => 'count',
            'order'      => 'DESC',
            'number'     => 10,
            'hide_empty' => true,
        ) );

        if ( ! empty( $categories ) ) {
            $output .= "## Topics\n";
            foreach ( $categories as $cat ) {
                $cat_url  = get_category_link( $cat->term_id );
                $cat_desc = ! empty( $cat->description ) ? $cat->description : $cat->name . ' articles';
                $output  .= '- [' . $cat->name . '](' . $cat_url . '): ' . $cat_desc . "\n";
            }
            $output .= "\n";
        }

        // Key pages.
        $key_pages = get_pages( array(
            'sort_column' => 'menu_order',
            'number'      => 20,
            'post_status' => 'publish',
        ) );

        if ( ! empty( $key_pages ) ) {
            $output .= "## Key Pages\n";
            foreach ( $key_pages as $page ) {
                $output .= '- [' . $page->post_title . '](' . get_permalink( $page->ID ) . ")\n";
            }
            $output .= "\n";
        }

        // Recent articles (full version includes summaries).
        $post_count = $full ? 50 : 20;
        $posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $post_count,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        if ( ! empty( $posts ) ) {
            $output .= "## Recent Articles\n";
            foreach ( $posts as $post ) {
                $url = get_permalink( $post->ID );
                if ( $full ) {
                    $post_text = strip_shortcodes( $post->post_content );
                    $post_text = preg_replace( '/\[et_pb_[^\]]*\]/', '', $post_text );
                    $post_text = preg_replace( '/\[\/et_pb_[^\]]*\]/', '', $post_text );
                    $excerpt = wp_trim_words( wp_strip_all_tags( $post_text ), 30 );
                    $output .= '- [' . $post->post_title . '](' . $url . '): ' . $excerpt . "\n";
                } else {
                    $output .= '- [' . $post->post_title . '](' . $url . ")\n";
                }
            }
            $output .= "\n";
        }

        // Contact.
        $output .= "## Contact\n";
        $output .= '- Website: ' . $site_url . "\n";
        if ( ! empty( $admin_email ) ) {
            $output .= '- Email: ' . $admin_email . "\n";
        }
        $contact_page = get_page_by_path( 'contact' );
        if ( ! $contact_page ) {
            $contact_page = get_page_by_path( 'contact-us' );
        }
        if ( $contact_page && 'publish' === $contact_page->post_status ) {
            $output .= '- Contact Page: ' . get_permalink( $contact_page->ID ) . "\n";
        }

        return $output;
    }

    /**
     * Initialize AEO module.
     *
     * Note: llms.txt serving is handled by MSH_Crawlers to avoid
     * duplicate /llms.txt handlers. This class focuses on AEO analysis.
     *
     * @return void
     */
    public static function init() {
        // AEO analysis is available via REST API and get_aeo_tips().
        // llms.txt serving is delegated to MSH_Crawlers::init().
    }

    /**
     * Check if content is structured for AI citation and return tips.
     *
     * Analyzes the post content and returns specific actionable tips
     * to improve AI search engine visibility.
     *
     * @param int $post_id The WordPress post ID.
     * @return array Array of tip strings.
     */
    public static function get_aeo_tips( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array();
        }

        $result = self::analyze( $post->post_content, $post->post_title );
        $tips   = array();

        foreach ( $result['checks'] as $check ) {
            if ( ! $check['pass'] && ! empty( $check['tip'] ) ) {
                $tips[] = $check['tip'];
            }
        }

        return $tips;
    }

    // ---------------------------------------------------------------
    // Individual check methods
    // ---------------------------------------------------------------

    /**
     * Check for FAQ section with Q&A format.
     *
     * @param string $content HTML content.
     * @return array Check result.
     */
    private static function check_faq_section( $content ) {
        $has_faq = false;

        // Look for headings ending with "?" followed by paragraph content.
        if ( preg_match_all( '/<h[2-4][^>]*>[^<]*\?<\/h[2-4]>/i', $content, $matches ) ) {
            $has_faq = count( $matches[0] ) >= 2;
        }

        // Also check for "FAQ" in a heading.
        if ( ! $has_faq && preg_match( '/<h[2-4][^>]*>[^<]*FAQ[^<]*<\/h[2-4]>/i', $content ) ) {
            $has_faq = true;
        }

        return array(
            'name'  => __( 'FAQ Section', 'msh-seo' ),
            'pass'  => $has_faq,
            'score' => $has_faq ? 15 : 0,
            'max'   => 15,
            'tip'   => __( 'Add a FAQ section with 3-5 questions and concise answers to increase AI citation likelihood.', 'msh-seo' ),
        );
    }

    /**
     * Check for concise definition paragraph.
     *
     * @param string $content HTML content.
     * @param string $title   Post title.
     * @return array Check result.
     */
    private static function check_definition_paragraph( $content, $title ) {
        $has_definition = false;

        // Extract first paragraph.
        if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content, $match ) ) {
            $first_para  = wp_strip_all_tags( $match[1] );
            $sentences   = preg_split( '/[.!?]+/', $first_para, -1, PREG_SPLIT_NO_EMPTY );
            $sent_count  = count( $sentences );

            // 1-3 sentences and contains a definition pattern ("is a", "refers to", "means").
            if ( $sent_count >= 1 && $sent_count <= 3 ) {
                $lower = strtolower( $first_para );
                if ( preg_match( '/\b(is a|is an|refers to|means|defined as|describes)\b/', $lower ) ) {
                    $has_definition = true;
                }
            }
        }

        return array(
            'name'  => __( 'Definition Paragraph', 'msh-seo' ),
            'pass'  => $has_definition,
            'score' => $has_definition ? 10 : 0,
            'max'   => 10,
            'tip'   => __( 'Start with a 1-3 sentence definition paragraph using the pattern "X is a Y that Z" for quick AI extraction.', 'msh-seo' ),
        );
    }

    /**
     * Check for numbered / ordered lists.
     *
     * @param string $content HTML content.
     * @return array Check result.
     */
    private static function check_ordered_lists( $content ) {
        $has_list = (bool) preg_match( '/<ol[^>]*>/i', $content );

        // Also count step-based patterns ("Step 1:", "1.", etc.) in headings.
        if ( ! $has_list ) {
            $has_list = (bool) preg_match( '/<h[2-4][^>]*>[^<]*(step\s+\d|#\d|\d+\.)/i', $content );
        }

        return array(
            'name'  => __( 'Ordered Lists', 'msh-seo' ),
            'pass'  => $has_list,
            'score' => $has_list ? 10 : 0,
            'max'   => 10,
            'tip'   => __( 'Add numbered lists or step-by-step instructions. AI models prefer structured, ordered content.', 'msh-seo' ),
        );
    }

    /**
     * Check for data / statistics with sources.
     *
     * @param string $plain Plain text content.
     * @return array Check result.
     */
    private static function check_statistics( $plain ) {
        $lower     = strtolower( $plain );
        $has_stats = false;

        // Look for percentage patterns.
        $has_numbers = (bool) preg_match( '/\d+(\.\d+)?%/', $plain );

        // Look for citation patterns.
        $has_source = (bool) preg_match( '/\b(according to|source:|study|research|survey|report|data from|statistics show)\b/i', $lower );

        $has_stats = $has_numbers && $has_source;

        // Partial credit: at least has numbers.
        $score = 0;
        if ( $has_stats ) {
            $score = 10;
        } elseif ( $has_numbers || $has_source ) {
            $score = 5;
        }

        return array(
            'name'  => __( 'Statistics & Sources', 'msh-seo' ),
            'pass'  => $has_stats,
            'score' => $score,
            'max'   => 10,
            'tip'   => __( 'Include specific statistics with source citations (e.g., "According to [Source], 75% of...") for credibility.', 'msh-seo' ),
        );
    }

    /**
     * Check for comparison tables or structured data.
     *
     * @param string $content HTML content.
     * @return array Check result.
     */
    private static function check_comparison_tables( $content ) {
        $has_table = (bool) preg_match( '/<table[^>]*>/i', $content );

        // Also check for comparison patterns.
        if ( ! $has_table ) {
            $has_table = (bool) preg_match( '/\b(vs\.?|versus|compared to|comparison)\b/i', $content );
        }

        return array(
            'name'  => __( 'Comparison / Tables', 'msh-seo' ),
            'pass'  => $has_table,
            'score' => $has_table ? 10 : 0,
            'max'   => 10,
            'tip'   => __( 'Add a comparison table or structured pros/cons list. AI engines frequently cite tabular data.', 'msh-seo' ),
        );
    }

    /**
     * Check for TL;DR or summary section.
     *
     * @param string $content HTML content.
     * @param string $lower   Lowercase plain text.
     * @return array Check result.
     */
    private static function check_summary_section( $content, $lower ) {
        $has_summary = false;

        // Check for TL;DR, Summary, Key Takeaways in headings or bold text.
        $patterns = array(
            '/<h[2-4][^>]*>[^<]*(tl;?dr|summary|key takeaways|in summary|overview|at a glance)[^<]*<\/h[2-4]>/i',
            '/<strong>[^<]*(tl;?dr|key takeaways)[^<]*<\/strong>/i',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $content ) ) {
                $has_summary = true;
                break;
            }
        }

        // Check plain text too.
        if ( ! $has_summary ) {
            $has_summary = (bool) preg_match( '/\btl;?dr\b/i', $lower );
        }

        return array(
            'name'  => __( 'TL;DR / Summary', 'msh-seo' ),
            'pass'  => $has_summary,
            'score' => $has_summary ? 8 : 0,
            'max'   => 8,
            'tip'   => __( 'Add a "TL;DR" or "Key Takeaways" section at the top or bottom for quick AI extraction.', 'msh-seo' ),
        );
    }

    /**
     * Check content length (> 1500 words).
     *
     * @param int $word_count Number of words.
     * @return array Check result.
     */
    private static function check_content_length( $word_count ) {
        $pass = $word_count >= 1500;

        return array(
            'name'  => __( 'Content Length', 'msh-seo' ),
            'pass'  => $pass,
            'score' => $pass ? 5 : ( $word_count >= 800 ? 3 : 0 ),
            'max'   => 5,
            'tip'   => sprintf(
                /* translators: %d: current word count */
                __( 'Aim for 1,500+ words for comprehensive coverage. Current: %d words. AI prefers in-depth content to cite.', 'msh-seo' ),
                $word_count
            ),
        );
    }

    /**
     * Check for author bio / E-E-A-T signals.
     *
     * @param string $content HTML content.
     * @param string $plain   Plain text content.
     * @return array Check result.
     */
    private static function check_eeat_signals( $content, $plain ) {
        $lower     = strtolower( $plain );
        $has_eeat  = false;

        // Look for author-related patterns.
        $patterns = array(
            '/\b(written by|author|reviewed by|expert|credentials|years of experience|certified|ph\.?d|m\.?d)\b/i',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $lower ) ) {
                $has_eeat = true;
                break;
            }
        }

        // Check for author box class patterns.
        if ( ! $has_eeat ) {
            $has_eeat = (bool) preg_match( '/class="[^"]*author[^"]*"/i', $content );
        }

        return array(
            'name'  => __( 'E-E-A-T Signals', 'msh-seo' ),
            'pass'  => $has_eeat,
            'score' => $has_eeat ? 8 : 0,
            'max'   => 8,
            'tip'   => __( 'Add author credentials, "Written by" attribution, or an author bio box to boost E-E-A-T signals.', 'msh-seo' ),
        );
    }

    /**
     * Check for schema markup matching content type.
     *
     * @param string $content HTML content.
     * @return array Check result.
     */
    private static function check_schema_presence( $content ) {
        // Check if the post has FAQ-style content and corresponding schema set.
        $has_faq_content = (bool) preg_match( '/<h[2-4][^>]*>[^<]*\?<\/h[2-4]>/i', $content );
        $has_howto       = (bool) preg_match( '/<h[2-4][^>]*>[^<]*(step\s+\d|how to)/i', $content );

        // We check for application/ld+json in the content (unlikely in post body),
        // or rely on whether MSH Schema is generating it. Give credit if schema class exists.
        $schema_active = class_exists( 'MSH_Schema' );

        return array(
            'name'  => __( 'Schema Markup', 'msh-seo' ),
            'pass'  => $schema_active,
            'score' => $schema_active ? 8 : 0,
            'max'   => 8,
            'tip'   => __( 'Ensure FAQ schema is set for FAQ content and HowTo schema for tutorials. MSH SEO auto-generates schema when active.', 'msh-seo' ),
        );
    }

    /**
     * Check for clear heading hierarchy.
     *
     * @param string $content HTML content.
     * @return array Check result.
     */
    private static function check_heading_hierarchy( $content ) {
        $has_h2 = (bool) preg_match( '/<h2[^>]*>/i', $content );
        $has_h3 = (bool) preg_match( '/<h3[^>]*>/i', $content );

        // Count headings.
        preg_match_all( '/<h[2-6][^>]*>/i', $content, $headings );
        $heading_count = count( $headings[0] );

        $pass = $has_h2 && $heading_count >= 3;

        $score = 0;
        if ( $pass && $has_h3 ) {
            $score = 8;
        } elseif ( $pass ) {
            $score = 5;
        } elseif ( $has_h2 ) {
            $score = 3;
        }

        return array(
            'name'  => __( 'Heading Hierarchy', 'msh-seo' ),
            'pass'  => $pass && $has_h3,
            'score' => $score,
            'max'   => 8,
            'tip'   => __( 'Use a clear H2 > H3 hierarchy with 3+ headings. AI engines rely on heading structure to parse content.', 'msh-seo' ),
        );
    }

    /**
     * Check for external citations / references.
     *
     * @param string $content HTML content.
     * @return array Check result.
     */
    private static function check_external_citations( $content ) {
        $site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );

        // Find all links.
        preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );

        $external_count = 0;
        if ( ! empty( $matches[1] ) ) {
            foreach ( $matches[1] as $url ) {
                $host = wp_parse_url( $url, PHP_URL_HOST );
                if ( $host && $host !== $site_host ) {
                    $external_count++;
                }
            }
        }

        $pass = $external_count >= 2;

        return array(
            'name'  => __( 'External Citations', 'msh-seo' ),
            'pass'  => $pass,
            'score' => $pass ? 8 : ( $external_count >= 1 ? 4 : 0 ),
            'max'   => 8,
            'tip'   => __( 'Link to 2+ authoritative external sources. Citations boost credibility and AI citation confidence.', 'msh-seo' ),
        );
    }
}
