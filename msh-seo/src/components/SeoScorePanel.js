import { useSelect, useDispatch } from '@wordpress/data';
import { PanelBody, Button, Icon } from '@wordpress/components';
import { useEffect, useState, useMemo } from '@wordpress/element';
import mshFetch from '../msh-fetch';
import UpsellModal from './UpsellModal';

/**
 * Local SEO scoring — mirrors the PHP MSH_SEO_Analysis checks.
 */
function runLocalAnalysis( title, content, slug, keyword, metaDescription, hasFeaturedImage, seoTitle ) {
    const checks = [];
    const plainContent = stripHtml( content );
    const wordCount = countWords( plainContent );

    // 1. Keyword in title (10 pts)
    if ( keyword ) {
        const found = title.toLowerCase().includes( keyword.toLowerCase() );
        checks.push( {
            id: 'keyword_in_title',
            label: 'Keyword in title',
            pass: found,
            score: found ? 10 : 0,
            max: 10,
            message: found
                ? 'Focus keyword found in the title.'
                : 'Focus keyword not found in the title.',
        } );
    } else {
        checks.push( { id: 'keyword_in_title', label: 'Keyword in title', pass: false, score: 0, max: 10, message: 'Set a focus keyword.' } );
    }

    // 2. Keyword in H1 (8 pts)
    // WordPress renders the post title as the H1 on the front end,
    // so treat the post title as an H1 in addition to any explicit h1 tags in content.
    if ( keyword ) {
        let found = title.toLowerCase().includes( keyword.toLowerCase() );
        if ( ! found ) {
            const h1Regex = /<h1[^>]*>(.*?)<\/h1>/gi;
            let match;
            while ( ( match = h1Regex.exec( content ) ) !== null ) {
                if ( stripHtml( match[ 1 ] ).toLowerCase().includes( keyword.toLowerCase() ) ) {
                    found = true;
                    break;
                }
            }
        }
        checks.push( {
            id: 'keyword_in_h1',
            label: 'Keyword in H1',
            pass: found,
            score: found ? 8 : 0,
            max: 8,
            message: found ? 'Focus keyword found in H1.' : 'Focus keyword not in any H1 heading.',
        } );
    } else {
        checks.push( { id: 'keyword_in_h1', label: 'Keyword in H1', pass: false, score: 0, max: 8, message: 'Set a focus keyword.' } );
    }

    // 3. Keyword in meta description (8 pts)
    if ( keyword ) {
        const hasMeta = metaDescription && metaDescription.length > 0;
        const found = hasMeta && metaDescription.toLowerCase().includes( keyword.toLowerCase() );
        checks.push( {
            id: 'keyword_in_meta',
            label: 'Keyword in meta description',
            pass: found,
            score: found ? 8 : 0,
            max: 8,
            message: ! hasMeta
                ? 'No meta description set.'
                : found
                    ? 'Focus keyword found in meta description.'
                    : 'Focus keyword not in meta description.',
        } );
    } else {
        checks.push( { id: 'keyword_in_meta', label: 'Keyword in meta description', pass: false, score: 0, max: 8, message: 'Set a focus keyword.' } );
    }

    // 4. Keyword in first paragraph (6 pts)
    if ( keyword ) {
        const pMatch = content.match( /<p[^>]*>(.*?)<\/p>/is );
        const firstP = pMatch ? stripHtml( pMatch[ 1 ] ) : plainContent.substring( 0, 200 );
        const found = firstP.toLowerCase().includes( keyword.toLowerCase() );
        checks.push( {
            id: 'keyword_in_first_p',
            label: 'Keyword in first paragraph',
            pass: found,
            score: found ? 6 : 0,
            max: 6,
            message: found ? 'Focus keyword in first paragraph.' : 'Add keyword to the first paragraph.',
        } );
    } else {
        checks.push( { id: 'keyword_in_first_p', label: 'Keyword in first paragraph', pass: false, score: 0, max: 6, message: 'Set a focus keyword.' } );
    }

    // 5. Keyword in URL (5 pts)
    if ( keyword ) {
        const kwSlug = keyword.toLowerCase().replace( /\s+/g, '-' ).replace( /[^a-z0-9-]/g, '' );
        const found = slug.toLowerCase().includes( kwSlug );
        checks.push( {
            id: 'keyword_in_url',
            label: 'Keyword in URL',
            pass: found,
            score: found ? 5 : 0,
            max: 5,
            message: found ? 'Focus keyword in URL.' : 'Consider adding keyword to the URL slug.',
        } );
    } else {
        checks.push( { id: 'keyword_in_url', label: 'Keyword in URL', pass: false, score: 0, max: 5, message: 'Set a focus keyword.' } );
    }

    // 6. Keyword density (8 pts)
    // For long-tail keywords (4+ words), lower the density thresholds since
    // repeating a long phrase many times is unnatural.
    if ( keyword && wordCount > 10 ) {
        const kwWords = keyword.trim().split( /\s+/ ).length;
        const kwCount = ( plainContent.toLowerCase().match( new RegExp( escapeRegExp( keyword.toLowerCase() ), 'g' ) ) || [] ).length;
        const density = ( kwCount / wordCount ) * 100;

        // Adjust thresholds for long-tail keywords
        let optimalMin, optimalMax, passMin, passMax;
        if ( kwWords >= 5 ) {
            optimalMin = 0.15; optimalMax = 0.6;
            passMin = 0.08; passMax = 1.5;
        } else if ( kwWords >= 3 ) {
            optimalMin = 0.4; optimalMax = 1.0;
            passMin = 0.2; passMax = 2.0;
        } else {
            optimalMin = 1.0; optimalMax = 1.5;
            passMin = 0.5; passMax = 2.5;
        }

        const optimal = density >= optimalMin && density <= optimalMax;
        const pass = density >= passMin && density <= passMax;
        const target = kwWords >= 5 ? '0.15-0.6%' : kwWords >= 3 ? '0.4-1.0%' : '1-1.5%';
        checks.push( {
            id: 'keyword_density',
            label: 'Keyword density',
            pass,
            score: optimal ? 8 : pass ? 5 : 0,
            max: 8,
            message: 'Keyword density: ' + density.toFixed( 1 ) + '%. ' + ( optimal ? 'Excellent!' : pass ? 'Good range.' : 'Aim for ' + target + '.' ),
        } );
    } else {
        checks.push( { id: 'keyword_density', label: 'Keyword density', pass: false, score: 0, max: 8, message: 'Set keyword and add more content.' } );
    }

    // 7. Heading hierarchy (5 pts)
    const headings = [ ...content.matchAll( /<h([2-6])[^>]*>/gi ) ].map( ( m ) => parseInt( m[ 1 ] ) );
    if ( headings.length === 0 ) {
        checks.push( { id: 'heading_hierarchy', label: 'Heading structure', pass: false, score: 0, max: 5, message: 'No subheadings found. Use H2/H3 tags.' } );
    } else {
        let valid = true;
        let prev = 2;
        for ( const level of headings ) {
            if ( level > prev + 1 ) {
                valid = false;
                break;
            }
            prev = level;
        }
        checks.push( {
            id: 'heading_hierarchy',
            label: 'Heading structure',
            pass: valid,
            score: valid ? 5 : 3,
            max: 5,
            message: valid ? 'Good structure with ' + headings.length + ' subheadings.' : 'Headings skip levels.',
        } );
    }

    // 8. Internal links (5 pts)
    const allLinks = [ ...content.matchAll( /<a\s[^>]*href=["']([^"']+)["'][^>]*>/gi ) ];
    const siteHost = window.location.hostname;
    let internalCount = 0;
    let externalCount = 0;
    for ( const lm of allLinks ) {
        try {
            const url = new URL( lm[ 1 ], window.location.origin );
            if ( url.hostname === siteHost || lm[ 1 ].startsWith( '/' ) ) {
                internalCount++;
            } else {
                externalCount++;
            }
        } catch ( e ) {
            if ( lm[ 1 ].startsWith( '/' ) || lm[ 1 ].startsWith( '#' ) ) {
                internalCount++;
            }
        }
    }
    checks.push( {
        id: 'internal_links',
        label: 'Internal links',
        pass: internalCount >= 2,
        score: internalCount >= 2 ? 5 : internalCount >= 1 ? 2 : 0,
        max: 5,
        message: internalCount >= 2 ? internalCount + ' internal links found.' : 'Only ' + internalCount + ' internal link(s). Add at least 2.',
    } );

    // 9. External links (3 pts)
    checks.push( {
        id: 'external_links',
        label: 'External links',
        pass: externalCount >= 1,
        score: externalCount >= 1 ? 3 : 0,
        max: 3,
        message: externalCount >= 1 ? externalCount + ' external link(s).' : 'No external links. Link to authoritative sources.',
    } );

    // 10. Image alt text (5 pts)
    // Check both inline images and the featured image (post thumbnail).
    const images = [ ...content.matchAll( /<img\s[^>]*>/gi ) ];
    const totalImages = images.length + ( hasFeaturedImage ? 1 : 0 );
    if ( totalImages === 0 ) {
        checks.push( { id: 'image_alt', label: 'Image alt text', pass: false, score: 0, max: 5, message: 'No images found. Add images with alt text.' } );
    } else {
        let withAlt = 0;
        let kwInAlt = 0;
        // Featured image counts as having alt text (WP manages it separately)
        if ( hasFeaturedImage ) {
            withAlt++;
        }
        for ( const img of images ) {
            const altMatch = img[ 0 ].match( /alt=["']([^"']+)["']/i );
            if ( altMatch ) {
                withAlt++;
                if ( keyword && altMatch[ 1 ].toLowerCase().includes( keyword.toLowerCase() ) ) {
                    kwInAlt++;
                }
            }
        }
        const allHaveAlt = withAlt >= totalImages;
        const score = allHaveAlt ? ( kwInAlt > 0 || hasFeaturedImage ? 5 : 3 ) : withAlt > 0 ? 2 : 0;
        checks.push( {
            id: 'image_alt',
            label: 'Image alt text',
            pass: allHaveAlt,
            score,
            max: 5,
            message: totalImages + ' image(s) found.' + ( allHaveAlt ? ' Good alt text coverage.' : ' Add alt text to all images.' ),
        } );
    }

    // 11. Content length (8 pts)
    const clPass = wordCount >= 300;
    checks.push( {
        id: 'content_length',
        label: 'Content length',
        pass: clPass,
        score: clPass ? 8 : wordCount >= 150 ? 4 : 0,
        max: 8,
        message: wordCount + ' words. ' + ( clPass ? 'Good length.' : 'Aim for 300+ words.' ),
    } );

    // 12. Title length (5 pts) — use SEO title if set, otherwise post title
    const effectiveTitle = seoTitle || title;
    const titleLen = effectiveTitle.length;
    const titlePass = titleLen >= 50 && titleLen <= 60;
    checks.push( {
        id: 'title_length',
        label: 'Title length',
        pass: titlePass,
        score: titlePass ? 5 : titleLen >= 30 ? 3 : 0,
        max: 5,
        message: titleLen + ' chars. ' + ( titlePass ? 'Perfect!' : 'Aim for 50-60 characters.' ),
    } );

    // 13. Meta description length (5 pts)
    const metaLen = metaDescription ? metaDescription.length : 0;
    if ( metaLen === 0 ) {
        checks.push( { id: 'meta_length', label: 'Meta description length', pass: false, score: 0, max: 5, message: 'No meta description. Write 150-160 chars.' } );
    } else {
        const metaPass = metaLen >= 150 && metaLen <= 160;
        checks.push( {
            id: 'meta_length',
            label: 'Meta description length',
            pass: metaPass,
            score: metaPass ? 5 : metaLen >= 120 ? 3 : 0,
            max: 5,
            message: metaLen + ' chars. ' + ( metaPass ? 'Perfect!' : 'Aim for 150-160 characters.' ),
        } );
    }

    // 14. Readability (9 pts)
    if ( wordCount < 30 ) {
        checks.push( { id: 'readability', label: 'Readability', pass: false, score: 0, max: 9, message: 'Not enough content to assess.' } );
    } else {
        const sentences = plainContent.split( /[.!?]+/ ).filter( ( s ) => s.trim().length > 0 );
        const sentenceCount = sentences.length;
        let longSentences = 0;
        let totalWords = 0;
        for ( const s of sentences ) {
            const wc = countWords( s );
            totalWords += wc;
            if ( wc > 20 ) longSentences++;
        }
        const avgLen = sentenceCount > 0 ? totalWords / sentenceCount : 0;
        let readScore = 9;
        if ( avgLen > 25 ) readScore -= 4;
        else if ( avgLen > 20 ) readScore -= 2;
        if ( longSentences / sentenceCount > 0.4 ) readScore -= 2;
        readScore = Math.max( 0, readScore );
        checks.push( {
            id: 'readability',
            label: 'Readability',
            pass: readScore >= 6,
            score: readScore,
            max: 9,
            message: 'Avg sentence: ' + avgLen.toFixed( 0 ) + ' words. ' + longSentences + ' long sentence(s).',
        } );
    }

    const totalScore = checks.reduce( ( sum, c ) => sum + c.score, 0 );
    const maxScore = checks.reduce( ( sum, c ) => sum + c.max, 0 );

    return { score: totalScore, maxScore, checks };
}

