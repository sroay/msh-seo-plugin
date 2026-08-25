<?php
/**
 * MSH WooCommerce SEO Module
 *
 * Adds WooCommerce-specific SEO features including Product schema,
 * product SEO scoring, custom product fields (GTIN, MPN, Brand),
 * and product-specific Open Graph tags.
 *
 * Only loads when WooCommerce is active.
 *
 * @package MSH_SEO
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_WooCommerce {

    /**
     * Initialize WooCommerce SEO hooks.
     *
     * Only hooks if WooCommerce is active. Should be called on plugins_loaded
     * or init so that WooCommerce has had a chance to load.
     *
     * @return void
     */
    public static function init() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Inject Product schema on single product pages.
        add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'filter_product_schema' ), 10, 2 );

        // Add custom fields to the WooCommerce product Inventory tab.
        add_action( 'woocommerce_product_options_sku', array( __CLASS__, 'add_product_data_fields' ) );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_data_fields' ) );

        // Output our own Product JSON-LD on single product pages.
        add_action( 'wp_head', array( __CLASS__, 'output_product_schema' ), 3 );
    }

    /**
     * Output Product JSON-LD schema on single product pages.
     *
     * @return void
     */
    public static function output_product_schema() {
        if ( ! is_product() ) {
            return;
        }

        global $post;
        $schema = self::get_product_schema( $post->ID );

        if ( empty( $schema ) ) {
            return;
        }

        $output = array(
            '@context' => 'https://schema.org',
        );

        $output = array_merge( $output, $schema );

        $json = wp_json_encode( $output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

        if ( ! $json ) {
            return;
        }

        echo "\n<!-- MSH SEO: Product Schema -->\n";
        echo '<script type="application/ld+json">' . "\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $json;
        echo "\n</script>\n";
    }

    /**
     * Filter WooCommerce's own structured data output for products.
     *
     * @param array       $markup  The existing product markup.
     * @param WC_Product  $product The WooCommerce product object.
     * @return array Modified markup.
     */
    public static function filter_product_schema( $markup, $product ) {
        // Add GTIN and MPN if available.
        $gtin = get_post_meta( $product->get_id(), '_msh_product_gtin', true );
        $mpn  = get_post_meta( $product->get_id(), '_msh_product_mpn', true );

        if ( ! empty( $gtin ) ) {
            $markup['gtin'] = sanitize_text_field( $gtin );
        }
        if ( ! empty( $mpn ) ) {
            $markup['mpn'] = sanitize_text_field( $mpn );
        }

        return $markup;
    }

    /**
     * Generate complete Product JSON-LD schema for a WooCommerce product.
     *
     * Auto-populates from WooCommerce product data including name, description,
     * SKU, brand, images, offers, ratings, and reviews.
     *
     * @param int $product_id The WooCommerce product (post) ID.
     * @return array The Product schema array.
     */
    public static function get_product_schema( $product_id ) {
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return array();
        }

        // Handle variable products as ProductGroup.
        if ( $product->is_type( 'variable' ) ) {
            return self::get_variable_product_schema( $product );
        }

        $schema = array(
            '@type' => 'Product',
            '@id'   => esc_url( get_permalink( $product_id ) ) . '#product',
            'name'  => esc_html( $product->get_name() ),
            'url'   => esc_url( get_permalink( $product_id ) ),
        );

        // Description.
        $description = $product->get_description();
        if ( ! empty( $description ) ) {
            $schema['description'] = esc_html( wp_strip_all_tags( $description ) );
        }

        // SKU.
        $sku = $product->get_sku();
        if ( ! empty( $sku ) ) {
            $schema['sku'] = esc_html( $sku );
        }

        // Brand — from taxonomy 'product_brand' or custom meta.
        $brand = self::get_product_brand( $product_id );
        if ( ! empty( $brand ) ) {
            $schema['brand'] = array(
                '@type' => 'Brand',
                'name'  => esc_html( $brand ),
            );
        }

        // Images — product gallery.
        $images = self::get_product_images( $product );
        if ( ! empty( $images ) ) {
            $schema['image'] = $images;
        }

        // GTIN and MPN from custom meta.
        $gtin = get_post_meta( $product_id, '_msh_product_gtin', true );
        $mpn  = get_post_meta( $product_id, '_msh_product_mpn', true );

        if ( ! empty( $gtin ) ) {
            $schema['gtin'] = sanitize_text_field( $gtin );
        }
        if ( ! empty( $mpn ) ) {
            $schema['mpn'] = sanitize_text_field( $mpn );
        }

        // Offers.
        $schema['offers'] = self::get_product_offers( $product );

        // Item condition.
        $schema['offers']['itemCondition'] = 'https://schema.org/NewCondition';

        // Aggregate rating.
        $rating_count = $product->get_rating_count();
        $average      = $product->get_average_rating();

        if ( $rating_count > 0 && $average > 0 ) {
            $schema['aggregateRating'] = array(
                '@type'       => 'AggregateRating',
                'ratingValue' => floatval( $average ),
                'reviewCount' => intval( $rating_count ),
                'bestRating'  => 5,
                'worstRating' => 1,
            );
        }

        // Reviews — last 10.
        $reviews = self::get_product_reviews( $product_id, 10 );
        if ( ! empty( $reviews ) ) {
            $schema['review'] = $reviews;
        }

        return $schema;
    }

    /**
     * Generate ProductGroup schema for variable products with hasVariant array.
     *
     * @param WC_Product_Variable $product The variable product object.
     * @return array The ProductGroup schema array.
     */
    private static function get_variable_product_schema( $product ) {
        $product_id = $product->get_id();

        $schema = array(
            '@type'      => 'ProductGroup',
            '@id'        => esc_url( get_permalink( $product_id ) ) . '#product',
            'name'       => esc_html( $product->get_name() ),
            'url'        => esc_url( get_permalink( $product_id ) ),
            'hasVariant' => array(),
        );

        $description = $product->get_description();
        if ( ! empty( $description ) ) {
            $schema['description'] = esc_html( wp_strip_all_tags( $description ) );
        }

        $sku = $product->get_sku();
        if ( ! empty( $sku ) ) {
            $schema['sku'] = esc_html( $sku );
        }

        $brand = self::get_product_brand( $product_id );
        if ( ! empty( $brand ) ) {
            $schema['brand'] = array(
                '@type' => 'Brand',
                'name'  => esc_html( $brand ),
            );
        }

        $images = self::get_product_images( $product );
        if ( ! empty( $images ) ) {
            $schema['image'] = $images;
        }

        // Build hasVariant array from available variations.
        $variations = $product->get_available_variations();
        foreach ( $variations as $variation_data ) {
            $variation = wc_get_product( $variation_data['variation_id'] );
            if ( ! $variation ) {
                continue;
            }

            $variant = array(
                '@type' => 'Product',
                'name'  => esc_html( $variation->get_name() ),
                'sku'   => esc_html( $variation->get_sku() ),
            );

            $variant['offers'] = self::get_product_offers( $variation );
            $variant['offers']['itemCondition'] = 'https://schema.org/NewCondition';

            $variant_image = $variation->get_image_id();
            if ( $variant_image ) {
                $url = wp_get_attachment_image_url( $variant_image, 'full' );
                if ( $url ) {
                    $variant['image'] = esc_url( $url );
                }
            }

            $schema['hasVariant'][] = $variant;
        }

        // Aggregate rating on the parent.
        $rating_count = $product->get_rating_count();
        $average      = $product->get_average_rating();

        if ( $rating_count > 0 && $average > 0 ) {
            $schema['aggregateRating'] = array(
                '@type'       => 'AggregateRating',
                'ratingValue' => floatval( $average ),
                'reviewCount' => intval( $rating_count ),
                'bestRating'  => 5,
                'worstRating' => 1,
            );
        }

        $reviews = self::get_product_reviews( $product_id, 10 );
        if ( ! empty( $reviews ) ) {
            $schema['review'] = $reviews;
        }

        return $schema;
    }

    /**
     * Get product offers schema.
     *
     * @param WC_Product $product The product object.
     * @return array Offer schema array.
     */
    private static function get_product_offers( $product ) {
        $offer = array(
            '@type'         => 'Offer',
            'url'           => esc_url( get_permalink( $product->get_id() ) ),
            'priceCurrency' => esc_html( get_woocommerce_currency() ),
            'price'         => esc_html( $product->get_price() ),
        );

        // Map WooCommerce stock status to schema.org availability.
        $stock_status = $product->get_stock_status();
        $availability_map = array(
            'instock'     => 'https://schema.org/InStock',
            'outofstock'  => 'https://schema.org/OutOfStock',
            'onbackorder' => 'https://schema.org/PreOrder',
        );

        $offer['availability'] = isset( $availability_map[ $stock_status ] )
            ? $availability_map[ $stock_status ]
            : 'https://schema.org/InStock';

        // Sale price dates.
        $sale_start = $product->get_date_on_sale_from();
        $sale_end   = $product->get_date_on_sale_to();

        if ( $sale_start ) {
            $offer['priceValidFrom'] = $sale_start->date( 'c' );
        }
        if ( $sale_end ) {
            $offer['priceValidThrough'] = $sale_end->date( 'c' );
        }

        return $offer;
    }

    /**
     * Get product images as an array of URLs.
     *
     * @param WC_Product $product The product object.
     * @return array Array of image URL strings.
     */
    private static function get_product_images( $product ) {
        $images = array();

        // Featured image.
        $featured_id = $product->get_image_id();
        if ( $featured_id ) {
            $url = wp_get_attachment_image_url( $featured_id, 'full' );
            if ( $url ) {
                $images[] = esc_url( $url );
            }
        }

        // Gallery images.
        $gallery_ids = $product->get_gallery_image_ids();
        foreach ( $gallery_ids as $gallery_id ) {
            $url = wp_get_attachment_image_url( $gallery_id, 'full' );
            if ( $url ) {
                $images[] = esc_url( $url );
            }
        }

        return $images;
    }

    /**
     * Get the product brand from taxonomy or custom field.
     *
     * Checks for 'product_brand' taxonomy first, then '_msh_product_brand' meta.
     *
     * @param int $product_id The product ID.
     * @return string Brand name or empty string.
     */
    private static function get_product_brand( $product_id ) {
        // Check taxonomy first (commonly used by WooCommerce Brands extension).
        if ( taxonomy_exists( 'product_brand' ) ) {
            $brands = get_the_terms( $product_id, 'product_brand' );
            if ( ! empty( $brands ) && ! is_wp_error( $brands ) ) {
                return $brands[0]->name;
            }
        }

        // Fall back to custom meta.
        $brand = get_post_meta( $product_id, '_msh_product_brand', true );
        return ! empty( $brand ) ? $brand : '';
    }

    /**
     * Get product reviews formatted as schema.org Review objects.
     *
     * @param int $product_id The product ID.
     * @param int $limit      Maximum number of reviews to return.
     * @return array Array of Review schema arrays.
     */
    private static function get_product_reviews( $product_id, $limit = 10 ) {
        $reviews_data = array();

        $comments = get_comments( array(
            'post_id' => $product_id,
            'status'  => 'approve',
            'type'    => 'review',
            'number'  => $limit,
            'orderby' => 'comment_date',
            'order'   => 'DESC',
        ) );

        foreach ( $comments as $comment ) {
            $rating = intval( get_comment_meta( $comment->comment_ID, 'rating', true ) );

            $review = array(
                '@type'       => 'Review',
                'author'      => array(
                    '@type' => 'Person',
                    'name'  => esc_html( $comment->comment_author ),
                ),
                'datePublished' => get_comment_date( 'c', $comment ),
                'reviewBody'    => esc_html( $comment->comment_content ),
            );

            if ( $rating > 0 ) {
                $review['reviewRating'] = array(
                    '@type'       => 'Rating',
                    'ratingValue' => $rating,
                    'bestRating'  => 5,
                    'worstRating' => 1,
                );
            }

            $reviews_data[] = $review;
        }

        return $reviews_data;
    }

    /**
     * Product-specific SEO scoring with different checks than blog posts.
     *
     * Evaluates product completeness, media quality, keyword usage,
     * and meta description quality.
     *
     * @param int $product_id The WooCommerce product (post) ID.
     * @return array { total_score: int, max_score: int, checks: array }
     */
    public static function analyze_product( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return array(
                'total_score' => 0,
                'max_score'   => 100,
                'checks'      => array(),
            );
        }

        $checks      = array();
        $total_score  = 0;
        $focus_keyword = get_post_meta( $product_id, '_msh_focus_keyword', true );

        // 1. Product title length (30-70 chars optimal) — 10pts.
        $title        = $product->get_name();
        $title_length = mb_strlen( $title );
        $title_pass   = $title_length >= 30 && $title_length <= 70;
        $checks[]     = array(
            'name'    => 'title_length',
            'pass'    => $title_pass,
            'score'   => $title_pass ? 10 : 0,
            'max'     => 10,
            'message' => $title_pass
                ? __( 'Product title is an optimal length.', 'msh-seo' )
                : sprintf(
                    /* translators: %d: character count */
                    __( 'Product title is %d characters. Aim for 30-70 characters.', 'msh-seo' ),
                    $title_length
                ),
        );
        $total_score += $title_pass ? 10 : 0;

        // 2. Product has description (>100 words) — 10pts.
        $description = $product->get_description();
        $word_count  = str_word_count( wp_strip_all_tags( $description ) );
        $desc_pass   = $word_count > 100;
        $checks[]    = array(
            'name'    => 'description_length',
            'pass'    => $desc_pass,
            'score'   => $desc_pass ? 10 : 0,
            'max'     => 10,
            'message' => $desc_pass
                ? __( 'Product description has sufficient length.', 'msh-seo' )
                : sprintf(
                    /* translators: %d: word count */
                    __( 'Product description is %d words. Aim for 100+ words.', 'msh-seo' ),
                    $word_count
                ),
        );
        $total_score += $desc_pass ? 10 : 0;

        // 3. Product has short description — 8pts.
        $short_desc      = $product->get_short_description();
        $short_desc_pass = ! empty( trim( wp_strip_all_tags( $short_desc ) ) );
        $checks[]        = array(
            'name'    => 'short_description',
            'pass'    => $short_desc_pass,
            'score'   => $short_desc_pass ? 8 : 0,
            'max'     => 8,
            'message' => $short_desc_pass
                ? __( 'Product has a short description.', 'msh-seo' )
                : __( 'Add a short description to improve SEO and product page display.', 'msh-seo' ),
        );
        $total_score += $short_desc_pass ? 8 : 0;

        // 4. Product has SKU — 5pts.
        $sku      = $product->get_sku();
        $sku_pass = ! empty( $sku );
        $checks[] = array(
            'name'    => 'sku',
            'pass'    => $sku_pass,
            'score'   => $sku_pass ? 5 : 0,
            'max'     => 5,
            'message' => $sku_pass
                ? __( 'Product has a SKU.', 'msh-seo' )
                : __( 'Add a SKU for better product identification in search.', 'msh-seo' ),
        );
        $total_score += $sku_pass ? 5 : 0;

        // 5. Product has at least 3 images — 10pts.
        $image_ids  = array();
        $featured   = $product->get_image_id();
        if ( $featured ) {
            $image_ids[] = $featured;
        }
        $gallery    = $product->get_gallery_image_ids();
        $image_ids  = array_merge( $image_ids, $gallery );
        $images_pass = count( $image_ids ) >= 3;
        $checks[]    = array(
            'name'    => 'images_count',
            'pass'    => $images_pass,
            'score'   => $images_pass ? 10 : 0,
            'max'     => 10,
            'message' => $images_pass
                ? sprintf(
                    /* translators: %d: image count */
                    __( 'Product has %d images.', 'msh-seo' ),
                    count( $image_ids )
                )
                : sprintf(
                    /* translators: %d: image count */
                    __( 'Product has %d images. Add at least 3 for better SEO.', 'msh-seo' ),
                    count( $image_ids )
                ),
        );
        $total_score += $images_pass ? 10 : 0;

        // 6. All images have alt text — 8pts.
        $all_have_alt = true;
        $missing_alt  = 0;
        foreach ( $image_ids as $img_id ) {
            $alt = get_post_meta( $img_id, '_wp_attachment_image_alt', true );
            if ( empty( $alt ) ) {
                $all_have_alt = false;
                $missing_alt++;
            }
        }
        $alt_pass = ! empty( $image_ids ) && $all_have_alt;
        $checks[] = array(
            'name'    => 'images_alt_text',
            'pass'    => $alt_pass,
            'score'   => $alt_pass ? 8 : 0,
            'max'     => 8,
            'message' => $alt_pass
                ? __( 'All product images have alt text.', 'msh-seo' )
                : sprintf(
                    /* translators: %d: number of images missing alt */
                    __( '%d product image(s) missing alt text.', 'msh-seo' ),
                    $missing_alt
                ),
        );
        $total_score += $alt_pass ? 8 : 0;

        // 7. Product has price — 5pts.
        $price      = $product->get_price();
        $price_pass = ! empty( $price ) && floatval( $price ) > 0;
        $checks[]   = array(
            'name'    => 'price',
            'pass'    => $price_pass,
            'score'   => $price_pass ? 5 : 0,
            'max'     => 5,
            'message' => $price_pass
                ? __( 'Product has a price set.', 'msh-seo' )
                : __( 'Set a product price for rich results in search.', 'msh-seo' ),
        );
        $total_score += $price_pass ? 5 : 0;

        // 8. Product has categories — 5pts.
        $categories  = get_the_terms( $product_id, 'product_cat' );
        $cat_pass    = ! empty( $categories ) && ! is_wp_error( $categories );
        $checks[]    = array(
            'name'    => 'categories',
            'pass'    => $cat_pass,
            'score'   => $cat_pass ? 5 : 0,
            'max'     => 5,
            'message' => $cat_pass
                ? __( 'Product is assigned to categories.', 'msh-seo' )
                : __( 'Assign product categories to improve navigation and SEO.', 'msh-seo' ),
        );
        $total_score += $cat_pass ? 5 : 0;

        // 9. Product has tags — 3pts.
        $tags      = get_the_terms( $product_id, 'product_tag' );
        $tags_pass = ! empty( $tags ) && ! is_wp_error( $tags );
        $checks[]  = array(
            'name'    => 'tags',
            'pass'    => $tags_pass,
            'score'   => $tags_pass ? 3 : 0,
            'max'     => 3,
            'message' => $tags_pass
                ? __( 'Product has tags.', 'msh-seo' )
                : __( 'Add product tags for better discoverability.', 'msh-seo' ),
        );
        $total_score += $tags_pass ? 3 : 0;

        // 10. Product has reviews — 8pts.
        $review_count = $product->get_review_count();
        $reviews_pass = $review_count > 0;
        $checks[]     = array(
            'name'    => 'reviews',
            'pass'    => $reviews_pass,
            'score'   => $reviews_pass ? 8 : 0,
            'max'     => 8,
            'message' => $reviews_pass
                ? sprintf(
                    /* translators: %d: review count */
                    __( 'Product has %d review(s).', 'msh-seo' ),
                    $review_count
                )
                : __( 'Encourage customers to leave reviews for better SEO.', 'msh-seo' ),
        );
        $total_score += $reviews_pass ? 8 : 0;

        // 11. Focus keyword in title — 10pts.
        $kw_title_pass = false;
        if ( ! empty( $focus_keyword ) ) {
            $kw_title_pass = stripos( $title, $focus_keyword ) !== false;
        }
        $checks[] = array(
            'name'    => 'keyword_in_title',
            'pass'    => $kw_title_pass,
            'score'   => $kw_title_pass ? 10 : 0,
            'max'     => 10,
            'message' => $kw_title_pass
                ? __( 'Focus keyword found in product title.', 'msh-seo' )
                : __( 'Add the focus keyword to the product title.', 'msh-seo' ),
        );
        $total_score += $kw_title_pass ? 10 : 0;

        // 12. Focus keyword in description — 8pts.
        $kw_desc_pass = false;
        if ( ! empty( $focus_keyword ) && ! empty( $description ) ) {
            $kw_desc_pass = stripos( wp_strip_all_tags( $description ), $focus_keyword ) !== false;
        }
        $checks[] = array(
            'name'    => 'keyword_in_description',
            'pass'    => $kw_desc_pass,
            'score'   => $kw_desc_pass ? 8 : 0,
            'max'     => 8,
            'message' => $kw_desc_pass
                ? __( 'Focus keyword found in product description.', 'msh-seo' )
                : __( 'Include the focus keyword in the product description.', 'msh-seo' ),
        );
        $total_score += $kw_desc_pass ? 8 : 0;

        // 13. Meta description set and 150-160 chars — 5pts.
        $meta_desc     = get_post_meta( $product_id, '_msh_seo_description', true );
        $meta_len      = mb_strlen( $meta_desc );
        $meta_desc_pass = ! empty( $meta_desc ) && $meta_len >= 150 && $meta_len <= 160;
        $checks[]       = array(
            'name'    => 'meta_description',
            'pass'    => $meta_desc_pass,
            'score'   => $meta_desc_pass ? 5 : 0,
            'max'     => 5,
            'message' => $meta_desc_pass
                ? __( 'Meta description is set and optimal length.', 'msh-seo' )
                : ( empty( $meta_desc )
                    ? __( 'Set a meta description for the product.', 'msh-seo' )
                    : sprintf(
                        /* translators: %d: character count */
                        __( 'Meta description is %d characters. Aim for 150-160.', 'msh-seo' ),
                        $meta_len
                    ) ),
        );
        $total_score += $meta_desc_pass ? 5 : 0;

        // 14. SEO title set and 50-60 chars — 5pts.
        $seo_title     = get_post_meta( $product_id, '_msh_seo_title', true );
        $seo_title_len = mb_strlen( $seo_title );
        $seo_title_pass = ! empty( $seo_title ) && $seo_title_len >= 50 && $seo_title_len <= 60;
        $checks[]       = array(
            'name'    => 'seo_title',
            'pass'    => $seo_title_pass,
            'score'   => $seo_title_pass ? 5 : 0,
            'max'     => 5,
            'message' => $seo_title_pass
                ? __( 'SEO title is set and optimal length.', 'msh-seo' )
                : ( empty( $seo_title )
                    ? __( 'Set an SEO title for the product.', 'msh-seo' )
                    : sprintf(
                        /* translators: %d: character count */
                        __( 'SEO title is %d characters. Aim for 50-60.', 'msh-seo' ),
                        $seo_title_len
                    ) ),
        );
        $total_score += $seo_title_pass ? 5 : 0;

        return array(
            'total_score' => $total_score,
            'max_score'   => 100,
            'checks'      => $checks,
        );
    }

    /**
     * Add custom fields (GTIN, MPN, Brand) to the WooCommerce product Inventory tab.
     *
     * Hooked to woocommerce_product_options_sku.
     *
     * @return void
     */
    public static function add_product_data_fields() {
        echo '<div class="options_group msh-product-fields">';

        woocommerce_wp_text_input( array(
            'id'          => '_msh_product_gtin',
            'label'       => __( 'GTIN (UPC/EAN/ISBN)', 'msh-seo' ),
            'desc_tip'    => true,
            'description' => __( 'Global Trade Item Number for product identification in search results.', 'msh-seo' ),
            'placeholder' => __( 'e.g. 012345678901', 'msh-seo' ),
        ) );

        woocommerce_wp_text_input( array(
            'id'          => '_msh_product_mpn',
            'label'       => __( 'MPN', 'msh-seo' ),
            'desc_tip'    => true,
            'description' => __( 'Manufacturer Part Number for product identification.', 'msh-seo' ),
            'placeholder' => __( 'e.g. ABC-12345', 'msh-seo' ),
        ) );

        woocommerce_wp_text_input( array(
            'id'          => '_msh_product_brand',
            'label'       => __( 'Brand', 'msh-seo' ),
            'desc_tip'    => true,
            'description' => __( 'Product brand name. Used in schema markup if no brand taxonomy is set.', 'msh-seo' ),
            'placeholder' => __( 'e.g. Acme Corp', 'msh-seo' ),
        ) );

        echo '</div>';
    }

    /**
     * Save custom product data fields on product save.
     *
     * Hooked to woocommerce_process_product_meta.
     *
     * @param int $product_id The product (post) ID.
     * @return void
     */
    public static function save_product_data_fields( $product_id ) {
        $fields = array( '_msh_product_gtin', '_msh_product_mpn', '_msh_product_brand' );

        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                update_post_meta(
                    $product_id,
                    $field,
                    sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
                );
            }
        }
    }

    /**
     * Get product-specific Open Graph tags.
     *
     * Returns og:type=product and product price/availability meta.
     *
     * @param int $product_id The WooCommerce product (post) ID.
     * @return array Associative array of OG property => value pairs.
     */
    public static function get_product_og_tags( $product_id ) {
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return array();
        }

        $tags = array(
            'og:type' => 'product',
        );

        $price = $product->get_price();
        if ( ! empty( $price ) ) {
            $tags['product:price:amount']   = esc_attr( $price );
            $tags['product:price:currency'] = esc_attr( get_woocommerce_currency() );
        }

        $stock_status = $product->get_stock_status();
        $og_availability_map = array(
            'instock'     => 'instock',
            'outofstock'  => 'oos',
            'onbackorder' => 'pending',
        );

        $tags['product:availability'] = isset( $og_availability_map[ $stock_status ] )
            ? $og_availability_map[ $stock_status ]
            : 'instock';

        return $tags;
    }
}
