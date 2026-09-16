<?php
/**
 * MSH Distribution - Distribute WordPress posts to MSH channels.
 *
 * Adds a meta box on the post editor for one-click distribution
 * to connected MSH platforms (LinkedIn, Twitter, newsletter, etc.).
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_SEO_Distribution {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_msh_seo_distribute_post', array( __CLASS__, 'ajax_distribute' ) );
    }

    /**
     * Load the meta box script on the post editor when the box is shown.
     *
     * @param string $hook Current admin page.
     */
    public static function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'post' !== get_post_type() || ! MSH_SEO_Auth::is_connected() ) {
            return;
        }
        wp_enqueue_script( 'msh-seo-distribution', MSH_SEO_URL . 'assets/js/distribution.js', array(), MSH_SEO_VERSION, true );
        wp_localize_script( 'msh-seo-distribution', 'mshSeoDistribution', array(
            'postId' => (int) get_the_ID(),
            'nonce'  => wp_create_nonce( 'msh_seo_distribute_nonce' ),
        ) );
    }

    public static function add_meta_box() {
        if ( ! MSH_SEO_Auth::is_connected() ) {
            return;
        }
        add_meta_box(
            'msh-distribution',
            __( 'MSH Distribution', 'msh-seo' ),
            array( __CLASS__, 'render_meta_box' ),
            'post',
            'side',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        $channels = array(
            'linkedin'   => 'LinkedIn',
            'twitter'    => 'Twitter / X',
            'newsletter' => 'Newsletter',
            'facebook'   => 'Facebook',
            'threads'    => 'Threads',
            'medium'     => 'Medium',
            'mastodon'   => 'Mastodon',
            'bluesky'    => 'Bluesky',
        );

        $distributed = get_post_meta( $post->ID, '_msh_seo_distributed_channels', true );
        $distributed = is_array( $distributed ) ? $distributed : array();

        wp_nonce_field( 'msh_seo_distribute_nonce', 'msh_seo_distribute_nonce_field' );
        ?>
        <div id="msh-distribution-box">
            <p class="description"><?php esc_html_e( 'Select channels to distribute this post to via MSH:', 'msh-seo' ); ?></p>

            <?php foreach ( $channels as $key => $label ) : ?>
                <label style="display:block; margin: 4px 0;">
                    <input type="checkbox" name="msh_seo_channels[]" value="<?php echo esc_attr( $key ); ?>"
                        <?php echo in_array( $key, $distributed, true ) ? 'disabled' : ''; ?> />
                    <?php echo esc_html( $label ); ?>
                    <?php if ( in_array( $key, $distributed, true ) ) : ?>
                        <em style="color:#46b450;"><?php esc_html_e( '(sent)', 'msh-seo' ); ?></em>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>

            <div style="margin-top: 10px;">
                <button type="button" id="msh-distribute-btn" class="button button-primary" <?php echo 'publish' !== $post->post_status ? 'disabled' : ''; ?>>
                    <?php esc_html_e( 'Distribute Now', 'msh-seo' ); ?>
                </button>
                <?php if ( 'publish' !== $post->post_status ) : ?>
                    <p class="description" style="color:#d63638;"><?php esc_html_e( 'Publish the post first.', 'msh-seo' ); ?></p>
                <?php endif; ?>
            </div>

            <div id="msh-distribute-result" style="margin-top: 8px;"></div>
        </div>

        <?php
    }

    public static function ajax_distribute() {
        check_ajax_referer( 'msh_seo_distribute_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'msh-seo' ) );
        }

        $post_id  = absint( $_POST['post_id'] ?? 0 );
        // wp_unslash, not stripslashes: WordPress slashes every superglobal on
        // the way in, and its own helper is the one that reverses exactly that.
        // Each decoded channel is then constrained to a key, so nothing
        // arbitrary reaches the API from a form field.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded on the next line, then every value is passed through sanitize_key.
        $raw_channels = isset( $_POST['channels'] ) ? wp_unslash( $_POST['channels'] ) : '[]';
        $channels     = json_decode( is_string( $raw_channels ) ? $raw_channels : '[]', true );
        $channels     = is_array( $channels ) ? array_values( array_filter( array_map( 'sanitize_key', $channels ) ) ) : array();

        if ( ! $post_id || ! is_array( $channels ) || empty( $channels ) ) {
            wp_send_json_error( __( 'Invalid request.', 'msh-seo' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            wp_send_json_error( __( 'Post must be published first.', 'msh-seo' ) );
        }

        $api_key = MSH_SEO_Auth::get_key();
        if ( empty( $api_key ) ) {
            wp_send_json_error( __( 'MSH API key not configured.', 'msh-seo' ) );
        }

        $connection = MSH_SEO_Auth::get_connection_info();
        $base_url   = ! empty( $connection['dashboard_url'] ) ? $connection['dashboard_url'] : 'https://app.marketingsohigh.com';

        $body = array(
            'post_url'  => get_permalink( $post_id ),
            'title'     => $post->post_title,
            'excerpt'   => $post->post_excerpt ?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 55 ),
            'content'   => wp_strip_all_tags( $post->post_content ),
            'channels'  => array_map( 'sanitize_text_field', $channels ),
            'image_url' => get_the_post_thumbnail_url( $post_id, 'full' ) ?: '',
        );

        $response = wp_remote_post( trailingslashit( $base_url ) . 'api/plugin/distribute', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 200 && $code < 300 && ! empty( $data['success'] ) ) {
            $existing = get_post_meta( $post_id, '_msh_seo_distributed_channels', true );
            $existing = is_array( $existing ) ? $existing : array();
            $merged   = array_unique( array_merge( $existing, $data['queued_channels'] ?? $channels ) );
            update_post_meta( $post_id, '_msh_seo_distributed_channels', $merged );

            wp_send_json_success( array(
                'message'         => sprintf(
                    /* translators: %s: comma-separated list of channel names the post was queued for. */
                    __( 'Queued for distribution: %s', 'msh-seo' ),
                    implode( ', ', $data['queued_channels'] ?? $channels )
                ),
                'queued_channels' => $data['queued_channels'] ?? $channels,
                'content_ids'     => $data['content_ids'] ?? array(),
            ) );
        } else {
            $error_msg = $data['error'] ?? __( 'Distribution failed.', 'msh-seo' );
            wp_send_json_error( $error_msg );
        }
    }
}
