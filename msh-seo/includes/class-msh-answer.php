<?php
/**
 * MSH Answer — quotable "Answer Engine" block.
 *
 * Renders a short, direct-answer callout ([msh_answer]…[/msh_answer]) marked
 * with the `.msh-answer` class. This is the unit AI answer engines (Google AI
 * Overviews, ChatGPT/Perplexity) love to quote, and it's what the Speakable
 * schema (class-msh-schema.php) points voice assistants at. Keep it to ~40-60
 * words for maximum citability.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_Answer {

    /**
     * Register shortcode, block, and styles.
     */
    public static function init() {
        add_shortcode( 'msh_answer', array( __CLASS__, 'render' ) );
        add_action( 'init', array( __CLASS__, 'register_block' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
    }

    /**
     * Register a dynamic block that shares the shortcode renderer.
     */
    public static function register_block() {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }
        register_block_type( 'msh-seo/answer', array(
            'render_callback' => array( __CLASS__, 'render_block' ),
            'attributes'      => array(
                'label' => array( 'type' => 'string', 'default' => 'Quick answer' ),
                'text'  => array( 'type' => 'string', 'default' => '' ),
            ),
        ) );
    }

    /**
     * Block render callback.
     */
    public static function render_block( $attributes ) {
        $label = isset( $attributes['label'] ) ? $attributes['label'] : 'Quick answer';
        $text  = isset( $attributes['text'] ) ? $attributes['text'] : '';
        return self::render( array( 'label' => $label ), $text );
    }

    /**
     * Render the answer callout.
     *
     * @param array       $atts    { label }.
     * @param string|null $content The answer text (shortcode inner content).
     * @return string HTML.
     */
    public static function render( $atts = array(), $content = null ) {
        $atts  = shortcode_atts( array( 'label' => 'Quick answer' ), $atts, 'msh_answer' );
        $text  = trim( (string) $content );
        if ( '' === $text ) {
            return '';
        }
        // Allow inline formatting + links inside the answer, nothing more.
        $text  = do_shortcode( $text );
        $clean = wp_kses( $text, array(
            'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(),
            'a'      => array( 'href' => array(), 'title' => array(), 'rel' => array() ),
            'code'   => array(),
        ) );

        $label = esc_html( $atts['label'] );

        return '<div class="msh-answer"><span class="msh-answer__label">' . $label . '</span> <span class="msh-answer__text">' . $clean . '</span></div>';
    }

    /**
     * Enqueue the answer-box styles on singular views.
     */
    public static function enqueue_styles() {
        if ( ! is_singular() ) {
            return;
        }
        wp_register_style( 'msh-answer', false, array(), MSH_SEO_VERSION );
        wp_enqueue_style( 'msh-answer' );
        wp_add_inline_style( 'msh-answer',
            '.msh-answer{margin:1.5em 0;padding:16px 18px;background:#f6f9ff;border-left:4px solid #ff5c8a;'
            . 'border-radius:0 10px 10px 0;font-size:1.05em;line-height:1.55}'
            . '.msh-answer__label{display:block;font-size:.72em;font-weight:700;letter-spacing:.05em;'
            . 'text-transform:uppercase;color:#ff5c8a;margin-bottom:4px}'
            . '.msh-answer__text{color:#1a1a1a}'
        );
    }
}
