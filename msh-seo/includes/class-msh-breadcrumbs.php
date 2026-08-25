<?php
/**
 * MSH Breadcrumbs
 *
 * Outputs accessible breadcrumb navigation with BreadcrumbList JSON-LD schema.
 * Can be used in theme templates via MSH_Breadcrumbs::render() or via
 * the [msh_breadcrumbs] shortcode.
 *
 * @package MSH_SEO
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Breadcrumbs {

    /**
     * Default arguments for breadcrumb rendering.
     *
     * @var array
     */
    private static $defaults = array(
        'separator'    => '&#8250;',
        'home_text'    => '',
        'wrap_class'   => 'msh-breadcrumbs',
        'show_current' => true,
    );

    /**
     * Initialize breadcrumbs functionality.
     *
     * Registers shortcode and outputs inline CSS on the front end.
     *
     * @return void
     */
    public static function init() {
        add_shortcode( 'msh_breadcrumbs', array( __CLASS__, 'shortcode_handler' ) );

        // Output breadcrumb CSS on the front end when needed.
        add_action( 'wp_head', array( __CLASS__, 'output_inline_css' ), 5 );
    }

    /**
     * Output inline CSS for breadcrumb styling.
     *
     * Only outputs on pages that are not the front page.
     *
     * @return void
     */
    public static function output_inline_css() {
        if ( is_front_page() ) {
            return;
        }

        $css = '
.msh-breadcrumbs { font-size: 14px; color: #666; margin-bottom: 16px; }
.msh-breadcrumbs a { color: #0073aa; text-decoration: none; }
.msh-breadcrumbs a:hover { text-decoration: underline; }
.msh-breadcrumbs .separator { margin: 0 8px; color: #999; }
.msh-breadcrumbs .current { color: #333; font-weight: 500; }
';

        echo '<style id="msh-breadcrumbs-css">' . "\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $css;
        echo '</style>' . "\n";
    }

    /**
     * Render breadcrumb navigation with BreadcrumbList schema.
     *
     * Can be called directly in theme templates:
     *   MSH_Breadcrumbs::render();
     *
     * Supports customization via $args:
     *   - separator:    Separator character (default: >)
     *   - home_text:    Home link text (default: "Home")
     *   - wrap_class:   CSS class for the nav wrapper (default: "msh-breadcrumbs")
     *   - show_current: Show current page title (default: true)
     *
     * Outputs both HTML (with accessibility attributes) and JSON-LD schema.
     *
     * @param array $args Optional. Customization arguments.
     * @return string The breadcrumb HTML output.
     */
    public static function render( $args = array() ) {
        $args = wp_parse_args( $args, self::$defaults );

        // Set default home text with translation support.
        if ( empty( $args['home_text'] ) ) {
            $args['home_text'] = __( 'Home', 'msh-seo' );
        }

        $trail = self::get_trail();

        if ( empty( $trail ) || count( $trail ) < 2 ) {
            return '';
        }

        $output = '';

        // Build HTML.
        $output .= '<nav class="' . esc_attr( $args['wrap_class'] ) . '" aria-label="' . esc_attr__( 'breadcrumb', 'msh-seo' ) . '">';

        $last_index = count( $trail ) - 1;
        foreach ( $trail as $index => $item ) {
            $is_last = ( $index === $last_index );

            if ( $index > 0 ) {
                $output .= '<span class="separator" aria-hidden="true">' . $args['separator'] . '</span>';
            }

            if ( $is_last && $args['show_current'] ) {
                $output .= '<span class="current" aria-current="page">';
                $output .= '<span itemscope itemtype="https://schema.org/ListItem" itemprop="itemListElement">';
                $output .= '<span itemprop="name">' . esc_html( $item['title'] ) . '</span>';
                $output .= '<meta itemprop="position" content="' . esc_attr( $item['position'] ) . '" />';
                $output .= '</span>';
                $output .= '</span>';
            } elseif ( $is_last && ! $args['show_current'] ) {
                // Skip current page if show_current is false.
                continue;
            } else {
                $output .= '<span itemscope itemtype="https://schema.org/ListItem" itemprop="itemListElement">';
                $output .= '<a itemprop="item" href="' . esc_url( $item['url'] ) . '">';
                $output .= '<span itemprop="name">' . esc_html( $item['title'] ) . '</span>';
                $output .= '</a>';
                $output .= '<meta itemprop="position" content="' . esc_attr( $item['position'] ) . '" />';
                $output .= '</span>';
            }
        }

        $output .= '</nav>';

        // Build JSON-LD schema.
        $schema_items = array();
        foreach ( $trail as $item ) {
            $schema_item = array(
                '@type'    => 'ListItem',
                'position' => $item['position'],
                'name'     => esc_html( $item['title'] ),
            );

            if ( ! empty( $item['url'] ) ) {
                $schema_item['item'] = esc_url( $item['url'] );
            }

            $schema_items[] = $schema_item;
        }

        $schema = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $schema_items,
        );

        $json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        if ( $json ) {
            $output .= "\n" . '<script type="application/ld+json">';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $output .= $json;
            $output .= '</script>' . "\n";
        }

        return $output;
    }

    /**
     * Handle the [msh_breadcrumbs] shortcode.
     *
     * Parses shortcode attributes and returns rendered breadcrumb output.
     *
     * Supported attributes:
     *   - separator:    Separator character.
     *   - home_text:    Home link text.
     *   - wrap_class:   CSS class for wrapper.
     *   - show_current: "true" or "false".
     *
     * @param array|string $atts Shortcode attributes.
     * @return string The breadcrumb HTML output.
     */
    public static function shortcode_handler( $atts ) {
        $atts = shortcode_atts( array(
            'separator'    => '',
            'home_text'    => '',
            'wrap_class'   => '',
            'show_current' => '',
        ), $atts, 'msh_breadcrumbs' );

        $args = array();

        if ( ! empty( $atts['separator'] ) ) {
            $args['separator'] = $atts['separator'];
        }
        if ( ! empty( $atts['home_text'] ) ) {
            $args['home_text'] = sanitize_text_field( $atts['home_text'] );
        }
        if ( ! empty( $atts['wrap_class'] ) ) {
            $args['wrap_class'] = sanitize_html_class( $atts['wrap_class'] );
        }
        if ( '' !== $atts['show_current'] ) {
            $args['show_current'] = filter_var( $atts['show_current'], FILTER_VALIDATE_BOOLEAN );
        }

        return self::render( $args );
    }

    /**
     * Get the breadcrumb trail as an array for programmatic use.
     *
     * Each trail item is an associative array:
     *   - title:    (string) The breadcrumb label.
     *   - url:      (string) The URL (empty for the current/last item).
     *   - position: (int) The 1-based position in the trail.
     *
     * @return array Array of breadcrumb items.
     */
    public static function get_trail() {
        $trail    = array();
        $position = 1;

        // Always start with Home.
        $trail[] = array(
            'title'    => __( 'Home', 'msh-seo' ),
            'url'      => home_url( '/' ),
            'position' => $position++,
        );

        if ( is_front_page() || is_home() && is_front_page() ) {
            // On the homepage, just return Home.
            return $trail;
        }

        if ( is_singular() ) {
            $post = get_queried_object();

            if ( ! $post instanceof WP_Post ) {
                return $trail;
            }

            // WooCommerce products.
            if ( 'product' === $post->post_type && taxonomy_exists( 'product_cat' ) ) {
                $terms = get_the_terms( $post->ID, 'product_cat' );
                if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                    $term = $terms[0];
                    // Add parent categories.
                    $ancestors = get_ancestors( $term->term_id, 'product_cat' );
                    $ancestors = array_reverse( $ancestors );
                    foreach ( $ancestors as $ancestor_id ) {
                        $ancestor = get_term( $ancestor_id, 'product_cat' );
                        if ( $ancestor && ! is_wp_error( $ancestor ) ) {
                            $trail[] = array(
                                'title'    => $ancestor->name,
                                'url'      => get_term_link( $ancestor ),
                                'position' => $position++,
                            );
                        }
                    }
                    $trail[] = array(
                        'title'    => $term->name,
                        'url'      => get_term_link( $term ),
                        'position' => $position++,
                    );
                }
            }
            // Posts — add primary category.
            elseif ( 'post' === $post->post_type ) {
                $categories = get_the_category( $post->ID );
                if ( ! empty( $categories ) ) {
                    $cat = $categories[0];
                    // Add parent categories first.
                    $ancestors = get_ancestors( $cat->term_id, 'category' );
                    $ancestors = array_reverse( $ancestors );
                    foreach ( $ancestors as $ancestor_id ) {
                        $ancestor_cat = get_category( $ancestor_id );
                        if ( $ancestor_cat && ! is_wp_error( $ancestor_cat ) ) {
                            $trail[] = array(
                                'title'    => $ancestor_cat->name,
                                'url'      => get_category_link( $ancestor_cat->term_id ),
                                'position' => $position++,
                            );
                        }
                    }
                    $trail[] = array(
                        'title'    => $cat->name,
                        'url'      => get_category_link( $cat->term_id ),
                        'position' => $position++,
                    );
                }
            }
            // Hierarchical pages — add parent pages.
            elseif ( is_post_type_hierarchical( $post->post_type ) && $post->post_parent ) {
                $parent_ids = get_post_ancestors( $post->ID );
                $parent_ids = array_reverse( $parent_ids );
                foreach ( $parent_ids as $parent_id ) {
                    $trail[] = array(
                        'title'    => get_the_title( $parent_id ),
                        'url'      => get_permalink( $parent_id ),
                        'position' => $position++,
                    );
                }
            }

            // Current page (no URL for the last item).
            $trail[] = array(
                'title'    => get_the_title( $post ),
                'url'      => '',
                'position' => $position,
            );

        } elseif ( is_category() ) {
            $term = get_queried_object();
            if ( $term instanceof WP_Term ) {
                // Add parent categories.
                $ancestors = get_ancestors( $term->term_id, 'category' );
                $ancestors = array_reverse( $ancestors );
                foreach ( $ancestors as $ancestor_id ) {
                    $ancestor_cat = get_category( $ancestor_id );
                    if ( $ancestor_cat && ! is_wp_error( $ancestor_cat ) ) {
                        $trail[] = array(
                            'title'    => $ancestor_cat->name,
                            'url'      => get_category_link( $ancestor_cat->term_id ),
                            'position' => $position++,
                        );
                    }
                }
                $trail[] = array(
                    'title'    => $term->name,
                    'url'      => '',
                    'position' => $position,
                );
            }

        } elseif ( is_tag() ) {
            $term = get_queried_object();
            if ( $term instanceof WP_Term ) {
                $trail[] = array(
                    'title'    => __( 'Tags', 'msh-seo' ),
                    'url'      => '',
                    'position' => $position++,
                );
                $trail[] = array(
                    'title'    => $term->name,
                    'url'      => '',
                    'position' => $position,
                );
            }

        } elseif ( is_tax() ) {
            $term = get_queried_object();
            if ( $term instanceof WP_Term ) {
                $taxonomy = get_taxonomy( $term->taxonomy );
                if ( $taxonomy ) {
                    // Add parent terms.
                    $ancestors = get_ancestors( $term->term_id, $term->taxonomy );
                    $ancestors = array_reverse( $ancestors );
                    foreach ( $ancestors as $ancestor_id ) {
                        $ancestor = get_term( $ancestor_id, $term->taxonomy );
                        if ( $ancestor && ! is_wp_error( $ancestor ) ) {
                            $trail[] = array(
                                'title'    => $ancestor->name,
                                'url'      => get_term_link( $ancestor ),
                                'position' => $position++,
                            );
                        }
                    }
                }
                $trail[] = array(
                    'title'    => $term->name,
                    'url'      => '',
                    'position' => $position,
                );
            }

        } elseif ( is_post_type_archive() ) {
            $post_type = get_queried_object();
            if ( $post_type ) {
                $label = is_object( $post_type ) && isset( $post_type->label )
                    ? $post_type->label
                    : post_type_archive_title( '', false );
                $trail[] = array(
                    'title'    => $label,
                    'url'      => '',
                    'position' => $position,
                );
            }

        } elseif ( is_date() ) {
            if ( is_year() ) {
                $trail[] = array(
                    'title'    => get_the_date( 'Y' ),
                    'url'      => '',
                    'position' => $position,
                );
            } elseif ( is_month() ) {
                $trail[] = array(
                    'title'    => get_the_date( 'F Y' ),
                    'url'      => '',
                    'position' => $position,
                );
            } elseif ( is_day() ) {
                $trail[] = array(
                    'title'    => get_the_date(),
                    'url'      => '',
                    'position' => $position,
                );
            }

        } elseif ( is_author() ) {
            $author = get_queried_object();
            if ( $author ) {
                $trail[] = array(
                    'title'    => $author->display_name,
                    'url'      => '',
                    'position' => $position,
                );
            }

        } elseif ( is_search() ) {
            $trail[] = array(
                'title'    => __( 'Search Results', 'msh-seo' ),
                'url'      => '',
                'position' => $position,
            );

        } elseif ( is_404() ) {
            $trail[] = array(
                'title'    => __( 'Not Found', 'msh-seo' ),
                'url'      => '',
                'position' => $position,
            );
        }

        return $trail;
    }
}
