<?php
/**
 * Plugin Name: MSH SEO – AI-Powered SEO Tools
 * Plugin URI: https://marketingsohigh.com
 * Description: Free SEO tools for WordPress with AI-powered content optimization. Connects to Marketing So High for advanced AI features.
 * Version: 1.1.2
 * Author: Marketing So High
 * Author URI: https://marketingsohigh.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: msh-seo
 * Requires at least: 6.5
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MSH_SEO_VERSION', '1.1.2' );
define( 'MSH_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSH_SEO_URL', plugin_dir_url( __FILE__ ) );

// Load includes
require_once MSH_SEO_DIR . 'includes/class-msh-auth.php';
require_once MSH_SEO_DIR . 'includes/class-msh-api.php';
require_once MSH_SEO_DIR . 'includes/class-msh-admin.php';
require_once MSH_SEO_DIR . 'includes/class-msh-seo-analysis.php';
require_once MSH_SEO_DIR . 'includes/class-msh-meta-tags.php';
require_once MSH_SEO_DIR . 'includes/class-msh-schema.php';
require_once MSH_SEO_DIR . 'includes/class-msh-sitemap.php';
require_once MSH_SEO_DIR . 'includes/class-msh-redirects.php';
require_once MSH_SEO_DIR . 'includes/class-msh-import.php';
require_once MSH_SEO_DIR . 'includes/class-msh-dashboard-widget.php';
require_once MSH_SEO_DIR . 'includes/class-msh-distribution.php';
require_once MSH_SEO_DIR . 'includes/class-msh-woocommerce.php';
require_once MSH_SEO_DIR . 'includes/class-msh-image-seo.php';
require_once MSH_SEO_DIR . 'includes/class-msh-breadcrumbs.php';
require_once MSH_SEO_DIR . 'includes/class-msh-indexing.php';
require_once MSH_SEO_DIR . 'includes/class-msh-analytics.php';
require_once MSH_SEO_DIR . 'includes/class-msh-aeo.php';
require_once MSH_SEO_DIR . 'includes/class-msh-page-types.php';
require_once MSH_SEO_DIR . 'includes/class-msh-crawlers.php';
require_once MSH_SEO_DIR . 'includes/class-msh-freshness.php';
require_once MSH_SEO_DIR . 'includes/class-msh-autopilot.php';
require_once MSH_SEO_DIR . 'includes/class-msh-link-mesh.php';
require_once MSH_SEO_DIR . 'includes/class-msh-conversion.php';
require_once MSH_SEO_DIR . 'includes/class-msh-answer.php';
require_once MSH_SEO_DIR . 'includes/class-msh-beacon.php';
require_once MSH_SEO_DIR . 'includes/class-msh-conflicts.php';
require_once MSH_SEO_DIR . 'includes/class-msh-site-health.php';

/**
 * Activation hook: flush rewrite rules.
 */
/**
 * Load translations.
 *
 * WordPress.org-hosted plugins get their translations loaded automatically
 * since WP 4.6, so this changes nothing there. It matters for anyone who
 * installs the plugin by hand or ships their own language pack, which is
 * otherwise silently untranslated despite 300+ strings being ready for it.
 */
function msh_seo_load_textdomain() {
	load_plugin_textdomain( 'msh-seo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'msh_seo_load_textdomain' );

function msh_seo_activate() {
    MSH_Redirects::create_tables();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'msh_seo_activate' );

/**
 * Deactivation hook: clean up transients.
 */
function msh_seo_deactivate() {
    global $wpdb;
    // Leave no orphaned cron entry behind pointing at a class that is no
    // longer loaded.
    MSH_Beacon::unschedule();
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_msh_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_msh_' ) . '%'
    ) );
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'msh_seo_deactivate' );

/**
 * After a plugin update, force a fresh MSH connection verify so newly added
 * flags (e.g. central Google indexing) show up immediately instead of after
 * the hourly transient expires.
 */
