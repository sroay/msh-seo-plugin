<?php
/**
 * MSH SEO Analysis - Local SEO scoring engine.
 *
 * Runs entirely in PHP with no external API calls.
 * Total max score: 100 points across 14 checks.
 *
 * @package MSH_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSH_SEO_Analysis {

    /**
     * Run all SEO checks on a post.
     *
     * @param int $post_id The post ID to analyze.
     * @return array { score, max_score, checks[] }
     */
    public static function analyze( $post_id ) {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return array( 'score' => 0, 'max_score' => 100, 'checks' => array() );
        }

        $title    = $post->post_title;
        $content  = $post->post_content;
        $slug     = $post->post_name;
        $keyword  = get_post_meta( $post_id, '_msh_focus_keyword', true );
        $meta_desc = get_post_meta( $post_id, '_msh_seo_description', true );

        $plain_content = wp_strip_all_tags( $content );

        $checks = array();

        $checks['keyword_in_title']     = self::check_keyword_in_title( $title, $keyword );
        $checks['keyword_in_h1']        = self::check_keyword_in_h1( $content, $keyword );
        $checks['keyword_in_meta']      = self::check_keyword_in_meta( $meta_desc, $keyword );
        $checks['keyword_in_first_p']   = self::check_keyword_in_first_paragraph( $content, $keyword );
        $checks['keyword_in_url']       = self::check_keyword_in_url( $slug, $keyword );
        $checks['keyword_density']      = self::check_keyword_density( $plain_content, $keyword );
        $checks['heading_hierarchy']    = self::check_heading_hierarchy( $content );
        $checks['internal_links']       = self::check_internal_links( $content );
        $checks['external_links']       = self::check_external_links( $content );
        $checks['image_alt_text']       = self::check_image_alt_text( $content, $keyword );
        $checks['content_length']       = self::check_content_length( $plain_content );
        $checks['title_length']         = self::check_title_length( $title );
        $checks['meta_length']          = self::check_meta_length( $meta_desc );
        $checks['readability']          = self::check_readability( $plain_content );

        $total_score = 0;
        $max_score   = 0;

        foreach ( $checks as $check ) {
            $total_score += $check['score'];
            $max_score   += $check['max'];
        }

        // Persist the score.
        update_post_meta( $post_id, '_msh_seo_score', $total_score );

        return array(
            'score'     => $total_score,
            'max_score' => $max_score,
            'checks'    => $checks,
        );
    }

    /**
     * Check if keyword appears in the title. (10 pts)
     */
    private static function check_keyword_in_title( $title, $keyword ) {
        if ( empty( $keyword ) ) {
            return array( 'pass' => false, 'score' => 0, 'max' => 10, 'message' => 'Set a focus keyword to check title optimization.' );
        }
        $found = stripos( $title, $keyword ) !== false;
        return array(
            'pass'    => $found,
            'score'   => $found ? 10 : 0,
            'max'     => 10,
            'message' => $found
                ? 'Focus keyword found in the title.'
                : 'Focus keyword not found in the title. Add it for better rankings.',
        );
    }

    /**
     * Check if keyword appears in an H1 tag. (8 pts)
     */
    private static function check_keyword_in_h1( $content, $keyword ) {
        if ( empty( $keyword ) ) {
            return array( 'pass' => false, 'score' => 0, 'max' => 8, 'message' => 'Set a focus keyword to check H1 optimization.' );
        }
        preg_match_all( '/<h1[^>]*>(.*?)<\/h1>/is', $content, $matches );
        $found = false;
        if ( ! empty( $matches[1] ) ) {
            foreach ( $matches[1] as $h1 ) {
                if ( stripos( wp_strip_all_tags( $h1 ), $keyword ) !== false ) {
                    $found = true;
                    break;
                }
            }
        }
        return array(
            'pass'    => $found,
            'score'   => $found ? 8 : 0,
            'max'     => 8,
            'message' => $found
                ? 'Focus keyword found in H1 heading.'
                : 'Focus keyword not found in any H1 heading.',
        );
    }

    /**
     * Check if keyword appears in meta description. (8 pts)
     */
    private static function check_keyword_in_meta( $meta_desc, $keyword ) {
        if ( empty( $keyword ) ) {
            return array( 'pass' => false, 'score' => 0, 'max' => 8, 'message' => 'Set a focus keyword to check meta description.' );
        }
        if ( empty( $meta_desc ) ) {
            return array( 'pass' => false, 'score' => 0, 'max' => 8, 'message' => 'No meta description set. Add one with your focus keyword.' );
        }
        $found = stripos( $meta_desc, $keyword ) !== false;
        return array(
            'pass'    => $found,
            'score'   => $found ? 8 : 0,
            'max'     => 8,
            'message' => $found
                ? 'Focus keyword found in meta description.'
                : 'Focus keyword not found in meta description.',
        );
    }

    /**
     * Check if keyword appears in the first paragraph. (6 pts)
     */
    private static function check_keyword_in_first_paragraph( $content, $keyword ) {
        if ( empty( $keyword ) ) {
            return array( 'pass' => false, 'score' => 0, 'max' => 6, 'message' => 'Set a focus keyword.' );
        }
        // Get text from the first <p> tag or first 200 chars.
        preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content, $match );
        $first_p = ! empty( $match[1] ) ? wp_strip_all_tags( $match[1] ) : substr( wp_strip_all_tags( $content ), 0, 200 );
        $found   = stripos( $first_p, $keyword ) !== false;
        return array(
            'pass'    => $found,
            'score'   => $found ? 6 : 0,
            'max'     => 6,
            'message' => $found
                ? 'Focus keyword found in the first paragraph.'
                : 'Add your focus keyword to the first paragraph of your content.',
        );
    }

    /**
     * Check if keyword appears in the URL slug. (5 pts)
     */
    private static function check_keyword_in_url( $slug, $keyword ) {
        if ( empty( $keyword ) ) {
            return array( 'pass' => false, 'score' => 0, 'max' => 5, 'message' => 'Set a focus keyword.' );
        }
        $kw_slug = sanitize_title( $keyword );
        $found   = stripos( $slug, $kw_slug ) !== false;
        return array(
            'pass'    => $found,
            'score'   => $found ? 5 : 0,
            'max'     => 5,
            'message' => $found
                ? 'Focus keyword found in the URL.'
                : 'Consider adding your focus keyword to the URL slug.',
        );
    }

    /**
     * Check keyword density (1-1.5% optimal). (8 pts)
     */
    private static function check_keyword_density( $content, $keyword ) {
        if ( empty( $keyword ) || empty( $content ) ) {
            return array( 'pass' => false, 'score' => 0, 'max' => 8, 'message' => 'Set a focus keyword and add content.' );
        }
        $word_count = str_word_count( $content );
        if ( $word_count < 10 ) {
            return array( 'pass' => false, 'score' => 0, 'max' => 8, 'message' => 'Not enough content to measure keyword density.' );
        }
        $kw_count = substr_count( strtolower( $content ), strtolower( $keyword ) );
        $density  = ( $kw_count / $word_count ) * 100;
        $pass     = $density >= 0.5 && $density <= 2.5;
        $optimal  = $density >= 1.0 && $density <= 1.5;

        if ( $density < 0.5 ) {
            $message = sprintf( 'Keyword density is %.1f%%. Try using your keyword more often (aim for 1-1.5%%).', $density );
        } elseif ( $density > 2.5 ) {
            $message = sprintf( 'Keyword density is %.1f%%. This may be seen as keyword stuffing. Aim for 1-1.5%%.', $density );
        } else {
            $message = sprintf( 'Keyword density is %.1f%%. %s', $density, $optimal ? 'Excellent!' : 'Good range.' );
        }

        return array(
            'pass'    => $pass,
            'score'   => $optimal ? 8 : ( $pass ? 5 : 0 ),
            'max'     => 8,
            'message' => $message,
        );
    }

    /**
     * Check heading hierarchy (proper H2, H3 usage). (5 pts)
     */
    private static function check_heading_hierarchy( $content ) {
        preg_match_all( '/<h([2-6])[^>]*>/i', $content, $matches );
        $count = count( $matches[1] );

        if ( 0 === $count ) {
            return array( 'pass' => false, 'score' => 0, 'max' => 5, 'message' => 'No subheadings found. Use H2 and H3 tags to structure your content.' );
        }

        // Check that headings don't skip levels.
        $valid = true;
        $prev  = 2;
        foreach ( $matches[1] as $level ) {
            $level = intval( $level );
            if ( $level > $prev + 1 ) {
                $valid = false;
                break;
            }
            $prev = $level;
        }

        $score   = $valid ? 5 : 3;
        $message = $valid
            ? sprintf( 'Good heading structure with %d subheadings.', $count )
            : 'Headings skip levels. Use H2 before H3, H3 before H4, etc.';

        return array( 'pass' => $valid, 'score' => $score, 'max' => 5, 'message' => $message );
    }

    /**
     * Check for internal links (at least 2). (5 pts)
     */
    private static function check_internal_links( $content ) {
        $site_url = wp_parse_url( get_site_url(), PHP_URL_HOST );
        preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );

        $internal = 0;
        foreach ( $matches[1] as $url ) {
            $host = wp_parse_url( $url, PHP_URL_HOST );
            if ( empty( $host ) || $host === $site_url ) {
                $internal++;
            }
        }

        $pass = $internal >= 2;
        return array(
            'pass'    => $pass,
            'score'   => $pass ? 5 : ( $internal >= 1 ? 2 : 0 ),
            'max'     => 5,
            'message' => $pass
                ? sprintf( 'Found %d internal links.', $internal )
                : sprintf( 'Only %d internal link(s). Add at least 2 internal links.', $internal ),
        );
    }

    /**
     * Check for external links (at least 1). (3 pts)
     */
    private static function check_external_links( $content ) {
        $site_url = wp_parse_url( get_site_url(), PHP_URL_HOST );
        preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );

        $external = 0;
        foreach ( $matches[1] as $url ) {
            $host = wp_parse_url( $url, PHP_URL_HOST );
            if ( ! empty( $host ) && $host !== $site_url ) {
                $external++;
            }
        }

        $pass = $external >= 1;
        return array(
            'pass'    => $pass,
            'score'   => $pass ? 3 : 0,
            'max'     => 3,
            'message' => $pass
                ? sprintf( 'Found %d external link(s).', $external )
                : 'No external links found. Link to authoritative sources for credibility.',
        );
    }

    /**
     * Check image alt text (keyword presence). (5 pts)
     */
    private static function check_image_alt_text( $content, $keyword ) {
        preg_match_all( '/<img\s[^>]*>/i', $content, $img_matches );
        $img_count = count( $img_matches[0] );

        if ( 0 === $img_count ) {
            return array( 'pass' => false, 'score' => 0, 'max' => 5, 'message' => 'No images found. Add images with descriptive alt text.' );
        }

        $with_alt = 0;
        $kw_in_alt = 0;
        foreach ( $img_matches[0] as $img ) {
            if ( preg_match( '/alt=["\']([^"\']+)["\']/i', $img, $alt_match ) ) {
                $with_alt++;
                if ( ! empty( $keyword ) && stripos( $alt_match[1], $keyword ) !== false ) {
                    $kw_in_alt++;
                }
            }
        }

        $all_have_alt = $with_alt === $img_count;
        $score = 0;
        if ( $all_have_alt ) {
            $score = $kw_in_alt > 0 ? 5 : 3;
        } elseif ( $with_alt > 0 ) {
            $score = 2;
        }

        $message = sprintf( '%d/%d images have alt text.', $with_alt, $img_count );
        if ( ! empty( $keyword ) && $kw_in_alt > 0 ) {
            $message .= ' Keyword found in alt text.';
        } elseif ( ! empty( $keyword ) ) {
            $message .= ' Consider adding your keyword to at least one image alt.';
        }

        return array( 'pass' => $all_have_alt, 'score' => $score, 'max' => 5, 'message' => $message );
    }

    /**
     * Check content length (> 300 words for 8 pts). (8 pts)
     */
    private static function check_content_length( $content ) {
        $word_count = str_word_count( $content );

        if ( $word_count >= 300 ) {
            $score   = 8;
            $message = sprintf( 'Content length: %d words. Good length for SEO.', $word_count );
        } elseif ( $word_count >= 150 ) {
            $score   = 4;
            $message = sprintf( 'Content length: %d words. Aim for at least 300 words.', $word_count );
        } else {
            $score   = 0;
            $message = sprintf( 'Content length: %d words. Too short. Write at least 300 words.', $word_count );
        }

        return array( 'pass' => $word_count >= 300, 'score' => $score, 'max' => 8, 'message' => $message );
    }

    /**
     * Check title length (50-60 characters optimal). (5 pts)
     */
    private static function check_title_length( $title ) {
        $len  = mb_strlen( $title );
        $pass = $len >= 50 && $len <= 60;

        if ( $len < 30 ) {
            $message = sprintf( 'Title is %d characters. Too short. Aim for 50-60 characters.', $len );
            $score   = 0;
        } elseif ( $len < 50 ) {
            $message = sprintf( 'Title is %d characters. Could be longer. Aim for 50-60 characters.', $len );
            $score   = 3;
        } elseif ( $len > 60 ) {
            $message = sprintf( 'Title is %d characters. May be truncated in search results. Aim for 50-60.', $len );
            $score   = 3;
        } else {
            $message = sprintf( 'Title is %d characters. Perfect length!', $len );
            $score   = 5;
        }

        return array( 'pass' => $pass, 'score' => $score, 'max' => 5, 'message' => $message );
    }

    /**
     * Check meta description length (150-160 characters optimal). (5 pts)
     */
    private static function check_meta_length( $meta ) {
        if ( empty( $meta ) ) {
            return array( 'pass' => false, 'score' => 0, 'max' => 5, 'message' => 'No meta description set. Write one between 150-160 characters.' );
        }

        $len  = mb_strlen( $meta );
        $pass = $len >= 150 && $len <= 160;

        if ( $len < 120 ) {
            $message = sprintf( 'Meta description is %d characters. Too short. Aim for 150-160.', $len );
            $score   = 0;
        } elseif ( $len < 150 ) {
            $message = sprintf( 'Meta description is %d characters. Could be longer. Aim for 150-160.', $len );
            $score   = 3;
        } elseif ( $len > 160 ) {
            $message = sprintf( 'Meta description is %d characters. May be truncated. Aim for 150-160.', $len );
            $score   = 3;
        } else {
            $message = sprintf( 'Meta description is %d characters. Perfect length!', $len );
            $score   = 5;
        }

        return array( 'pass' => $pass, 'score' => $score, 'max' => 5, 'message' => $message );
    }

    /**
     * Check readability (sentence length + passive voice heuristic). (9 pts)
     */
    private static function check_readability( $content ) {
        if ( str_word_count( $content ) < 30 ) {
            return array( 'pass' => false, 'score' => 0, 'max' => 9, 'message' => 'Not enough content to assess readability.' );
        }

        // Split into sentences.
        $sentences    = preg_split( '/[.!?]+/', $content, -1, PREG_SPLIT_NO_EMPTY );
        $sentence_count = count( $sentences );

        if ( 0 === $sentence_count ) {
            return array( 'pass' => false, 'score' => 0, 'max' => 9, 'message' => 'Could not detect sentences.' );
        }

        // Average words per sentence.
        $total_words = 0;
        $long_sentences = 0;
        foreach ( $sentences as $s ) {
            $wc = str_word_count( trim( $s ) );
            $total_words += $wc;
            if ( $wc > 20 ) {
                $long_sentences++;
            }
        }
        $avg_sentence_len = $total_words / $sentence_count;

        // Simple passive voice detection.
        $passive_patterns = array( '/\b(is|are|was|were|be|been|being)\s+\w+ed\b/i' );
        $passive_count    = 0;
        foreach ( $passive_patterns as $pattern ) {
            $passive_count += preg_match_all( $pattern, $content );
        }
        $passive_ratio = $sentence_count > 0 ? $passive_count / $sentence_count : 0;

        $score = 9;

        // Deduct for long average sentence length.
        if ( $avg_sentence_len > 25 ) {
            $score -= 4;
        } elseif ( $avg_sentence_len > 20 ) {
            $score -= 2;
        }

        // Deduct for high passive voice usage.
        if ( $passive_ratio > 0.3 ) {
            $score -= 3;
        } elseif ( $passive_ratio > 0.15 ) {
            $score -= 1;
        }

        // Deduct for too many long sentences.
        $long_ratio = $long_sentences / $sentence_count;
        if ( $long_ratio > 0.4 ) {
            $score -= 2;
        }

        $score = max( 0, $score );
        $pass  = $score >= 6;

        $messages = array();
        $messages[] = sprintf( 'Average sentence length: %.0f words.', $avg_sentence_len );
        if ( $passive_count > 0 ) {
            $messages[] = sprintf( '%d possible passive voice instance(s) detected.', $passive_count );
        }
        if ( $long_sentences > 0 ) {
            $messages[] = sprintf( '%d sentence(s) over 20 words.', $long_sentences );
        }

        return array(
            'pass'    => $pass,
            'score'   => $score,
            'max'     => 9,
            'message' => implode( ' ', $messages ),
        );
    }
}
