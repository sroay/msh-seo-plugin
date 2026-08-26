<?php
/**
 * MSH Admin - Settings page and admin functionality.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Admin {

    /**
     * Initialize admin hooks.
     */
    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_msh_verify_connection', array( __CLASS__, 'ajax_verify_connection' ) );
        add_action( 'wp_ajax_msh_disconnect', array( __CLASS__, 'ajax_disconnect' ) );
    }

    /**
     * Enqueue admin CSS and JS on our settings page.
     */
    public static function enqueue_admin_assets( $hook ) {
        $allowed_pages = array(
            'toplevel_page_msh-seo',
            'msh-seo_page_msh-seo-autopilot',
            'msh-seo_page_msh-seo-analytics',
        );
        if ( ! in_array( $hook, $allowed_pages, true ) ) {
            return;
        }

        wp_enqueue_style(
            'msh-seo-admin',
            MSH_SEO_URL . 'assets/css/admin.css',
            array(),
            MSH_SEO_VERSION
        );

        // Use jQuery as the base handle — inline script attaches to it
        wp_add_inline_script( 'jquery', self::get_inline_admin_js() );

        wp_localize_script( 'jquery', 'mshAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'msh_admin_nonce' ),
        ) );
    }

    /**
     * Register WordPress settings.
     */
    public static function register_settings() {
        register_setting( 'msh_seo_settings', 'msh_seo_enable_meta', array(
            'type'              => 'boolean',
            'default'           => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ) );

        register_setting( 'msh_seo_settings', 'msh_seo_default_schema', array(
            'type'              => 'string',
            'default'           => 'Article',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        register_setting( 'msh_seo_settings', 'msh_seo_noindex_archives', array(
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ) );

        register_setting( 'msh_seo_settings', 'msh_seo_noindex_tags', array(
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ) );

        register_setting( 'msh_seo_settings', 'msh_seo_noindex_author', array(
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ) );

        // Connection section
        add_settings_section(
            'msh_seo_connection',
            __( 'MSH Connection', 'msh-seo' ),
            array( __CLASS__, 'render_connection_section' ),
            'msh-seo'
        );

        // General SEO section
        add_settings_section(
            'msh_seo_general',
            __( 'General SEO Settings', 'msh-seo' ),
            null,
            'msh-seo'
        );

        add_settings_field(
            'msh_seo_enable_meta',
            __( 'Enable Meta Tags', 'msh-seo' ),
            array( __CLASS__, 'render_checkbox_field' ),
            'msh-seo',
            'msh_seo_general',
            array(
                'name'        => 'msh_seo_enable_meta',
                'description' => __( 'Output SEO meta tags in the page head. <strong style="color:#16a34a;">Recommended: ON</strong> — Adds meta descriptions, Open Graph (Facebook/LinkedIn previews), and Twitter Cards to all your pages. Turn OFF only if another SEO plugin handles meta tags.', 'msh-seo' ),
            )
        );

        add_settings_field(
            'msh_seo_default_schema',
            __( 'Default Schema Type', 'msh-seo' ),
            array( __CLASS__, 'render_schema_dropdown' ),
            'msh-seo',
            'msh_seo_general'
        );

        add_settings_field(
            'msh_seo_noindex_archives',
            __( 'Noindex Archives', 'msh-seo' ),
            array( __CLASS__, 'render_checkbox_field' ),
            'msh-seo',
            'msh_seo_general',
            array(
                'name'        => 'msh_seo_noindex_archives',
                'description' => __( 'Add noindex to date-based archive pages. <strong style="color:#16a34a;">Recommended: ON</strong> — Date archives (e.g. /2026/04/) create duplicate content. Hiding them from search engines is an SEO best practice.', 'msh-seo' ),
            )
        );

        add_settings_field(
            'msh_seo_noindex_tags',
            __( 'Noindex Tags', 'msh-seo' ),
            array( __CLASS__, 'render_checkbox_field' ),
            'msh-seo',
            'msh_seo_general',
            array(
                'name'        => 'msh_seo_noindex_tags',
                'description' => __( 'Add noindex to tag archive pages. <strong style="color:#16a34a;">Recommended: ON</strong> — Tag pages are usually thin content with just a list of posts. Hiding them prevents search engines from indexing low-value pages.', 'msh-seo' ),
            )
        );

        add_settings_field(
            'msh_seo_noindex_author',
            __( 'Noindex Author Pages', 'msh-seo' ),
            array( __CLASS__, 'render_checkbox_field' ),
            'msh-seo',
            'msh_seo_general',
            array(
                'name'        => 'msh_seo_noindex_author',
                'description' => __( 'Add noindex to author archive pages. <strong style="color:#16a34a;">Recommended: ON for single-author sites</strong> — If you are the only author, the author page duplicates your blog page. Multi-author sites may want this OFF.', 'msh-seo' ),
            )
        );

        // ── AI & Conversion (world-class) ──────────────────────────────
        register_setting( 'msh_seo_settings', 'msh_seo_cta_enabled', array(
            'type'              => 'boolean',
            'default'           => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ) );
        register_setting( 'msh_seo_settings', 'msh_seo_social_profiles', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => array( __CLASS__, 'sanitize_profiles' ),
        ) );
        register_setting( 'msh_seo_settings', 'msh_seo_google_indexing_key', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => array( __CLASS__, 'sanitize_google_key' ),
        ) );

        add_settings_section(
            'msh_seo_features',
            __( 'AI & Conversion', 'msh-seo' ),
            array( __CLASS__, 'render_features_intro' ),
            'msh-seo'
        );

        add_settings_field(
            'msh_seo_cta_enabled',
            __( 'Smart CTA', 'msh-seo' ),
            array( __CLASS__, 'render_checkbox_field' ),
            'msh-seo',
            'msh_seo_features',
            array(
                'name'        => 'msh_seo_cta_enabled',
                'description' => __( 'Automatically add an intent-personalized call-to-action to the end of every post. <strong style="color:#16a34a;">Recommended: ON</strong> — The CTA adapts its message to each visitor and feeds conversion data back to MSH. Place it manually anywhere with the <code>[msh_cta]</code> shortcode.', 'msh-seo' ),
            )
        );

        add_settings_field(
            'msh_seo_social_profiles',
            __( 'Brand Profiles (sameAs)', 'msh-seo' ),
            array( __CLASS__, 'render_textarea_field' ),
            'msh-seo',
            'msh_seo_features',
            array(
                'name'        => 'msh_seo_social_profiles',
                'placeholder' => "https://www.linkedin.com/company/...\nhttps://x.com/...",
                'description' => __( 'One URL per line — your official social / authority profiles. Added to Organization schema as <code>sameAs</code> to strengthen your knowledge-graph identity.', 'msh-seo' ),
            )
        );

        add_settings_field(
            'msh_seo_google_indexing_key',
            __( 'Google Indexing API', 'msh-seo' ),
            array( __CLASS__, 'render_textarea_field' ),
            'msh-seo',
            'msh_seo_features',
            array(
                'name'        => 'msh_seo_google_indexing_key',
                'placeholder' => '{ "type": "service_account", "client_email": "...", "private_key": "..." }',
                'rows'        => 5,
                'description' => __( '<strong>Usually not needed:</strong> when this site is connected to MSH, Google indexing runs centrally — MSH pings Google on every publish with no key here. Only paste a Google Cloud <strong>service-account JSON</strong> (Indexing API enabled, added as a Search Console Owner) if you want this site to ping Google on its own, e.g. without an MSH connection.', 'msh-seo' ),
            )
        );
    }

    /**
     * Intro copy for the AI & Conversion section.
     */
    public static function render_features_intro() {
        echo '<p class="description" style="max-width:640px;">' . esc_html__( 'The features that make MSH SEO more than an SEO plugin — powered by your MSH brain connection.', 'msh-seo' ) . '</p>';
    }

    /**
     * Sanitize the social-profiles textarea into newline-separated URLs.
     */
    public static function sanitize_profiles( $value ) {
        $lines = preg_split( '/[\r\n]+/', (string) $value );
        $clean = array();
        foreach ( $lines as $line ) {
            $url = esc_url_raw( trim( $line ) );
            if ( $url ) {
                $clean[] = $url;
            }
        }
        return implode( "\n", $clean );
    }

    /**
     * Sanitize the Google service-account JSON (validate it parses; store raw).
     */
    public static function sanitize_google_key( $value ) {
        $value = trim( (string) $value );
        // The field renders blank when a key is saved (secrets are never
        // echoed back), so an empty submit means "keep the existing key" —
        // NOT "delete it". Explicit removal: type CLEAR.
        if ( '' === $value ) {
            return get_option( 'msh_seo_google_indexing_key', '' );
        }
        if ( 'CLEAR' === strtoupper( $value ) ) {
            return '';
        }
        $decoded = json_decode( $value, true );
        if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) ) {
            add_settings_error( 'msh_seo_google_indexing_key', 'invalid_json', __( 'Google Indexing key must be a valid service-account JSON with client_email and private_key.', 'msh-seo' ) );
            return get_option( 'msh_seo_google_indexing_key', '' );
        }
        // Re-encode to strip anything extraneous.
        return wp_json_encode( $decoded );
    }

    /**
     * Render a textarea settings field.
     */
    public static function render_textarea_field( $args ) {
        $name  = $args['name'];
        $value = get_option( $name, '' );
        $rows  = isset( $args['rows'] ) ? (int) $args['rows'] : 3;
        // Never echo a stored private key back into the page — show a masked marker.
        $is_secret = ( 'msh_seo_google_indexing_key' === $name );
        $display   = ( $is_secret && ! empty( $value ) ) ? '' : $value;
        ?>
        <textarea name="<?php echo esc_attr( $name ); ?>" rows="<?php echo esc_attr( $rows ); ?>" class="large-text code" placeholder="<?php echo esc_attr( $args['placeholder'] ?? '' ); ?>"><?php echo esc_textarea( $display ); ?></textarea>
        <?php if ( $is_secret && ! empty( $value ) ) : ?>
            <p class="description" style="color:#16a34a;">✓ <?php esc_html_e( 'A service-account key is saved. Leave blank to keep it, paste a new one to replace, or type CLEAR to remove it.', 'msh-seo' ); ?></p>
        <?php endif; ?>
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description" style="max-width:640px;"><?php echo wp_kses_post( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render the connection section HTML.
     */
    public static function render_connection_section() {
        $is_connected    = MSH_Auth::is_connected();
        $connection_info = MSH_Auth::get_connection_info();
        ?>
        <div id="msh-connection-panel" class="msh-panel">
            <?php if ( $is_connected && $connection_info ) : ?>
                <div class="msh-status msh-status--connected">
                    <span class="msh-status__dot msh-status__dot--green"></span>
                    <span>
                        <?php
                        printf(
                            /* translators: %1$s: plan name */
                            esc_html__( 'Connected to MSH (%1$s)', 'msh-seo' ),
                            esc_html( $connection_info['plan'] ?? 'Free' )
                        );
                        ?>
                    </span>
                </div>
                <?php if ( ! empty( $connection_info['org_name'] ) ) : ?>
                    <p class="msh-org-name">
                        <?php echo esc_html( $connection_info['org_name'] ); ?>
                    </p>
                <?php endif; ?>
                <?php if ( ! empty( $connection_info['usage'] ) ) : ?>
                    <div class="msh-usage-summary">
                        <strong><?php esc_html_e( 'Usage this month:', 'msh-seo' ); ?></strong>
                        <span>
                            <?php
                            printf(
                                '%s / %s AI calls',
                                esc_html( $connection_info['usage']['ai_calls'] ?? '0' ),
                                esc_html( $connection_info['limits']['ai_calls'] ?? 'unlimited' )
                            );
                            ?>
                        </span>
                    </div>
                <?php endif; ?>
                <p>
                    <button type="button" class="button" id="msh-disconnect-btn">
                        <?php esc_html_e( 'Disconnect', 'msh-seo' ); ?>
                    </button>
                </p>
            <?php else : ?>
                <div class="msh-status msh-status--disconnected">
                    <span class="msh-status__dot msh-status__dot--red"></span>
                    <span><?php esc_html_e( 'Not Connected', 'msh-seo' ); ?></span>
                </div>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="msh-api-key"><?php esc_html_e( 'API Key', 'msh-seo' ); ?></label>
                        </th>
                        <td>
                            <input type="password" id="msh-api-key" class="regular-text" placeholder="msh_..." />
                            <button type="button" class="button button-primary" id="msh-verify-btn">
                                <?php esc_html_e( 'Verify & Connect', 'msh-seo' ); ?>
                            </button>
                            <span id="msh-verify-spinner" class="spinner" style="float: none;"></span>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: link to settings page */
                                    esc_html__( 'Get your API key from %s', 'msh-seo' ),
                                    '<a href="https://app.marketingsohigh.com/settings" target="_blank" rel="noopener">marketingsohigh.com/settings</a>'
                                );
                                ?>
                            </p>
                            <div id="msh-verify-message" style="margin-top: 8px;"></div>
                        </td>
                    </tr>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a checkbox settings field.
     */
    public static function render_checkbox_field( $args ) {
        $name = $args['name'];
        // Read with NO explicit default so each option's REGISTERED default
        // applies when its row doesn't exist yet. Passing false here would
        // override a registered default of true (via WP's default_option
        // filter), rendering a default-on checkbox as unchecked — and because
        // update_option skips writing a value equal to the default, the row
        // never gets created, so the box could never appear checked.
        $value = get_option( $name );
        ?>
        <label style="display:flex;align-items:flex-start;gap:8px;max-width:600px;">
            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $value ); ?> style="margin-top:3px;" />
            <span><?php echo wp_kses_post( $args['description'] ?? '' ); ?></span>
        </label>
        <?php
    }

    /**
     * Render the schema type dropdown.
     */
    public static function render_schema_dropdown() {
        $value   = get_option( 'msh_seo_default_schema', 'Article' );
        $options = array(
            'Article'     => __( 'Article', 'msh-seo' ),
            'BlogPosting' => __( 'Blog Posting', 'msh-seo' ),
            'WebPage'     => __( 'Web Page', 'msh-seo' ),
            'Product'     => __( 'Product', 'msh-seo' ),
            'FAQPage'     => __( 'FAQ Page', 'msh-seo' ),
        );
        ?>
        <select name="msh_seo_default_schema">
            <?php foreach ( $options as $key => $label ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Render the main settings page — a modern branded console.
     */
    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $connected = MSH_Auth::is_connected();
        ?>
        <div class="wrap msh-settings-wrap">
            <div class="msh-hero">
                <div class="msh-hero__brand">
                    <span class="msh-hero__logo">MH</span>
                    <div>
                        <h1 class="msh-hero__title"><?php esc_html_e( 'MSH SEO', 'msh-seo' ); ?></h1>
                        <p class="msh-hero__tagline"><?php esc_html_e( 'The WordPress limb of your MSH marketing brain', 'msh-seo' ); ?></p>
                    </div>
                </div>
                <span class="msh-hero__version">v<?php echo esc_html( MSH_SEO_VERSION ); ?></span>
            </div>

            <?php
            // Bulk-index notice.
            $bulk = isset( $_GET['msh_bulk'] ) ? absint( $_GET['msh_bulk'] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( $bulk >= 0 ) {
                echo '<div class="notice notice-success is-dismissible"><p>' .
                    sprintf(
                        /* translators: %d: number of URLs */
                        esc_html__( 'Re-submitted %d URLs for indexing.', 'msh-seo' ),
                        (int) $bulk
                    ) . '</p></div>';
            }
            ?>

            <div class="msh-grid">
                <?php self::render_status_card( $connected ); ?>
                <?php self::render_ai_visibility_card( $connected ); ?>
                <?php self::render_indexing_card(); ?>
            </div>

            <h2 class="msh-section-heading"><?php esc_html_e( 'Settings', 'msh-seo' ); ?></h2>
            <form method="post" action="options.php" class="msh-settings-form">
                <?php
                settings_fields( 'msh_seo_settings' );
                do_settings_sections( 'msh-seo' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Feature-status card: which world-class features are live.
     */
    private static function render_status_card( $connected ) {
        $google_status = MSH_Indexing::google_status();
        if ( 'local' === $google_status ) {
            $indexing_desc = 'IndexNow + Google API (this site)';
        } elseif ( 'central' === $google_status ) {
            $indexing_desc = 'IndexNow + Google via MSH';
        } else {
            $indexing_desc = 'IndexNow (Google inactive)';
        }
        $features = array(
            array( 'Internal Link Mesh', $connected, 'Editor links from your topic graph' ),
            array( 'Smart CTAs + conversions', (bool) get_option( 'msh_seo_cta_enabled', true ), 'Personalized by visitor intent' ),
            array( 'AI-answer (AEO) schema', true, 'Speakable + quotable answer blocks' ),
            array( 'Instant indexing', true, $indexing_desc ),
        );
        ?>
        <div class="msh-card">
            <h3 class="msh-card__title"><?php esc_html_e( 'Features', 'msh-seo' ); ?></h3>
            <ul class="msh-feature-list">
                <?php foreach ( $features as $f ) : ?>
                    <li>
                        <span class="msh-dot <?php echo $f[1] ? 'msh-dot--on' : 'msh-dot--off'; ?>"></span>
                        <span class="msh-feature-list__name"><?php echo esc_html( $f[0] ); ?></span>
                        <span class="msh-feature-list__desc"><?php echo esc_html( $f[2] ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ( ! $connected ) : ?>
                <p class="msh-card__hint"><?php esc_html_e( 'Connect below to unlock the brain-powered features.', 'msh-seo' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AI-visibility card: does AI actually cite this site?
     */
    private static function render_ai_visibility_card( $connected ) {
        ?>
        <div class="msh-card">
            <h3 class="msh-card__title">
                <?php esc_html_e( 'AI Visibility', 'msh-seo' ); ?>
                <span class="msh-card__badge"><?php esc_html_e( 'Are you cited by AI?', 'msh-seo' ); ?></span>
            </h3>
            <?php
            if ( ! $connected ) {
                echo '<p class="msh-card__hint">' . esc_html__( 'Connect to MSH to see whether AI answers cite your site.', 'msh-seo' ) . '</p>';
                echo '</div>';
                return;
            }
            $data = MSH_API::ai_visibility();
            if ( is_wp_error( $data ) || ! isset( $data['checked'] ) || (int) $data['checked'] === 0 ) {
                echo '<p class="msh-card__hint">' . esc_html__( 'No AI-citation data yet. MSH checks weekly — come back soon.', 'msh-seo' ) . '</p>';
                echo '</div>';
                return;
            }
            $rate = (int) $data['citation_rate'];
            ?>
            <div class="msh-metric">
                <span class="msh-metric__value"><?php echo esc_html( $rate ); ?>%</span>
                <span class="msh-metric__label">
                    <?php
                    printf(
                        /* translators: 1: cited count, 2: total checked */
                        esc_html__( 'AI cites you for %1$d of %2$d tracked queries', 'msh-seo' ),
                        (int) $data['ai_cited'],
                        (int) $data['checked']
                    );
                    ?>
                </span>
            </div>
            <?php if ( ! empty( $data['competitors'] ) && is_array( $data['competitors'] ) ) : ?>
                <p class="msh-card__sub"><?php esc_html_e( 'AI cites these instead:', 'msh-seo' ); ?></p>
                <div class="msh-chips">
                    <?php foreach ( array_slice( $data['competitors'], 0, 6 ) as $c ) : ?>
                        <span class="msh-chip"><?php echo esc_html( is_array( $c ) ? ( $c['domain'] ?? '' ) : $c ); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Indexing card: recent submissions + bulk re-submit.
     */
    private static function render_indexing_card() {
        $recent = class_exists( 'MSH_Indexing' ) ? MSH_Indexing::get_recent_submissions() : array();
        $gstatus = class_exists( 'MSH_Indexing' ) ? MSH_Indexing::google_status() : 'off';
        if ( 'local' === $gstatus ) {
            $glabel = 'Google API (this site)';
        } elseif ( 'central' === $gstatus ) {
            $glabel = 'Google via MSH';
        } else {
            $glabel = 'Google (inactive)';
        }
        ?>
        <div class="msh-card">
            <h3 class="msh-card__title"><?php esc_html_e( 'Instant Indexing', 'msh-seo' ); ?></h3>
            <p class="msh-card__sub">
                <span class="msh-chip msh-chip--on">IndexNow</span>
                <span class="msh-chip <?php echo 'off' !== $gstatus ? 'msh-chip--on' : ''; ?>"><?php echo esc_html( $glabel ); ?></span>
            </p>
            <?php if ( 'central' === $gstatus ) : ?>
                <p class="msh-card__hint" style="margin-bottom:8px;">MSH pings Google automatically on every publish &mdash; no key needed on this site.</p>
            <?php endif; ?>
            <?php if ( ! empty( $recent ) ) : ?>
                <ul class="msh-log">
                    <?php foreach ( array_slice( $recent, 0, 4 ) as $entry ) : ?>
                        <li>
                            <span class="msh-dot <?php echo ! empty( $entry['success'] ) ? 'msh-dot--on' : 'msh-dot--off'; ?>"></span>
                            <span class="msh-log__url"><?php echo esc_html( is_array( $entry['urls'] ) ? ( $entry['urls'][0] ?? '' ) : '' ); ?></span>
                            <span class="msh-log__time"><?php echo esc_html( $entry['timestamp'] ?? '' ); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p class="msh-card__hint"><?php esc_html_e( 'No submissions yet — publish a post or re-submit all URLs.', 'msh-seo' ); ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
                <input type="hidden" name="action" value="msh_seo_bulk_index" />
                <?php wp_nonce_field( 'msh_seo_bulk_index' ); ?>
                <button type="submit" class="button msh-btn-secondary"><?php esc_html_e( 'Re-submit all URLs', 'msh-seo' ); ?></button>
            </form>
        </div>
        <?php
    }

    /**
     * AJAX: Verify API key and establish connection.
     */
    public static function ajax_verify_connection() {
        check_ajax_referer( 'msh_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'msh-seo' ) ) );
        }

        $api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter an API key.', 'msh-seo' ) ) );
        }

        MSH_Auth::save_key( $api_key );

        $result = MSH_Auth::verify_connection();

        if ( is_wp_error( $result ) ) {
            MSH_Auth::delete_key();
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message'    => __( 'Connected successfully!', 'msh-seo' ),
            'connection' => $result,
        ) );
    }

    /**
     * AJAX: Disconnect from MSH.
     */
    public static function ajax_disconnect() {
        check_ajax_referer( 'msh_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'msh-seo' ) ) );
        }

        MSH_Auth::delete_key();
        wp_send_json_success( array( 'message' => __( 'Disconnected.', 'msh-seo' ) ) );
    }

    /**
     * Inline admin JavaScript for the settings page.
     */
    private static function get_inline_admin_js() {
        return <<<'JS'
jQuery(function($) {
    $('#msh-verify-btn').on('click', function() {
        var btn = $(this);
        var key = $('#msh-api-key').val().trim();
        var spinner = $('#msh-verify-spinner');
        var msg = $('#msh-verify-message');

        if (!key) {
            msg.html('<span style="color:#d63638;">Please enter an API key.</span>');
            return;
        }

        btn.prop('disabled', true);
        spinner.addClass('is-active');
        msg.html('');

        $.post(mshAdmin.ajaxUrl, {
            action: 'msh_verify_connection',
            nonce: mshAdmin.nonce,
            api_key: key
        }, function(response) {
            btn.prop('disabled', false);
            spinner.removeClass('is-active');

            if (response.success) {
                msg.html('<span style="color:#00a32a;">' + response.data.message + '</span>');
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                msg.html('<span style="color:#d63638;">' + response.data.message + '</span>');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            spinner.removeClass('is-active');
            msg.html('<span style="color:#d63638;">Request failed. Please try again.</span>');
        });
    });

    $('#msh-disconnect-btn').on('click', function() {
        if (!confirm('Disconnect from Marketing So High?')) return;

        $.post(mshAdmin.ajaxUrl, {
            action: 'msh_disconnect',
            nonce: mshAdmin.nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            }
        });
    });
});
JS;
    }
}

MSH_Admin::init();