function msh_seo_after_update_reverify() {
    if ( get_option( 'msh_seo_version' ) !== MSH_SEO_VERSION ) {
        update_option( 'msh_seo_version', MSH_SEO_VERSION, false );
        delete_transient( 'msh_seo_verified' );
        delete_transient( 'msh_seo_verify_backoff' );
    }
}
add_action( 'admin_init', 'msh_seo_after_update_reverify' );

/**
 * Register admin menu page.
 */
function msh_seo_admin_menu() {
    add_menu_page(
        __( 'MSH SEO', 'msh-seo' ),
        __( 'MSH SEO', 'msh-seo' ),
        'manage_options',
        'msh-seo',
        array( 'MSH_Admin', 'render_settings_page' ),
        'dashicons-chart-line',
        80
    );

    add_submenu_page(
        'msh-seo',
        __( 'Redirects', 'msh-seo' ),
        __( 'Redirects', 'msh-seo' ),
        'manage_options',
        'msh-seo-redirects',
        'msh_seo_redirects_page'
    );

    add_submenu_page(
        'msh-seo',
        __( 'Import SEO', 'msh-seo' ),
        __( 'Import SEO', 'msh-seo' ),
        'manage_options',
        'msh-seo-import',
        'msh_seo_import_page'
    );

    add_submenu_page(
        'msh-seo',
        __( 'Autopilot', 'msh-seo' ),
        __( 'Autopilot', 'msh-seo' ),
        'manage_options',
        'msh-seo-autopilot',
        'msh_seo_autopilot_page'
    );

    add_submenu_page(
        'msh-seo',
        __( 'Analytics', 'msh-seo' ),
        __( 'Analytics', 'msh-seo' ),
        'manage_options',
        'msh-seo-analytics',
        'msh_seo_analytics_page'
    );
}
add_action( 'admin_menu', 'msh_seo_admin_menu' );

/**
 * Render the Autopilot admin page.
 */
function msh_seo_autopilot_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    MSH_Autopilot::render_admin_page();
}

/**
 * Render the Redirects admin page.
 */
function msh_seo_redirects_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'MSH SEO Redirects', 'msh-seo' ) . '</h1>';
    if ( class_exists( 'MSH_Redirects' ) ) {
        MSH_Redirects::render_admin_page();
    } else {
        echo '<p>' . esc_html__( 'Redirects module is not available.', 'msh-seo' ) . '</p>';
    }
    echo '</div>';
}

/**
 * Render the Import SEO admin page.
 */
function msh_seo_import_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Import SEO Data', 'msh-seo' ) . '</h1>';
    if ( class_exists( 'MSH_Import' ) ) {
        MSH_Import::render_admin_page();
    } else {
        echo '<p>' . esc_html__( 'Import module is not available.', 'msh-seo' ) . '</p>';
    }
    echo '</div>';
}

/**
 * Render the Analytics admin page.
 */
function msh_seo_analytics_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    MSH_Analytics::render_page();
}

/**
 * Enqueue Gutenberg sidebar assets.
 */
function msh_seo_enqueue_block_editor_assets() {
    $asset_file = MSH_SEO_DIR . 'build/index.asset.php';

    if ( ! file_exists( $asset_file ) ) {
        return;
    }

    $asset = include $asset_file;

    wp_enqueue_script(
        'msh-seo-editor',
        MSH_SEO_URL . 'build/index.js',
        $asset['dependencies'],
        $asset['version'],
        true
    );

    $css_file = MSH_SEO_DIR . 'build/index.css';
    if ( file_exists( $css_file ) ) {
        wp_enqueue_style(
            'msh-seo-editor',
            MSH_SEO_URL . 'build/index.css',
            array(),
            $asset['version']
        );
    }

    $connection_info = MSH_Auth::get_connection_info();

    // REST base pinned to the WORDPRESS host. rest_url() builds on home_url(),
    // which on headless installs is the public front-end domain — cross-origin
    // from the editor, so every panel's API call would fail. The editor bundle
    // calls this base with absolute URLs (apiFetch's own root middleware would
    // otherwise rewrite path-based requests back to the wrong host).
    $rest_base = rest_url( 'msh-seo/v1/' );
    $home_root = untrailingslashit( home_url() );
    $site_root = untrailingslashit( site_url() );
    if ( $home_root !== $site_root && 0 === strpos( $rest_base, $home_root ) ) {
        $rest_base = $site_root . substr( $rest_base, strlen( $home_root ) );
    }

    wp_localize_script( 'msh-seo-editor', 'mshSeoData', array(
        'isConnected'    => MSH_Auth::is_connected(),
        'connectionInfo' => $connection_info ? $connection_info : null,
        'restUrl'        => esc_url_raw( $rest_base ),
        'nonce'          => wp_create_nonce( 'wp_rest' ),
        'settingsUrl'    => admin_url( 'admin.php?page=msh-seo' ),
    ) );
}
add_action( 'enqueue_block_editor_assets', 'msh_seo_enqueue_block_editor_assets' );

