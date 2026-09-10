<?php
/**
 * MSH Page Types — Marketing Page SEO
 *
 * Provides page type detection and type-specific SEO scoring rules.
 * Different pages (homepage, contact, landing, etc.) have fundamentally
 * different SEO requirements compared to blog posts.
 *
 * @package MSH_SEO
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Page_Types {

    /**
     * Auto-detect page type from content, template, and URL.
     *
     * @param int $post_id WordPress post ID.
     * @return string The detected page type slug.
     */
    public static function detect_page_type( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return 'post';
        }

        // Blog posts are always 'post'.
        if ( 'post' === $post->post_type ) {
            return 'post';
        }

        // WooCommerce product.
        if ( 'product' === $post->post_type ) {
            return 'product';
        }

        // Check if this is the front page.
        $front_page_id = (int) get_option( 'page_on_front' );
        if ( $front_page_id && $front_page_id === $post->ID ) {
            return 'homepage';
        }

        // Check if this is the posts page (blog index).
        $posts_page_id = (int) get_option( 'page_for_posts' );
        if ( $posts_page_id && $posts_page_id === $post->ID ) {
            return 'blog_index';
        }

        // WooCommerce special pages.
        if ( function_exists( 'wc_get_page_id' ) ) {
            if ( $post->ID === wc_get_page_id( 'shop' ) ) {
                return 'shop_page';
            }
            if ( $post->ID === wc_get_page_id( 'cart' ) ) {
                return 'cart_page';
            }
            if ( $post->ID === wc_get_page_id( 'checkout' ) ) {
                return 'checkout_page';
            }
            if ( $post->ID === wc_get_page_id( 'myaccount' ) ) {
                return 'account_page';
            }
        }

        // Check page template.
        $template = get_page_template_slug( $post_id );
        if ( ! empty( $template ) ) {
            $template_lower = strtolower( $template );
            $template_map   = array(
                'homepage'  => 'homepage',
                'landing'   => 'landing_page',
                'contact'   => 'contact_page',
                'about'     => 'about_page',
                'services'  => 'services_page',
                'pricing'   => 'pricing_page',
                'faq'       => 'faq_page',
            );
            foreach ( $template_map as $needle => $type ) {
                if ( strpos( $template_lower, $needle ) !== false ) {
                    return $type;
                }
            }
        }

        // Detect by URL slug.
        $slug = $post->post_name;
        if ( ! empty( $slug ) ) {
            $slug_map = array(
                'homepage'      => array( 'home' ),
                'about_page'    => array( 'about', 'about-us', 'who-we-are' ),
                'contact_page'  => array( 'contact', 'contact-us', 'get-in-touch' ),
                'services_page' => array( 'services', 'our-services', 'what-we-do' ),
                'pricing_page'  => array( 'pricing', 'plans', 'prices' ),
                'faq_page'      => array( 'faq', 'faqs', 'frequently-asked-questions' ),
                'legal_page'    => array( 'privacy', 'privacy-policy', 'terms', 'terms-of-service', 'terms-and-conditions', 'cookie-policy', 'disclaimer' ),
            );

            foreach ( $slug_map as $type => $slugs ) {
                if ( in_array( $slug, $slugs, true ) ) {
                    return $type;
                }
            }

            // Landing page prefix patterns.
            if ( preg_match( '/^(landing|lp)-/', $slug ) ) {
                return 'landing_page';
            }
        }

        return 'standard_page';
    }

    /**
     * Get scoring rules for a specific page type.
     *
     * Returns an array of checks with weights appropriate for the page type.
     * Each check contains: name, max score, description, and a callable checker.
     *
     * @param string $page_type The page type slug.
     * @return array Array of scoring rule definitions.
     */
    public static function get_scoring_rules( $page_type ) {
        switch ( $page_type ) {
            case 'homepage':
                return self::homepage_rules();
            case 'services_page':
            case 'landing_page':
                return self::landing_page_rules();
            case 'contact_page':
                return self::contact_page_rules();
            case 'pricing_page':
                return self::pricing_page_rules();
            case 'faq_page':
                return self::faq_page_rules();
            case 'product':
                return self::product_rules();
            case 'about_page':
                return self::about_page_rules();
            case 'legal_page':
                return self::legal_page_rules();
            case 'blog_index':
                return self::blog_index_rules();
            case 'post':
                return null; // Delegate to MSH_SEO_Analysis::analyze().
            default:
                return self::standard_page_rules();
        }
    }

    /**
     * Score a page using its type-specific rules.
     *
     * @param int $post_id WordPress post ID.
     * @return array { page_type, score, max_score, checks[] } or null if delegated.
     */
    public static function score_page( $post_id ) {
        $page_type = self::detect_page_type( $post_id );
        $rules     = self::get_scoring_rules( $page_type );

        // Delegate blog posts to standard SEO analysis.
        if ( null === $rules ) {
            return null;
        }

        $post    = get_post( $post_id );
        $content = $post ? $post->post_content : '';
        $title   = $post ? $post->post_title : '';
        $meta    = get_post_meta( $post_id, '_msh_seo_description', true );
        $keyword = get_post_meta( $post_id, '_msh_focus_keyword', true );
        $schema  = get_post_meta( $post_id, '_msh_schema_type', true );

        $context = array(
            'post_id' => $post_id,
            'content' => $content,
            'title'   => $title,
            'meta'    => $meta,
            'keyword' => $keyword,
            'schema'  => $schema,
            'slug'    => $post ? $post->post_name : '',
        );

        $checks      = array();
        $total_score = 0;
        $max_score   = 0;

        foreach ( $rules as $rule ) {
            $result       = call_user_func( $rule['check'], $context );
            $total_score += $result['score'];
            $max_score   += $rule['max'];
            $checks[]     = array_merge( $result, array( 'max' => $rule['max'] ) );
        }

        return array(
            'page_type' => $page_type,
            'score'     => $total_score,
            'max_score' => $max_score,
            'checks'    => $checks,
        );
    }

    /**
     * Get the recommended schema type(s) for a page type.
     *
     * @param string $page_type The page type slug.
     * @return array Array of schema type strings.
     */
    public static function get_recommended_schema( $page_type ) {
        $map = array(
            'homepage'      => array( 'WebSite', 'Organization' ),
            'about_page'    => array( 'AboutPage' ),
            'contact_page'  => array( 'ContactPage', 'LocalBusiness' ),
            'services_page' => array( 'Service' ),
            'pricing_page'  => array( 'WebPage', 'Offer' ),
            'faq_page'      => array( 'FAQPage' ),
            'blog_index'    => array( 'CollectionPage' ),
            'landing_page'  => array( 'WebPage' ),
            'product'       => array( 'Product' ),
            'post'          => array( 'Article', 'BlogPosting' ),
            'legal_page'    => array( 'WebPage' ),
            'standard_page' => array( 'WebPage' ),
            'shop_page'     => array( 'CollectionPage', 'Store' ),
        );

        return isset( $map[ $page_type ] ) ? $map[ $page_type ] : array( 'WebPage' );
    }

    // ---------------------------------------------------------------
    // Rule sets per page type
    // ---------------------------------------------------------------

    /**
     * Homepage scoring rules.
     *
     * @return array
     */
    private static function homepage_rules() {
        return array(
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['schema'] ) && in_array( $ctx['schema'], array( 'Organization', 'WebSite' ), true );
                    return array(
                        'name'  => __( 'Organization Schema', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Set Organization or WebSite schema for your homepage.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<h1[^>]*>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'H1 with Brand / Value Prop', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 10 : 0,
                        'tip'   => __( 'Add a clear H1 heading with your brand name or value proposition.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['meta'] );
                    return array(
                        'name'  => __( 'Meta Description', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 10 : 0,
                        'tip'   => __( 'Add a meta description summarizing what your site offers.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );
                    preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $ctx['content'], $m );
                    $internal = 0;
                    foreach ( $m[1] ?? array() as $url ) {
                        $host = wp_parse_url( $url, PHP_URL_HOST );
                        if ( ! $host || $host === $site_host ) {
                            $internal++;
                        }
                    }
                    $pass = $internal >= 3;
                    return array(
                        'name'  => __( 'Internal Links to Key Pages', 'msh-seo' ),
                        'pass'  => $pass,
                        'score' => $pass ? 15 : ( $internal >= 1 ? 8 : 0 ),
                        'tip'   => __( 'Link to your key internal pages (services, about, blog) from the homepage.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<img[^>]+/i', $ctx['content'] );
                    $og  = get_post_meta( $ctx['post_id'], '_msh_og_image', true );
                    $pass = $has || ! empty( $og ) || has_post_thumbnail( $ctx['post_id'] );
                    return array(
                        'name'  => __( 'OG Image Set', 'msh-seo' ),
                        'pass'  => $pass,
                        'score' => $pass ? 10 : 0,
                        'tip'   => __( 'Set a featured image or OG image for social sharing.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has_cta = (bool) preg_match( '/<(a|button)[^>]*>[^<]*(get started|sign up|try|contact|learn more|start|buy|subscribe|book|schedule)[^<]*<\/(a|button)>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Call to Action', 'msh-seo' ),
                        'pass'  => $has_cta,
                        'score' => $has_cta ? 10 : 0,
                        'tip'   => __( 'Add a clear call-to-action button (e.g., "Get Started", "Contact Us").', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    // SearchAction schema check — presence of search functionality.
                    $has_search = (bool) preg_match( '/<form[^>]*role=["\']search["\']|<input[^>]*type=["\']search["\']/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'SearchAction Schema', 'msh-seo' ),
                        'pass'  => $has_search,
                        'score' => $has_search ? 10 : 5, // 5 pts partial — MSH auto-adds WebSite SearchAction.
                        'tip'   => __( 'Consider adding a site search form. MSH SEO auto-generates SearchAction schema.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 5,
                'check' => function ( $ctx ) {
                    $wc = str_word_count( wp_strip_all_tags( $ctx['content'] ) );
                    $ok = $wc <= 800 && $wc >= 100;
                    return array(
                        'name'  => __( 'Concise Content', 'msh-seo' ),
                        'pass'  => $ok,
                        'score' => $ok ? 5 : 0,
                        'tip'   => __( 'Homepage content should be concise and scannable, not excessively long.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 5,
                'check' => function ( $ctx ) {
                    $has_viewport = true; // WordPress themes almost always include this.
                    return array(
                        'name'  => __( 'Mobile-Friendly Viewport', 'msh-seo' ),
                        'pass'  => $has_viewport,
                        'score' => 5,
                        'tip'   => __( 'Ensure your theme sets a proper viewport meta tag.', 'msh-seo' ),
                    );
                },
            ),
        );
    }

    /**
     * Service / landing page scoring rules.
     *
     * @return array
     */
    private static function landing_page_rules() {
        return array(
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<h1[^>]*>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Clear H1 Heading', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 10 : 0,
                        'tip'   => __( 'Add a clear H1 with the service or offer name.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['schema'] ) && in_array( $ctx['schema'], array( 'Service', 'Product', 'WebPage' ), true );
                    return array(
                        'name'  => __( 'Service Schema', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Set the schema type to "Service" for service pages.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/\b(testimonial|review|client|customer said|rating|stars)\b/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Testimonials / Reviews', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 10 : 0,
                        'tip'   => __( 'Add customer testimonials or reviews to build trust.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has_cta = (bool) preg_match( '/<(a|button)[^>]*>[^<]*(get started|sign up|try|contact|buy|book|schedule|request|free trial|demo)[^<]*<\/(a|button)>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'CTA Above the Fold', 'msh-seo' ),
                        'pass'  => $has_cta,
                        'score' => $has_cta ? 15 : 0,
                        'tip'   => __( 'Place a prominent call-to-action button near the top of the page.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<(ul|ol)[^>]*>(\s*<li[^>]*>.*?<\/li>\s*){3,}/is', $ctx['content'] );
                    return array(
                        'name'  => __( 'Benefits List', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 10 : 0,
                        'tip'   => __( 'List at least 3 key benefits or features using bullet points.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<h[2-4][^>]*>[^<]*\?<\/h[2-4]>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'FAQ Section', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 10 : 0,
                        'tip'   => __( 'Add a FAQ section to address common questions about the service.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $in_h1 = false;
                    if ( ! empty( $ctx['keyword'] ) && preg_match( '/<h1[^>]*>(.*?)<\/h1>/i', $ctx['content'], $m ) ) {
                        $in_h1 = stripos( $m[1], $ctx['keyword'] ) !== false;
                    }
                    $in_first = false;
                    if ( ! empty( $ctx['keyword'] ) && preg_match( '/<p[^>]*>(.*?)<\/p>/is', $ctx['content'], $m ) ) {
                        $in_first = stripos( $m[1], $ctx['keyword'] ) !== false;
                    }
                    $pass = $in_h1 || $in_first;
                    return array(
                        'name'  => __( 'Keyword in H1 / First Para', 'msh-seo' ),
                        'pass'  => $pass,
                        'score' => $pass ? 10 : 0,
                        'tip'   => __( 'Include your focus keyword in the H1 heading and first paragraph.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['meta'] ) && ( empty( $ctx['keyword'] ) || stripos( $ctx['meta'], $ctx['keyword'] ) !== false );
                    return array(
                        'name'  => __( 'Meta Description with Keyword', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 10 : ( ! empty( $ctx['meta'] ) ? 5 : 0 ),
                        'tip'   => __( 'Write a meta description that includes your focus keyword.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/\b(trusted by|as seen|partner|certified|award|logo|badge)\b/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Trust Signals', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 10 : 0,
                        'tip'   => __( 'Add trust signals: client logos, certifications, awards, or "Trusted by" sections.', 'msh-seo' ),
                    );
                },
            ),
        );
    }

    /**
     * Contact page scoring rules.
     *
     * @return array
     */
    private static function contact_page_rules() {
        return array(
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['schema'] ) && in_array( $ctx['schema'], array( 'ContactPage', 'LocalBusiness' ), true );
                    return array(
                        'name'  => __( 'ContactPage Schema', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Set schema type to "ContactPage" or "LocalBusiness".', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<form[^>]*>/i', $ctx['content'] );
                    // Also check for common form shortcodes.
                    if ( ! $has ) {
                        $has = (bool) preg_match( '/\[(contact-form|wpforms|formidable|gravity|ninja_form)/i', $ctx['content'] );
                    }
                    return array(
                        'name'  => __( 'Contact Form', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Add a contact form to the page.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $plain = strtolower( wp_strip_all_tags( $ctx['content'] ) );
                    $has_phone = (bool) preg_match( '/(\+?\d[\d\s\-().]{7,})/', $plain );
                    $has_email = (bool) preg_match( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/', $plain );
                    $pass = $has_phone || $has_email;
                    return array(
                        'name'  => __( 'Phone / Email Visible', 'msh-seo' ),
                        'pass'  => $pass,
                        'score' => $pass ? 15 : 0,
                        'tip'   => __( 'Display a phone number or email address visibly on the contact page.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/\b(address|street|suite|city|state|zip|map|directions|located)\b/i', $ctx['content'] );
                    // Check for embedded maps.
                    if ( ! $has ) {
                        $has = (bool) preg_match( '/(google\.com\/maps|maps\.googleapis|<iframe[^>]*map)/i', $ctx['content'] );
                    }
                    return array(
                        'name'  => __( 'Address / Map', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Add your physical address or an embedded map.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['schema'] ) && 'LocalBusiness' === $ctx['schema'];
                    return array(
                        'name'  => __( 'LocalBusiness Schema', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Add LocalBusiness schema with your business name, address, and phone.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/\b(hours|open|closed|monday|tuesday|wednesday|thursday|friday|saturday|sunday|am|pm|24\/7)\b/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Business Hours', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 10 : 0,
                        'tip'   => __( 'Display your business hours or availability.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<h1[^>]*>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Clear H1 Heading', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Add a clear H1 heading like "Contact Us" or "Get in Touch".', 'msh-seo' ),
                    );
                },
            ),
        );
    }

    /**
     * Pricing page scoring rules.
     *
     * @return array
     */
    private static function pricing_page_rules() {
        return array(
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<h1[^>]*>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Clear H1 Heading', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Add a clear H1 like "Pricing" or "Plans & Pricing".', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/(\$|USD|EUR|GBP|£|€)\s*\d+/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Visible Pricing', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Display clear pricing with currency symbols.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<table[^>]*>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Comparison Table', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Use a comparison table to differentiate plan tiers.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has_cta = (bool) preg_match( '/<(a|button)[^>]*>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'CTA Buttons', 'msh-seo' ),
                        'pass'  => $has_cta,
                        'score' => $has_cta ? 15 : 0,
                        'tip'   => __( 'Add clear CTA buttons for each pricing tier.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['meta'] );
                    return array(
                        'name'  => __( 'Meta Description', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 10 : 0,
                        'tip'   => __( 'Write a meta description mentioning your pricing or value.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<h[2-4][^>]*>[^<]*\?<\/h[2-4]>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'FAQ Section', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Add pricing FAQ to address common billing questions.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['schema'] );
                    return array(
                        'name'  => __( 'Schema Markup', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Add Offer or Product schema with pricing information.', 'msh-seo' ),
                    );
                },
            ),
        );
    }

    /**
     * FAQ page scoring rules.
     *
     * @return array
     */
    private static function faq_page_rules() {
        return array(
            array(
                'max'   => 20,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['schema'] ) && 'FAQPage' === $ctx['schema'];
                    return array(
                        'name'  => __( 'FAQPage Schema', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 20 : 0,
                        'tip'   => __( 'Set the schema type to "FAQPage" for rich FAQ results.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 20,
                'check' => function ( $ctx ) {
                    preg_match_all( '/<h[2-4][^>]*>[^<]*\?<\/h[2-4]>/i', $ctx['content'], $m );
                    $count = count( $m[0] );
                    $pass  = $count >= 5;
                    return array(
                        'name'  => __( 'Q&A Format (5+ Questions)', 'msh-seo' ),
                        'pass'  => $pass,
                        'score' => $pass ? 20 : ( $count >= 3 ? 12 : ( $count >= 1 ? 5 : 0 ) ),
                        /* translators: %d: number of question headings found in the content. */
                        'tip'   => sprintf( __( 'Add at least 5 questions as headings ending with "?". Found: %d.', 'msh-seo' ), $count ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<h1[^>]*>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Clear H1 Heading', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Add a clear H1 like "Frequently Asked Questions".', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['meta'] );
                    return array(
                        'name'  => __( 'Meta Description', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Write a meta description for the FAQ page.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );
                    preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $ctx['content'], $m );
                    $internal = 0;
                    foreach ( $m[1] ?? array() as $url ) {
                        $host = wp_parse_url( $url, PHP_URL_HOST );
                        if ( ! $host || $host === $site_host ) {
                            $internal++;
                        }
                    }
                    $pass = $internal >= 3;
                    return array(
                        'name'  => __( 'Internal Links in Answers', 'msh-seo' ),
                        'pass'  => $pass,
                        'score' => $pass ? 15 : ( $internal >= 1 ? 8 : 0 ),
                        'tip'   => __( 'Link FAQ answers to relevant pages on your site.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    // Check headings are organized in categories.
                    $has_h2 = (bool) preg_match( '/<h2[^>]*>/i', $ctx['content'] );
                    $has_h3 = (bool) preg_match( '/<h3[^>]*>/i', $ctx['content'] );
                    $pass = $has_h2 && $has_h3;
                    return array(
                        'name'  => __( 'Organized by Category', 'msh-seo' ),
                        'pass'  => $pass,
                        'score' => $pass ? 15 : ( $has_h2 ? 8 : 0 ),
                        'tip'   => __( 'Group FAQ questions under H2 category headings with H3 questions.', 'msh-seo' ),
                    );
                },
            ),
        );
    }

    /**
     * About page scoring rules.
     *
     * @return array
     */
    private static function about_page_rules() {
        return array(
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<h1[^>]*>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Clear H1 Heading', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Add a clear H1 heading for the about page.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['schema'] ) && 'AboutPage' === $ctx['schema'];
                    return array(
                        'name'  => __( 'AboutPage Schema', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Set the schema type to "AboutPage".', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['meta'] );
                    return array(
                        'name'  => __( 'Meta Description', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Write a compelling meta description for the about page.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/\b(team|founder|ceo|staff|expert|experience|years)\b/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Team / Expertise Signals', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Mention team members, expertise, or years of experience.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = has_post_thumbnail( $ctx['post_id'] ) || (bool) preg_match( '/<img[^>]+/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Images / Photos', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 10 : 0,
                        'tip'   => __( 'Add team photos or office images to build trust.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/\b(mission|vision|values|believe|committed|dedicated)\b/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Mission / Values', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Include your company mission, vision, or values.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has_cta = (bool) preg_match( '/<(a|button)[^>]*>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'CTA / Next Step', 'msh-seo' ),
                        'pass'  => $has_cta,
                        'score' => $has_cta ? 15 : 0,
                        'tip'   => __( 'Add a call-to-action linking to contact or services page.', 'msh-seo' ),
                    );
                },
            ),
        );
    }

    /**
     * Product page scoring rules (WooCommerce).
     *
     * @return array
     */
    private static function product_rules() {
        return array(
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['schema'] ) && 'Product' === $ctx['schema'];
                    return array(
                        'name'  => __( 'Product Schema', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 5, // WooCommerce usually adds its own.
                        'tip'   => __( 'Ensure Product schema is active (WooCommerce adds this by default).', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $wc = str_word_count( wp_strip_all_tags( $ctx['content'] ) );
                    $pass = $wc >= 100;
                    return array(
                        'name'  => __( 'Product Description', 'msh-seo' ),
                        'pass'  => $pass,
                        'score' => $pass ? 15 : ( $wc >= 50 ? 8 : 0 ),
                        'tip'   => __( 'Write a detailed product description (100+ words).', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = has_post_thumbnail( $ctx['post_id'] );
                    return array(
                        'name'  => __( 'Product Image', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Add a high-quality product image.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['meta'] );
                    return array(
                        'name'  => __( 'Meta Description', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Write a meta description highlighting the product value.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['keyword'] );
                    return array(
                        'name'  => __( 'Focus Keyword Set', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 10 : 0,
                        'tip'   => __( 'Set a focus keyword for the product.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );
                    preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $ctx['content'], $m );
                    $internal = 0;
                    foreach ( $m[1] ?? array() as $url ) {
                        $host = wp_parse_url( $url, PHP_URL_HOST );
                        if ( ! $host || $host === $site_host ) {
                            $internal++;
                        }
                    }
                    $pass = $internal >= 2;
                    return array(
                        'name'  => __( 'Related Product Links', 'msh-seo' ),
                        'pass'  => $pass,
                        'score' => $pass ? 15 : ( $internal >= 1 ? 8 : 0 ),
                        'tip'   => __( 'Link to related products or categories in the description.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<h1[^>]*>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Clear H1 / Product Title', 'msh-seo' ),
                        'pass'  => $has || ! empty( $ctx['title'] ),
                        'score' => 15, // WooCommerce always provides the title.
                        'tip'   => __( 'Ensure the product title is descriptive and keyword-rich.', 'msh-seo' ),
                    );
                },
            ),
        );
    }

    /**
     * Legal page scoring rules.
     *
     * @return array
     */
    private static function legal_page_rules() {
        return array(
            array(
                'max'   => 25,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<h1[^>]*>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Clear H1 Heading', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 25 : 0,
                        'tip'   => __( 'Add a clear H1 heading for the legal page.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 25,
                'check' => function ( $ctx ) {
                    preg_match_all( '/<h[2-3][^>]*>/i', $ctx['content'], $m );
                    $pass = count( $m[0] ) >= 3;
                    return array(
                        'name'  => __( 'Organized Sections', 'msh-seo' ),
                        'pass'  => $pass,
                        'score' => $pass ? 25 : ( count( $m[0] ) >= 1 ? 12 : 0 ),
                        'tip'   => __( 'Organize the legal page into clear sections with headings.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 25,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/\b(last updated|effective date|updated on)\b/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Last Updated Date', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 25 : 0,
                        'tip'   => __( 'Include a "Last Updated" or "Effective Date" at the top.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 25,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['meta'] );
                    return array(
                        'name'  => __( 'Meta Description', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 25 : 0,
                        'tip'   => __( 'Add a meta description for the legal page.', 'msh-seo' ),
                    );
                },
            ),
        );
    }

    /**
     * Blog index page scoring rules.
     *
     * @return array
     */
    private static function blog_index_rules() {
        return array(
            array(
                'max'   => 25,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<h1[^>]*>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Clear H1 Heading', 'msh-seo' ),
                        'pass'  => $has || ! empty( $ctx['title'] ),
                        'score' => ( $has || ! empty( $ctx['title'] ) ) ? 25 : 0,
                        'tip'   => __( 'Add a clear H1 heading for the blog page.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 25,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['meta'] );
                    return array(
                        'name'  => __( 'Meta Description', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 25 : 0,
                        'tip'   => __( 'Write a meta description for the blog index page.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 25,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['schema'] ) && 'CollectionPage' === $ctx['schema'];
                    return array(
                        'name'  => __( 'CollectionPage Schema', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 25 : 0,
                        'tip'   => __( 'Set the schema type to "CollectionPage" for the blog index.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 25,
                'check' => function ( $ctx ) {
                    $has = has_post_thumbnail( $ctx['post_id'] );
                    return array(
                        'name'  => __( 'Featured Image / OG Image', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 25 : 0,
                        'tip'   => __( 'Set a featured image for social sharing of the blog page.', 'msh-seo' ),
                    );
                },
            ),
        );
    }

    /**
     * Standard / generic page scoring rules.
     *
     * @return array
     */
    private static function standard_page_rules() {
        return array(
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = (bool) preg_match( '/<h1[^>]*>/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Clear H1 Heading', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Add a single, clear H1 heading.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['meta'] );
                    return array(
                        'name'  => __( 'Meta Description', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Write a descriptive meta description.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $wc = str_word_count( wp_strip_all_tags( $ctx['content'] ) );
                    $pass = $wc >= 100;
                    return array(
                        'name'  => __( 'Sufficient Content', 'msh-seo' ),
                        'pass'  => $pass,
                        'score' => $pass ? 15 : ( $wc >= 50 ? 8 : 0 ),
                        'tip'   => __( 'Add at least 100 words of content.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = has_post_thumbnail( $ctx['post_id'] ) || (bool) preg_match( '/<img[^>]+/i', $ctx['content'] );
                    return array(
                        'name'  => __( 'Images', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Add at least one relevant image.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 10,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['keyword'] );
                    return array(
                        'name'  => __( 'Focus Keyword Set', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 10 : 0,
                        'tip'   => __( 'Set a focus keyword for this page.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );
                    preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $ctx['content'], $m );
                    $internal = 0;
                    foreach ( $m[1] ?? array() as $url ) {
                        $host = wp_parse_url( $url, PHP_URL_HOST );
                        if ( ! $host || $host === $site_host ) {
                            $internal++;
                        }
                    }
                    $pass = $internal >= 2;
                    return array(
                        'name'  => __( 'Internal Links', 'msh-seo' ),
                        'pass'  => $pass,
                        'score' => $pass ? 15 : ( $internal >= 1 ? 8 : 0 ),
                        'tip'   => __( 'Add internal links to related pages.', 'msh-seo' ),
                    );
                },
            ),
            array(
                'max'   => 15,
                'check' => function ( $ctx ) {
                    $has = ! empty( $ctx['schema'] );
                    return array(
                        'name'  => __( 'Schema Markup', 'msh-seo' ),
                        'pass'  => $has,
                        'score' => $has ? 15 : 0,
                        'tip'   => __( 'Set a schema type for this page.', 'msh-seo' ),
                    );
                },
            ),
        );
    }
}
