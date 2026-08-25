<?php
/**
 * MSH Import - Import SEO data from Yoast, RankMath, and AIOSEO.
 *
 * Non-destructive importer that copies SEO metadata from competitor plugins
 * into MSH SEO meta fields. Never overwrites existing MSH data or deletes
 * source plugin data.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Import {

    /**
     * Yoast SEO meta field mapping.
     *
     * @var array<string, string>
     */
    const YOAST_MAP = array(
        '_yoast_wpseo_title'    => '_msh_seo_title',
        '_yoast_wpseo_metadesc' => '_msh_seo_description',
        '_yoast_wpseo_focuskw'  => '_msh_focus_keyword',
    );

    /**
     * Rank Math meta field mapping.
     *
     * @var array<string, string>
     */
    const RANKMATH_MAP = array(
        'rank_math_title'         => '_msh_seo_title',
        'rank_math_description'   => '_msh_seo_description',
        'rank_math_focus_keyword' => '_msh_focus_keyword',
    );

    /**
     * All in One SEO meta field mapping.
     *
     * @var array<string, string>
     */
    const AIOSEO_MAP = array(
        '_aioseo_title'       => '_msh_seo_title',
        '_aioseo_description' => '_msh_seo_description',
    );

    /**
     * Plugin slug to file path mapping for detection.
     *
     * @var array<string, string>
     */
    const PLUGIN_FILES = array(
        'yoast'    => 'wordpress-seo/wp-seo.php',
        'rankmath' => 'seo-by-rank-math/rank-math.php',
        'aioseo'   => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
    );

    /**
     * Plugin slug to human-readable name mapping.
     *
     * @var array<string, string>
     */
    const PLUGIN_NAMES = array(
        'yoast'    => 'Yoast SEO',
        'rankmath' => 'Rank Math',
        'aioseo'   => 'All in One SEO',
    );

    /**
     * Render the Import admin page.
     *
     * Shows detected SEO plugins and allows importing data from them.
     *
     * @return void
     */
    public static function render_admin_page() {
        $detected = self::detect_plugins();
        ?>
        <p><?php esc_html_e( 'Import SEO data from other plugins. MSH will never overwrite existing data or delete the source plugin\'s data.', 'msh-seo' ); ?></p>

        <?php if ( empty( $detected ) ) : ?>
            <p><?php esc_html_e( 'No supported SEO plugins detected. Supported plugins: Yoast SEO, Rank Math, All in One SEO.', 'msh-seo' ); ?></p>
        <?php else : ?>
            <?php foreach ( $detected as $plugin ) : ?>
                <?php
                $preview = self::get_import_preview( $plugin['slug'] );
                if ( is_wp_error( $preview ) ) {
                    continue;
                }
                ?>
                <div class="msh-import-plugin" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:16px;max-width:600px;">
                    <h3 style="margin-top:0;"><?php echo esc_html( $plugin['name'] ); ?></h3>
                    <table class="widefat" style="border:0;">
                        <tbody>
                            <tr>
                                <td><?php esc_html_e( 'Posts with SEO data', 'msh-seo' ); ?></td>
                                <td style="text-align:right;font-weight:600;"><?php echo esc_html( $preview['posts_with_data'] ); ?></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Posts to import (no MSH data yet)', 'msh-seo' ); ?></td>
                                <td style="text-align:right;font-weight:600;"><?php echo esc_html( $preview['posts_to_import'] ); ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if ( $preview['posts_to_import'] > 0 ) : ?>
                        <form method="post" style="margin-top:12px;">
                            <?php wp_nonce_field( 'msh_import_nonce' ); ?>
                            <input type="hidden" name="msh_import_plugin" value="<?php echo esc_attr( $plugin['slug'] ); ?>" />
                            <?php submit_button( sprintf(
                                /* translators: %s: plugin name */
                                __( 'Import from %s', 'msh-seo' ),
                                $plugin['name']
                            ), 'primary', 'msh_import_submit', false ); ?>
                        </form>
                    <?php else : ?>
                        <p class="description" style="margin-top:12px;">
                            <?php esc_html_e( 'Nothing to import. All posts already have MSH SEO data.', 'msh-seo' ); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php
        // Handle form submission.
        if ( isset( $_POST['msh_import_submit'] ) && check_admin_referer( 'msh_import_nonce' ) ) {
            $slug   = sanitize_text_field( $_POST['msh_import_plugin'] ?? '' );
            $result = self::import_from( $slug );

            if ( is_wp_error( $result ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>';
                printf(
                    /* translators: %d: number of posts imported */
                    esc_html__( 'Successfully imported SEO data for %d posts.', 'msh-seo' ),
                    intval( $result )
                );
                echo '</p></div>';
            }
        }
    }

    /**
     * Detect which SEO plugins are installed and active.
     *
     * @return array Array of detected plugins with slug, name, and active status.
     */
    public static function detect_plugins() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $detected = array();

        foreach ( self::PLUGIN_FILES as $slug => $plugin_file ) {
            if ( is_plugin_active( $plugin_file ) ) {
                $detected[] = array(
                    'slug'   => $slug,
                    'name'   => self::PLUGIN_NAMES[ $slug ],
                    'active' => true,
                );
            }
        }

        return $detected;
    }

    /**
     * Get the meta field mapping for a given plugin slug.
     *
     * @param string $plugin_slug One of 'yoast', 'rankmath', or 'aioseo'.
     * @return array|false The mapping array or false if the slug is unknown.
     */
    private static function get_mapping( $plugin_slug ) {
        $maps = array(
            'yoast'    => self::YOAST_MAP,
            'rankmath' => self::RANKMATH_MAP,
            'aioseo'   => self::AIOSEO_MAP,
        );

        return isset( $maps[ $plugin_slug ] ) ? $maps[ $plugin_slug ] : false;
    }

    /**
     * Preview how many posts would be affected by an import.
     *
     * @param string $plugin_slug One of 'yoast', 'rankmath', or 'aioseo'.
     * @return array|WP_Error Array with total_posts, posts_with_data, and posts_to_import.
     */
    public static function get_import_preview( $plugin_slug ) {
        global $wpdb;

        $mapping = self::get_mapping( $plugin_slug );

        if ( ! $mapping ) {
            return new WP_Error(
                'msh_import_unknown_plugin',
                __( 'Unknown SEO plugin slug.', 'msh-seo' )
            );
        }

        $source_keys = array_keys( $mapping );

        // Count posts that have at least one source meta key set.
        $placeholders = implode( ',', array_fill( 0, count( $source_keys ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $posts_with_data = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT( DISTINCT post_id ) FROM {$wpdb->postmeta}
                WHERE meta_key IN ({$placeholders})
                AND meta_value != ''",
                ...$source_keys
            )
        );

        // Count posts that have source data but are missing MSH data.
        $msh_keys      = array_values( $mapping );
        $msh_unique    = array_unique( $msh_keys );
        $msh_placeholders = implode( ',', array_fill( 0, count( $msh_unique ), '%s' ) );

        // Posts that have source data AND don't already have MSH meta.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $posts_to_import = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT( DISTINCT pm_source.post_id )
                FROM {$wpdb->postmeta} AS pm_source
                INNER JOIN {$wpdb->posts} AS p ON p.ID = pm_source.post_id AND p.post_status != 'auto-draft'
                WHERE pm_source.meta_key IN ({$placeholders})
                AND pm_source.meta_value != ''
                AND pm_source.post_id NOT IN (
                    SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                    WHERE meta_key IN ({$msh_placeholders})
                    AND meta_value != ''
                )",
                ...array_merge( $source_keys, $msh_unique )
            )
        );

        $total_posts = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status IN ('publish', 'draft', 'pending', 'future', 'private')"
        );

        return array(
            'plugin_name'    => self::PLUGIN_NAMES[ $plugin_slug ] ?? $plugin_slug,
            'total_posts'    => $total_posts,
            'posts_with_data' => $posts_with_data,
            'posts_to_import' => $posts_to_import,
        );
    }

    /**
     * Import SEO data from a competitor plugin into MSH meta fields.
     *
     * Only imports data for posts where the MSH meta field is currently empty.
     * Never deletes or modifies the original plugin's meta data.
     *
     * @param string $plugin_slug One of 'yoast', 'rankmath', or 'aioseo'.
     * @return int|WP_Error Number of posts imported, or error.
     */
    public static function import_from( $plugin_slug ) {
        global $wpdb;

        $mapping = self::get_mapping( $plugin_slug );

        if ( ! $mapping ) {
            return new WP_Error(
                'msh_import_unknown_plugin',
                __( 'Unknown SEO plugin slug.', 'msh-seo' )
            );
        }

        $source_keys  = array_keys( $mapping );
        $placeholders = implode( ',', array_fill( 0, count( $source_keys ), '%s' ) );

        // Get all post IDs that have any of the source meta keys with non-empty values.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                WHERE meta_key IN ({$placeholders})
                AND meta_value != ''",
                ...$source_keys
            )
        );

        if ( empty( $post_ids ) ) {
            return 0;
        }

        $imported_count = 0;

        foreach ( $post_ids as $post_id ) {
            $post_id   = (int) $post_id;
            $did_import = false;

            foreach ( $mapping as $source_key => $msh_key ) {
                // Only import if the MSH meta field is empty.
                $existing_msh = get_post_meta( $post_id, $msh_key, true );
                if ( ! empty( $existing_msh ) ) {
                    continue;
                }

                $source_value = get_post_meta( $post_id, $source_key, true );
                if ( empty( $source_value ) ) {
                    continue;
                }

                update_post_meta( $post_id, $msh_key, sanitize_text_field( $source_value ) );
                $did_import = true;
            }

            if ( $did_import ) {
                $imported_count++;
            }
        }

        return $imported_count;
    }
}