/**
 * Initialize meta tags (canonical removal, Divi conflict handling, and meta output).
 * All handled inside MSH_Meta_Tags::init().
 */
/*
 * Head output is decided on `plugins_loaded`, NOT here.
 *
 * Plugins load in roughly alphabetical order, so msh-seo is loaded before
 * wordpress-seo — and at this point in the file WPSEO_VERSION does not exist
 * yet. Checking now finds no conflict on a site that plainly has one, which is
 * exactly what happened the first time this was tested against a real Yoast
 * install. `plugins_loaded` is the earliest moment every plugin is present, and
 * still long before wp_head fires.
 */
add_action( 'plugins_loaded', 'msh_seo_init_head_output' );
function msh_seo_init_head_output() {
	// Two plugins both writing titles, descriptions, Open Graph tags and
	// canonicals gives every page two of each. That measurably worsens the
	// user's search results and reads to them as this plugin breaking their
	// site. Whoever was there first keeps the output.
	if ( ! MSH_Conflicts::should_output() ) {
		return;
	}
	MSH_Meta_Tags::init();
	// Replaces the core sitemap, so two SEO plugins would mean two competing
	// sitemap indexes.
	MSH_Sitemap::init();
	// Breadcrumb JSON-LD duplicates too.
	MSH_Breadcrumbs::init();
}

/**
 * Output JSON-LD schema markup in wp_head.
 */
add_action( 'wp_head', array( 'MSH_Schema', 'output_schema' ), 2 );

/**
 * Initialize sitemap functionality.
 * Called directly (not via init hook) so the early priority-1 URI interceptor works.
 */


// Disable WordPress core sitemaps (MSH SEO serves its own)
add_filter( 'wp_sitemaps_enabled', '__return_false' );

/**
 * Initialize redirects (301/302 processing + 404 logging).
 */
add_action( 'init', array( 'MSH_Redirects', 'init' ) );

MSH_Dashboard_Widget::init();

/**
 * Initialize distribution meta box on post editor.
 */
MSH_Distribution::init();

// WooCommerce SEO (only if WooCommerce is active)
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        MSH_WooCommerce::init();
    }
});

// Image SEO automation
MSH_Image_SEO::init();

// Breadcrumbs shortcode


// Instant Indexing (IndexNow)
MSH_Indexing::init();

// Internal Link Mesh — editor suggestions from the MSH cluster graph
MSH_Link_Mesh::init();

// Personalized CTAs + conversion tracking (content → rank → convert loop)
MSH_Conversion::init();

// AEO / llms.txt
MSH_AEO::init();

// AI Crawler management (robots.txt, llms.txt)
MSH_Crawlers::init();

// Content freshness scanner (weekly cron)
MSH_Freshness::init();

// SEO Autopilot — self-healing content engine (weekly cron + REST receiver)
MSH_Autopilot::init();

// Analytics AJAX handler
MSH_Analytics::init();

// Daily health beacon — the plugin reports its own condition to MSH.
// Without it a subsystem can be dead for a year while the site looks fine
// from outside, which is exactly what the redirect engine did.
MSH_Beacon::init();