function stripHtml( html ) {
    const tmp = document.createElement( 'div' );
    tmp.innerHTML = html;
    return tmp.textContent || tmp.innerText || '';
}

function countWords( text ) {
    return text.trim().split( /\s+/ ).filter( ( w ) => w.length > 0 ).length;
}

function escapeRegExp( string ) {
    return string.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

function getScoreColor( score ) {
    if ( score < 40 ) return '#d63638';
    if ( score < 70 ) return '#dba617';
    return '#00a32a';
}

export default function SeoScorePanel() {
    const { title, content, slug, hasFeaturedImage } = useSelect( ( select ) => {
        const editor = select( 'core/editor' );
        return {
            title: editor.getEditedPostAttribute( 'title' ) || '',
            content: editor.getEditedPostAttribute( 'content' ) || '',
            slug: editor.getEditedPostAttribute( 'slug' ) || '',
            hasFeaturedImage: !! editor.getEditedPostAttribute( 'featured_media' ),
        };
    } );

    const meta = useSelect( ( select ) => {
        return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
    } );

    const { editPost } = useDispatch( 'core/editor' );

    const keyword = meta._msh_seo_focus_keyword || '';
    const metaDescription = meta._msh_seo_description || '';
    const seoTitle = meta._msh_seo_title || '';

    const [ expanded, setExpanded ] = useState( false );
    const [ aiLoading, setAiLoading ] = useState( false );
    const [ aiResult, setAiResult ] = useState( null );
    const [ showUpsell, setShowUpsell ] = useState( false );

    const analysis = useMemo(
        () => runLocalAnalysis( title, content, slug, keyword, metaDescription, hasFeaturedImage, seoTitle ),
        [ title, content, slug, keyword, metaDescription, hasFeaturedImage, seoTitle ]
    );

    // Update score meta whenever analysis changes.
    useEffect( () => {
        if ( analysis.score !== meta._msh_seo_score ) {
            editPost( { meta: { _msh_seo_score: analysis.score } } );
        }
    }, [ analysis.score ] );

    const scorePercent = analysis.maxScore > 0 ? Math.round( ( analysis.score / analysis.maxScore ) * 100 ) : 0;
    const color = getScoreColor( scorePercent );

    const circumference = 2 * Math.PI * 40;
    const strokeOffset = circumference - ( scorePercent / 100 ) * circumference;

    async function handleAiScore() {
        if ( ! window.mshSeoData?.isConnected ) return;
        setAiLoading( true );
        try {
            const result = await mshFetch( 'analyze', {
                title,
                content,
                keyword,
                url: window.location.href,
            } );
            if ( result && ( result.seo_score !== undefined || result.aeo_score !== undefined ) ) {
                setAiResult( result );
            }
        } catch ( err ) {
            // Silently fail - user can retry.
        }
        setAiLoading( false );
    }

    return (
        <>
        <PanelBody title="SEO Score" initialOpen={ true }>
            <div style={ { textAlign: 'center', padding: '16px 0' } }>
                <svg width="100" height="100" viewBox="0 0 100 100">
                    <circle
                        cx="50" cy="50" r="40"
                        fill="none"
                        stroke="#e0e0e0"
                        strokeWidth="8"
                    />
                    <circle
                        cx="50" cy="50" r="40"
                        fill="none"
                        stroke={ color }
                        strokeWidth="8"
                        strokeDasharray={ circumference }
                        strokeDashoffset={ strokeOffset }
                        strokeLinecap="round"
                        transform="rotate(-90 50 50)"
                        style={ { transition: 'stroke-dashoffset 0.5s ease' } }
                    />
                    <text
                        x="50" y="50"
                        textAnchor="middle"
                        dominantBaseline="central"
                        fontSize="22"
                        fontWeight="bold"
                        fill={ color }
                    >
                        { scorePercent }
                    </text>
                </svg>
                <div style={ { fontSize: '13px', color: '#757575', marginTop: '4px' } }>
                    { analysis.score } / { analysis.maxScore } points
                </div>
            </div>

            <Button
                variant="link"
                onClick={ () => setExpanded( ! expanded ) }
                style={ { width: '100%', justifyContent: 'space-between', padding: '8px 0' } }
            >
                { expanded ? 'Hide checks' : 'Show all checks' }
                <Icon icon={ expanded ? 'arrow-up-alt2' : 'arrow-down-alt2' } />
            </Button>

            { expanded && (
                <div style={ { fontSize: '13px' } }>
                    { analysis.checks.map( ( check ) => (
                        <div
                            key={ check.id }
                            style={ {
                                display: 'flex',
                                alignItems: 'flex-start',
                                gap: '8px',
                                padding: '6px 0',
                                borderBottom: '1px solid #f0f0f0',
                            } }
                        >
                            <span style={ { color: check.pass ? '#00a32a' : '#d63638', flexShrink: 0 } }>
                                { check.pass ? '\u2713' : '\u2717' }
                            </span>
                            <div>
                                <strong>{ check.label }</strong>
                                <span style={ { color: '#757575', marginLeft: '4px' } }>
                                    ({ check.score }/{ check.max })
                                </span>
                                <div style={ { color: '#757575', marginTop: '2px' } }>
                                    { check.message }
                                </div>
                            </div>
                        </div>
                    ) ) }
                </div>
            ) }

            { aiResult && (
                <div style={ {
                    marginTop: '12px',
                    padding: '12px',
                    background: '#f0f6fc',
                    borderRadius: '6px',
                    fontSize: '13px',
                    borderLeft: '3px solid #0073aa',
                } }>
                    <div style={ { fontWeight: '600', marginBottom: '8px' } }>AI Analysis</div>
                    <div style={ { display: 'flex', gap: '16px', marginBottom: '8px' } }>
                        <div>
                            <span style={ { color: '#757575' } }>SEO: </span>
                            <strong style={ { color: getScoreColor( aiResult.seo_score ) } }>
                                { aiResult.seo_score }/100
                            </strong>
                        </div>
                        <div>
                            <span style={ { color: '#757575' } }>AEO: </span>
                            <strong style={ { color: getScoreColor( aiResult.aeo_score ) } }>
                                { aiResult.aeo_score }/100
                            </strong>
                        </div>
                    </div>
                    { aiResult.suggestions && aiResult.suggestions.length > 0 && (
                        <div>
                            <div style={ { fontWeight: '600', marginBottom: '4px', fontSize: '12px' } }>Suggestions:</div>
                            <ul style={ { margin: '0', paddingLeft: '16px', fontSize: '12px', color: '#555' } }>
                                { aiResult.suggestions.map( ( s, i ) => (
                                    <li key={ i } style={ { marginBottom: '3px' } }>{ s }</li>
                                ) ) }
                            </ul>
                        </div>
                    ) }
                </div>
            ) }

            <div style={ { marginTop: '12px' } }>
                { window.mshSeoData?.isConnected ? (
                    <Button
                        variant="secondary"
                        onClick={ handleAiScore }
                        isBusy={ aiLoading }
                        disabled={ aiLoading }
                        style={ { width: '100%' } }
                    >
                        { aiLoading ? 'Analyzing...' : ( aiResult ? 'Re-analyze with AI' : 'Get AI Score' ) }
                    </Button>
                ) : (
                    <>
                        <Button
                            variant="secondary"
                            onClick={ () => setShowUpsell( true ) }
                            style={ { width: '100%' } }
                        >
                            { '\uD83D\uDD12 Get AI Score' }
                        </Button>
                        <p style={ { fontSize: '11px', color: '#757575', textAlign: 'center', marginTop: '6px' } }>
                            Connect to MSH for AI-powered analysis
                        </p>
                    </>
                ) }
            </div>
        </PanelBody>
        <UpsellModal isOpen={ showUpsell } onClose={ () => setShowUpsell( false ) } />
        </>
    );
}
