<?php
/**
 * MSH Internal Link Mesh.
 *
 * The plugin's moat feature. When editing a post, the block-editor panel asks
 * the MSH brain "what should this post link to?" The brain scores the whole
 * site's topical cluster graph (which lives in the MSH dashboard, not in
 * WordPress) plus the site's own post inventory, and returns ranked, one-click
 * internal-link suggestions with the best anchor text. No standalone SEO plugin
 * can do this — it only sees the current post.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Link_Mesh {

    /**
     * Hook REST route registration.
     */
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * Register the /link-suggestions REST route (editor → plugin → MSH brain).
     */
    public static function register_routes() {
        register_rest_route( 'msh-seo/v1', '/link-suggestions', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_link_suggestions' ),
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ) );
    }

    /**
     * REST: return ranked internal-link suggestions for the post being edited.
     */
    public static function rest_link_suggestions( WP_REST_Request $request ) {
        $params = $request->get_json_params();

        $post_id = absint( $params['post_id'] ?? 0 );
        $title   = sanitize_text_field( $params['title'] ?? '' );
        $content = wp_kses_post( $params['content'] ?? '' );
        $keyword = sanitize_text_field( $params['keyword'] ?? '' );
        $url     = esc_url_raw( $params['url'] ?? '' );

        if ( $post_id && empty( $url ) ) {
            $url = get_permalink( $post_id );
        }

        if ( empty( $title ) && empty( $content ) ) {
            return new WP_Error( 'msh_missing_data', __( 'Title or content is required.', 'msh-seo' ), array( 'status' => 400 ) );
        }

        $inventory = self::build_inventory( $post_id );

        $result = MSH_API::link_suggestions( $title, $content, $keyword, $url, $inventory );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    /**
     * Build the site's internal-link inventory: recently-modified published
     * posts and pages (title + permalink), excluding the current post. Capped
     * so the payload stays small. The MSH brain merges this with its cluster
     * graph and ranks the best targets.
     *
     * @param int $exclude_id Post ID to exclude (the one being edited).
     * @param int $limit      Max inventory items.
     * @return array<int,array{url:string,title:string}>
     */
    private static function build_inventory( $exclude_id = 0, $limit = 150 ) {
        $args = array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'fields'         => 'ids',
        );
        if ( $exclude_id ) {
            $args['post__not_in'] = array( $exclude_id );
        }

        $query = new WP_Query( $args );
        $items = array();
        foreach ( $query->posts as $pid ) {
            $permalink = get_permalink( $pid );
            if ( ! $permalink ) {
                continue;
            }
            $items[] = array(
                'url'   => $permalink,
                'title' => html_entity_decode( get_the_title( $pid ), ENT_QUOTES ),
            );
        }
        return $items;
    }
}