// These two always run, conflict or not. The conflict notice is the only thing
// telling the user why their meta tags did not change, and Site Health is where
// they will look before they open a support thread.
MSH_Conflicts::init();
MSH_Site_Health::init();

/**
 * Register post meta fields.
 */
function msh_seo_register_meta() {
    $meta_fields = array(
        '_msh_seo_title'       => 'string',
        '_msh_seo_description' => 'string',
        '_msh_focus_keyword'   => 'string',
        '_msh_seo_score'       => 'integer',
        '_msh_seo_noindex'     => 'boolean',
        '_msh_schema_type'     => 'string',
    );

    foreach ( $meta_fields as $key => $type ) {
        register_post_meta( '', $key, array(
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => $type,
            'auth_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ) );
    }
}
add_action( 'init', 'msh_seo_register_meta' );

/**
 * Register REST API endpoints.
 */
function msh_seo_register_rest_routes() {
    register_rest_route( 'msh-seo/v1', '/analyze', array(
        'methods'             => 'POST',
        'callback'            => 'msh_seo_rest_analyze',
        'permission_callback' => function () {
            return current_user_can( 'edit_posts' );
        },
    ) );

    register_rest_route( 'msh-seo/v1', '/generate-meta', array(
        'methods'             => 'POST',
        'callback'            => 'msh_seo_rest_generate_meta',
        'permission_callback' => function () {
            return current_user_can( 'edit_posts' );
        },
    ) );

    register_rest_route( 'msh-seo/v1', '/local-analyze', array(
        'methods'             => 'POST',
        'callback'            => 'msh_seo_rest_local_analyze',
        'permission_callback' => function () {
            return current_user_can( 'edit_posts' );
        },
    ) );

    register_rest_route( 'msh-seo/v1', '/publish', array(
        'methods'             => 'POST',
        'callback'            => 'msh_seo_rest_publish',
        'permission_callback' => 'msh_seo_verify_plugin_key',
    ) );

    register_rest_route( 'msh-seo/v1', '/aeo-analyze', array(
        'methods'             => 'POST',
        'callback'            => 'msh_seo_rest_aeo_analyze',
        'permission_callback' => function () {
            return current_user_can( 'edit_posts' );
        },
    ) );

    // Dashboard push: store/clear the Google site verification token.
    // Authenticated via WordPress application passwords (Basic auth) — the
    // same credential the MSH dashboard already uses to publish posts.
    register_rest_route( 'msh-seo/v1', '/site-verification', array(
        'methods'             => 'POST',
        'callback'            => 'msh_seo_rest_site_verification',
        'permission_callback' => function () {
            return current_user_can( 'manage_options' );
        },
    ) );
}
add_action( 'rest_api_init', 'msh_seo_register_rest_routes' );

/**
 * REST: store the Google site verification token pushed by the MSH dashboard
 * (one-click Google Search Console onboarding). The token is rendered as
 * <meta name="google-site-verification"> in <head> by MSH_Meta_Tags.
 */
function msh_seo_rest_site_verification( WP_REST_Request $request ) {
    $params = $request->get_json_params();

    if ( ! empty( $params['clear'] ) ) {
        delete_option( 'msh_seo_google_site_verification' );
        return rest_ensure_response( array(
            'success' => true,
            'cleared' => true,
            'version' => MSH_SEO_VERSION,
        ) );
    }

    $token = isset( $params['token'] ) ? sanitize_text_field( (string) $params['token'] ) : '';

    if ( '' === $token || ! preg_match( '/^[A-Za-z0-9_\-]{8,256}$/', $token ) ) {
        return new WP_Error( 'msh_invalid_token', 'Invalid verification token format.', array( 'status' => 400 ) );
    }

    update_option( 'msh_seo_google_site_verification', $token );

    return rest_ensure_response( array(
        'success' => true,
        'stored'  => true,
        'home'    => get_home_url(),
        'version' => MSH_SEO_VERSION,
    ) );
}

/**
 * REST: AI-powered analysis via MSH API.
 */
function msh_seo_rest_analyze( WP_REST_Request $request ) {
    $params = $request->get_json_params();

    $title   = sanitize_text_field( $params['title'] ?? '' );
    $content = wp_kses_post( $params['content'] ?? '' );
    $keyword = sanitize_text_field( $params['keyword'] ?? '' );
    $url     = esc_url_raw( $params['url'] ?? '' );

    // When only keyword is provided, do a keyword-only lookup (no title/content required).
    if ( empty( $title ) && empty( $content ) && ! empty( $keyword ) ) {
        $result = MSH_API::analyze( '', '', $keyword, $url );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    if ( empty( $title ) || empty( $content ) ) {
        return new WP_Error( 'missing_data', __( 'Title and content are required.', 'msh-seo' ), array( 'status' => 400 ) );
    }

    $result = MSH_API::analyze( $title, $content, $keyword, $url );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    return rest_ensure_response( $result );
}

/**
 * REST: Generate meta title/description via MSH API.
 */
function msh_seo_rest_generate_meta( WP_REST_Request $request ) {
    $params = $request->get_json_params();

    $title   = sanitize_text_field( $params['title'] ?? '' );
    $content = wp_kses_post( $params['content'] ?? '' );
    $keyword = sanitize_text_field( $params['keyword'] ?? '' );

    if ( empty( $title ) || empty( $content ) ) {
        return new WP_Error( 'missing_data', __( 'Title and content are required.', 'msh-seo' ), array( 'status' => 400 ) );
    }

    $result = MSH_API::generate_meta( $title, $content, $keyword );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    return rest_ensure_response( $result );
}

/**
 * REST: Local SEO analysis (no API call).
 */
function msh_seo_rest_local_analyze( WP_REST_Request $request ) {
    $params  = $request->get_json_params();
    $post_id = absint( $params['post_id'] ?? 0 );

    if ( ! $post_id ) {
        return new WP_Error( 'missing_post', __( 'Post ID is required.', 'msh-seo' ), array( 'status' => 400 ) );
    }

    $result = MSH_SEO_Analysis::analyze( $post_id );

    return rest_ensure_response( $result );
}

/**
 * REST: AEO (Answer Engine Optimization) local analysis.
 */
function msh_seo_rest_aeo_analyze( WP_REST_Request $request ) {
    $params  = $request->get_json_params();
    $content = wp_kses_post( $params['content'] ?? '' );
    $title   = sanitize_text_field( $params['title'] ?? '' );

    if ( empty( $content ) ) {
        return new WP_Error( 'missing_data', __( 'Content is required.', 'msh-seo' ), array( 'status' => 400 ) );
    }

    $result = MSH_AEO::analyze( $content, $title );
    return rest_ensure_response( $result );
}

/**
 * Permission callback: verify the MSH plugin API key from Authorization header.
 */
function msh_seo_verify_plugin_key( WP_REST_Request $request ) {
    $auth_header = $request->get_header( 'authorization' );
    if ( empty( $auth_header ) || strpos( $auth_header, 'Bearer ' ) !== 0 ) {
        return false;
    }
    $provided_key = substr( $auth_header, 7 );
    $stored_key   = MSH_Auth::get_key();
    if ( empty( $stored_key ) ) {
        return false;
    }
    return hash_equals( $stored_key, $provided_key );
}

/**
 * REST: Receive and publish content from MSH dashboard.
 * Creates a WordPress post with all SEO metadata pre-filled.
 */
function msh_seo_rest_publish( WP_REST_Request $request ) {
    $params = $request->get_json_params();

    // Required fields
    $title        = sanitize_text_field( $params['title'] ?? '' );
    $content_html = wp_kses_post( $params['content'] ?? '' );

    if ( empty( $title ) || empty( $content_html ) ) {
        return new WP_Error( 'missing_data', 'Title and content are required.', array( 'status' => 400 ) );
    }

    // Optional fields
    $slug            = sanitize_title( $params['slug'] ?? '' );
    $excerpt         = sanitize_text_field( $params['meta_description'] ?? '' );
    $status          = in_array( $params['status'] ?? 'publish', array( 'publish', 'draft', 'pending' ), true ) ? $params['status'] : 'publish';
    $focus_keyword   = sanitize_text_field( $params['focus_keyword'] ?? '' );
    $seo_title       = sanitize_text_field( $params['seo_title'] ?? '' );
    $seo_description = sanitize_text_field( $params['seo_description'] ?? '' );
    $seo_score       = absint( $params['seo_score'] ?? 0 );
    $schema_type     = sanitize_text_field( $params['schema_type'] ?? '' );
    $featured_image_url = esc_url_raw( $params['featured_image_url'] ?? '' );
    $image_alt_text  = sanitize_text_field( $params['image_alt_text'] ?? '' );
    $categories      = $params['categories'] ?? array();
    $tags            = $params['tags'] ?? array();

    // Create the post
    $post_data = array(
        'post_title'   => $title,
        'post_content' => $content_html,
        'post_status'  => $status,
        'post_type'    => 'post',
        'post_excerpt' => $excerpt,
    );
    if ( ! empty( $slug ) ) {
        $post_data['post_name'] = $slug;
    }

    $post_id = wp_insert_post( $post_data, true );

    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    // Set MSH SEO meta fields
    if ( ! empty( $focus_keyword ) ) {
        update_post_meta( $post_id, '_msh_focus_keyword', $focus_keyword );
    }
    if ( ! empty( $seo_title ) ) {
        update_post_meta( $post_id, '_msh_seo_title', $seo_title );
    }
    if ( ! empty( $seo_description ) ) {
        update_post_meta( $post_id, '_msh_seo_description', $seo_description );
    }
    if ( $seo_score > 0 ) {
        update_post_meta( $post_id, '_msh_seo_score', $seo_score );
    }
    if ( ! empty( $schema_type ) ) {
        update_post_meta( $post_id, '_msh_schema_type', $schema_type );
    }

    // Handle featured image — download from URL and attach
    $featured_media_id = null;
    if ( ! empty( $featured_image_url ) ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $featured_image_url, 30 );
        if ( ! is_wp_error( $tmp ) ) {
            $file_array = array(
                'name'     => basename( wp_parse_url( $featured_image_url, PHP_URL_PATH ) ) ?: 'featured-image.webp',
                'tmp_name' => $tmp,
            );

            $attachment_id = media_handle_sideload( $file_array, $post_id, $image_alt_text );

            if ( ! is_wp_error( $attachment_id ) ) {
                set_post_thumbnail( $post_id, $attachment_id );
                if ( ! empty( $image_alt_text ) ) {
                    update_post_meta( $attachment_id, '_wp_attachment_image_alt', $image_alt_text );
                }
                $featured_media_id = $attachment_id;
            }

            // Clean up temp file if it still exists
            if ( file_exists( $tmp ) ) {
                @unlink( $tmp );
            }
        }
    }

    // Handle categories
    if ( ! empty( $categories ) && is_array( $categories ) ) {
        $cat_ids = array();
        foreach ( $categories as $cat_name ) {
            $cat = get_cat_ID( sanitize_text_field( $cat_name ) );
            if ( ! $cat ) {
                $new_cat = wp_insert_category( array( 'cat_name' => sanitize_text_field( $cat_name ) ) );
                if ( ! is_wp_error( $new_cat ) ) {
                    $cat_ids[] = $new_cat;
                }
            } else {
                $cat_ids[] = $cat;
            }
        }
        if ( ! empty( $cat_ids ) ) {
            wp_set_post_categories( $post_id, $cat_ids );
        }
    }

    // Handle tags
    if ( ! empty( $tags ) && is_array( $tags ) ) {
        $clean_tags = array_map( 'sanitize_text_field', $tags );
        wp_set_post_tags( $post_id, $clean_tags );
    }

    // Get the published URL
    $post_url = get_permalink( $post_id );

    return rest_ensure_response( array(
        'success'           => true,
        'post_id'           => $post_id,
        'post_url'          => $post_url,
        'featured_media_id' => $featured_media_id,
        'status'            => $status,
    ) );
}
