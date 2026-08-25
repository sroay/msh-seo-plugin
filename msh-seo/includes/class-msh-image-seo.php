<?php
/**
 * MSH Image SEO Automation
 *
 * Automatically optimizes images for SEO by renaming uploaded files,
 * setting alt text, title and caption from context, and fixing missing
 * alt text in post content output.
 *
 * @package MSH_SEO
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Image_SEO {

    /**
     * Initialize Image SEO hooks.
     *
     * @return void
     */
    public static function init() {
        // Auto-rename uploaded files before they hit the filesystem.
        add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'rename_uploaded_file' ) );

        // Auto-set alt text, title, caption on new image uploads.
        add_action( 'add_attachment', array( __CLASS__, 'auto_set_image_meta' ) );

        // Fix missing alt text and add lazy loading in content output.
        add_filter( 'the_content', array( __CLASS__, 'fix_missing_alt_text' ), 99 );
    }

    /**
     * Auto-rename uploaded image files to SEO-friendly names.
     *
     * Transforms generic camera filenames like "IMG_20260331_1234.jpg"
     * into meaningful names based on the current post context.
     *
     * @param array $file The file data array from wp_handle_upload_prefilter.
     * @return array Modified file data array.
     */
    public static function rename_uploaded_file( $file ) {
        // Only process image files.
        if ( strpos( $file['type'], 'image/' ) !== 0 ) {
            return $file;
        }

        $original_name = $file['name'];
        $extension     = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
        $name_only     = pathinfo( $original_name, PATHINFO_FILENAME );

        // Check if filename matches common camera/phone patterns.
        $generic_patterns = array(
            '/^IMG[_-]?\d+/i',
            '/^DSC[_-]?\d+/i',
            '/^DSCN?\d+/i',
            '/^P\d{7,}/i',
            '/^Screenshot[_\s-]?\d+/i',
            '/^Screen\s?Shot\s/i',
            '/^Photo[_\s-]?\d+/i',
            '/^image[_\s-]?\d+/i',
            '/^\d{8}[_-]\d+/i',
        );

        $is_generic = false;
        foreach ( $generic_patterns as $pattern ) {
            if ( preg_match( $pattern, $name_only ) ) {
                $is_generic = true;
                break;
            }
        }

        if ( ! $is_generic ) {
            // Already has a meaningful name; just clean it up.
            $file['name'] = self::sanitize_filename( $name_only ) . '.' . $extension;
            return $file;
        }

        // Try to get a meaningful name from the current post context.
        $base_name = self::get_context_name();

        if ( empty( $base_name ) ) {
            // Fallback: clean the original name.
            $file['name'] = self::sanitize_filename( $name_only ) . '.' . $extension;
            return $file;
        }

        // Append a unique suffix to avoid collisions.
        $suffix         = substr( md5( $original_name . microtime() ), 0, 4 );
        $file['name']   = self::sanitize_filename( $base_name ) . '-' . $suffix . '.' . $extension;

        return $file;
    }

    /**
     * Auto-set alt text, title, and caption on new image uploads.
     *
     * Derives metadata from the filename and post context when no
     * alt text has been set by the user.
     *
     * @param int $attachment_id The newly created attachment ID.
     * @return void
     */
    public static function auto_set_image_meta( $attachment_id ) {
        // Only process images.
        if ( ! wp_attachment_is_image( $attachment_id ) ) {
            return;
        }

        $attachment = get_post( $attachment_id );
        if ( ! $attachment ) {
            return;
        }

        // Check if alt text is already set.
        $existing_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

        if ( ! empty( $existing_alt ) ) {
            return;
        }

        // Derive meaningful text from the filename.
        $filename  = pathinfo( get_attached_file( $attachment_id ), PATHINFO_FILENAME );
        $alt_text  = self::filename_to_text( $filename );

        // If attached to a post, try to use focus keyword or post title.
        $parent_id = $attachment->post_parent;
        if ( $parent_id ) {
            $focus_keyword = get_post_meta( $parent_id, '_msh_focus_keyword', true );
            if ( ! empty( $focus_keyword ) ) {
                // Count existing images on this post to create a unique alt.
                $existing = self::count_post_images( $parent_id );
                $position = $existing + 1;
                $alt_text = sanitize_text_field( $focus_keyword ) . ' ' . $position;
            } else {
                $parent_title = get_the_title( $parent_id );
                if ( ! empty( $parent_title ) ) {
                    $alt_text = sanitize_text_field( $parent_title );
                }
            }
        }

        if ( ! empty( $alt_text ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
        }

        // Set title if empty.
        if ( empty( $attachment->post_title ) || $attachment->post_title === $filename ) {
            wp_update_post( array(
                'ID'           => $attachment_id,
                'post_title'   => $alt_text,
                'post_excerpt' => $alt_text, // Caption.
            ) );
        }
    }

    /**
     * Add missing alt text to images in content on output.
     *
     * Parses img tags in the post content, adds alt and title attributes
     * where missing, and ensures loading="lazy" is set on all images
     * below the fold (skips the first image).
     *
     * @param string $content The post content HTML.
     * @return string Modified content with alt text and lazy loading added.
     */
    public static function fix_missing_alt_text( $content ) {
        if ( empty( $content ) || is_admin() ) {
            return $content;
        }

        // Find all <img> tags.
        if ( ! preg_match_all( '/<img\b[^>]*>/i', $content, $matches ) ) {
            return $content;
        }

        $image_count = 0;

        foreach ( $matches[0] as $img_tag ) {
            $image_count++;
            $new_img = $img_tag;

            // Check for missing alt attribute.
            if ( ! preg_match( '/\balt\s*=/i', $new_img ) ) {
                $alt = self::derive_alt_from_img( $new_img );
                $new_img = str_replace( '<img', '<img alt="' . esc_attr( $alt ) . '"', $new_img );
            } elseif ( preg_match( '/\balt\s*=\s*["\'][\s]*["\']/i', $new_img ) ) {
                // Alt attribute exists but is empty.
                $alt = self::derive_alt_from_img( $new_img );
                $new_img = preg_replace(
                    '/\balt\s*=\s*["\'][\s]*["\']/i',
                    'alt="' . esc_attr( $alt ) . '"',
                    $new_img
                );
            }

            // Add title attribute if missing.
            if ( ! preg_match( '/\btitle\s*=/i', $new_img ) ) {
                // Extract alt value for title.
                if ( preg_match( '/\balt\s*=\s*["\']([^"\']*?)["\']/i', $new_img, $alt_match ) ) {
                    $title_val = $alt_match[1];
                    if ( ! empty( $title_val ) ) {
                        $new_img = str_replace( '<img', '<img title="' . esc_attr( $title_val ) . '"', $new_img );
                    }
                }
            }

            // Add loading="lazy" to all images except the first (above the fold).
            if ( $image_count > 1 && ! preg_match( '/\bloading\s*=/i', $new_img ) ) {
                $new_img = str_replace( '<img', '<img loading="lazy"', $new_img );
            }

            if ( $new_img !== $img_tag ) {
                $content = str_replace( $img_tag, $new_img, $content );
            }
        }

        return $content;
    }

    /**
     * Bulk audit: find all images without alt text.
     *
     * Queries the media library for image attachments that are missing
     * the _wp_attachment_image_alt meta key.
     *
     * @return array { count: int, images: array of { id, url, filename } }
     */
    public static function get_images_without_alt() {
        global $wpdb;

        // Get all image attachment IDs.
        $all_images = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_mime_type LIKE 'image/%'
             AND post_status = 'inherit'
             ORDER BY post_date DESC"
        );

        if ( empty( $all_images ) ) {
            return array(
                'count'  => 0,
                'images' => array(),
            );
        }

        // Filter to those missing alt text.
        $missing_alt = array();

        foreach ( $all_images as $image_id ) {
            $alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
            if ( empty( $alt ) ) {
                $url      = wp_get_attachment_url( $image_id );
                $filename = basename( get_attached_file( $image_id ) );

                $missing_alt[] = array(
                    'id'       => intval( $image_id ),
                    'url'      => esc_url( $url ),
                    'filename' => sanitize_file_name( $filename ),
                );
            }
        }

        return array(
            'count'  => count( $missing_alt ),
            'images' => $missing_alt,
        );
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Sanitize a filename to an SEO-friendly format.
     *
     * Converts to lowercase, replaces non-alphanumeric characters with
     * hyphens, and removes consecutive hyphens.
     *
     * @param string $name The raw filename (without extension).
     * @return string Sanitized filename.
     */
    private static function sanitize_filename( $name ) {
        $name = strtolower( $name );
        $name = preg_replace( '/[^a-z0-9]+/', '-', $name );
        $name = trim( $name, '-' );
        $name = preg_replace( '/-{2,}/', '-', $name );

        // Ensure not empty.
        if ( empty( $name ) ) {
            $name = 'image';
        }

        return $name;
    }

    /**
     * Convert a filename to human-readable text.
     *
     * Replaces hyphens and underscores with spaces and applies title case.
     *
     * @param string $filename The filename without extension.
     * @return string Human-readable text.
     */
    private static function filename_to_text( $filename ) {
        $text = str_replace( array( '-', '_' ), ' ', $filename );
        $text = preg_replace( '/\s+/', ' ', $text );
        $text = trim( $text );
        $text = ucwords( $text );

        return $text;
    }

    /**
     * Get a contextual name from the current post being edited.
     *
     * Checks for focus keyword first, then falls back to post title.
     *
     * @return string A meaningful base name or empty string.
     */
    private static function get_context_name() {
        // Try to get the post ID from the upload context.
        $post_id = 0;

        if ( isset( $_REQUEST['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $post_id = absint( $_REQUEST['post_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        } elseif ( isset( $_REQUEST['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $post_id = absint( $_REQUEST['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ( ! $post_id ) {
            return '';
        }

        // Prefer focus keyword.
        $focus_keyword = get_post_meta( $post_id, '_msh_focus_keyword', true );
        if ( ! empty( $focus_keyword ) ) {
            return sanitize_text_field( $focus_keyword );
        }

        // Fall back to post title.
        $title = get_the_title( $post_id );
        if ( ! empty( $title ) ) {
            return sanitize_text_field( $title );
        }

        return '';
    }

    /**
     * Derive alt text from an img tag's src attribute.
     *
     * Extracts the filename from the src URL and converts it to
     * human-readable text. Also checks for attachment alt in the database.
     *
     * @param string $img_tag The full <img> HTML tag.
     * @return string Derived alt text.
     */
    private static function derive_alt_from_img( $img_tag ) {
        // Try to get attachment ID from class.
        if ( preg_match( '/\bwp-image-(\d+)\b/', $img_tag, $class_match ) ) {
            $attachment_id = intval( $class_match[1] );
            $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
            if ( ! empty( $alt ) ) {
                return $alt;
            }

            // Use attachment title.
            $attachment = get_post( $attachment_id );
            if ( $attachment && ! empty( $attachment->post_title ) ) {
                return $attachment->post_title;
            }
        }

        // Fall back to filename from src.
        if ( preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $img_tag, $src_match ) ) {
            $filename = pathinfo( wp_parse_url( $src_match[1], PHP_URL_PATH ), PATHINFO_FILENAME );
            if ( ! empty( $filename ) ) {
                return self::filename_to_text( $filename );
            }
        }

        return __( 'Image', 'msh-seo' );
    }

    /**
     * Count existing image attachments for a given post.
     *
     * @param int $post_id The parent post ID.
     * @return int Number of image attachments.
     */
    private static function count_post_images( $post_id ) {
        $attachments = get_children( array(
            'post_parent'    => $post_id,
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'numberposts'    => -1,
        ) );

        return is_array( $attachments ) ? count( $attachments ) : 0;
    }
}
