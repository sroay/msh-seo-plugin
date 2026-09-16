import { useSelect, select, dispatch } from '@wordpress/data';
import { PanelBody, Button, Spinner, Icon } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { createBlock } from '@wordpress/blocks';
import mshFetch from '../msh-fetch';

/**
 * Internal Link Mesh panel — the plugin's moat feature.
 *
 * Asks the MSH brain (which holds the site-wide topical cluster graph) what the
 * current post should link to, then inserts the link with one click: it wraps
 * the suggested anchor phrase where it already appears in the post, or appends a
 * linked paragraph when it doesn't.
 */

function escapeRegExp( string ) {
    return string.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

function scoreColor( score ) {
    if ( score >= 0.75 ) return '#00a32a';
    if ( score >= 0.45 ) return '#dba617';
    return '#757575';
}

/** Flatten top-level + one level of inner blocks so grouped content is reachable. */
function flattenBlocks( blocks ) {
    const out = [];
    for ( const b of blocks ) {
        out.push( b );
        if ( b.innerBlocks && b.innerBlocks.length ) {
            for ( const ib of b.innerBlocks ) out.push( ib );
        }
    }
    return out;
}

/**
 * Insert a link for a suggestion. Returns 'wrapped' if it linked an existing
 * phrase, 'appended' if it added a new paragraph.
 */
function insertLink( sug ) {
    const editor = dispatch( 'core/block-editor' );
    const blocks = flattenBlocks( select( 'core/block-editor' ).getBlocks() );
    const anchorLc = sug.anchor.toLowerCase();
    const urlLc = sug.url.toLowerCase();
    // Global regex + callback so we can reject matches that sit inside an
    // EXISTING <a>…</a> (wrapping those would produce invalid nested links).
    const re = new RegExp( '(?![^<]*>)(' + escapeRegExp( sug.anchor ) + ')', 'gi' );

    for ( const b of blocks ) {
        const raw = b.attributes && b.attributes.content != null ? String( b.attributes.content ) : '';
        if ( ! raw ) continue;
        const lc = raw.toLowerCase();
        if ( lc.includes( 'href="' + urlLc ) ) continue; // already linked to this URL
        if ( ! lc.includes( anchorLc ) ) continue;
        let wrappedOne = false;
        const next = raw.replace( re, ( match, p1, offset ) => {
            if ( wrappedOne ) return match;
            const before = lc.slice( 0, offset );
            const lastOpen = Math.max( before.lastIndexOf( '<a ' ), before.lastIndexOf( '<a>' ) );
            const lastClose = before.lastIndexOf( '</a' );
            if ( lastOpen > lastClose ) return match; // inside an existing link — skip
            wrappedOne = true;
            return '<a href="' + sug.url + '">' + p1 + '</a>';
        } );
        if ( wrappedOne && next !== raw ) {
            editor.updateBlockAttributes( b.clientId, { content: next } );
            return 'wrapped';
        }
    }

    // Anchor phrase not found in the body — append a linked paragraph.
    const block = createBlock( 'core/paragraph', {
        content: '<a href="' + sug.url + '">' + sug.anchor + '</a>',
    } );
    editor.insertBlocks( block );
    return 'appended';
}

export default function LinkMeshPanel() {
    const { postId, title, content, permalink } = useSelect( ( sel ) => {
        const editor = sel( 'core/editor' );
        return {
            postId: editor.getCurrentPostId(),
            title: editor.getEditedPostAttribute( 'title' ) || '',
            content: editor.getEditedPostAttribute( 'content' ) || '',
            permalink: editor.getPermalink ? editor.getPermalink() : '',
        };
    }, [] );

    const meta = useSelect( ( sel ) => sel( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {}, [] );
    const keyword = meta._msh_seo_focus_keyword || '';

    const [ loading, setLoading ] = useState( false );
    const [ suggestions, setSuggestions ] = useState( null );
    const [ error, setError ] = useState( '' );
    const [ inserted, setInserted ] = useState( {} );

    const connected = !! window.mshSeoData?.isConnected;

    async function findLinks() {
        setLoading( true );
        setError( '' );
        try {
            const result = await mshFetch( 'link-suggestions', { post_id: postId, title, content, keyword, url: permalink } );
            setSuggestions( Array.isArray( result?.suggestions ) ? result.suggestions : [] );
        } catch ( err ) {
            setError( err?.message || 'Could not fetch suggestions.' );
            setSuggestions( null );
        }
        setLoading( false );
    }

    function handleInsert( sug, idx ) {
        const mode = insertLink( sug );
        setInserted( ( prev ) => ( { ...prev, [ idx ]: mode } ) );
    }

    if ( ! connected ) {
        return (
            <PanelBody title="Internal Link Mesh" initialOpen={ false }>
                <p style={ { fontSize: '13px', color: '#757575', margin: '0 0 10px' } }>
                    Connect to MSH to unlock the internal link mesh — AI-ranked internal links
                    computed from your whole site's topic clusters.
                </p>
                <Button variant="secondary" href={ window.mshSeoData?.settingsUrl } style={ { width: '100%' } }>
                    Connect MSH
                </Button>
            </PanelBody>
        );
    }

    return (
        <PanelBody title="Internal Link Mesh" initialOpen={ false }>
            <p style={ { fontSize: '12px', color: '#757575', margin: '0 0 10px' } }>
                Ranked internal links from your site's topic graph. Insert with one click.
            </p>

            <Button
                variant="primary"
                onClick={ findLinks }
                disabled={ loading }
                style={ { width: '100%', justifyContent: 'center' } }
            >
                { loading ? 'Finding links…' : ( suggestions ? 'Refresh suggestions' : 'Find internal links' ) }
            </Button>

            { loading && (
                <div style={ { textAlign: 'center', padding: '16px 0' } }><Spinner /></div>
            ) }

            { error && (
                <p style={ { fontSize: '12px', color: '#d63638', marginTop: '10px' } }>{ error }</p>
            ) }

            { suggestions && suggestions.length === 0 && ! loading && (
                <p style={ { fontSize: '13px', color: '#757575', marginTop: '12px' } }>
                    No internal-link opportunities found yet. Publish more posts in this topic
                    cluster and check again.
                </p>
            ) }

            { suggestions && suggestions.length > 0 && (
                <div style={ { marginTop: '12px', display: 'flex', flexDirection: 'column', gap: '8px' } }>
                    { suggestions.map( ( sug, idx ) => (
                        <div
                            key={ idx }
                            style={ {
                                border: '1px solid #e0e0e0',
                                borderRadius: '8px',
                                padding: '10px',
                                background: '#fff',
                            } }
                        >
                            <div style={ { display: 'flex', alignItems: 'center', gap: '6px', marginBottom: '2px' } }>
                                <span
                                    style={ {
                                        width: '8px', height: '8px', borderRadius: '50%',
                                        background: scoreColor( sug.score ), flexShrink: 0,
                                    } }
                                />
                                <strong style={ { fontSize: '13px', lineHeight: 1.3 } }>{ sug.title }</strong>
                            </div>
                            <div style={ { fontSize: '11px', color: '#757575', marginBottom: '8px' } }>
                                { sug.reason }
                                { sug.in_content && (
                                    <span style={ { color: '#00a32a', marginLeft: '4px' } }>· anchor in text</span>
                                ) }
                            </div>
                            <div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px' } }>
                                <code style={ {
                                    fontSize: '11px', background: '#f0f0f0', padding: '2px 6px',
                                    borderRadius: '4px', overflow: 'hidden', textOverflow: 'ellipsis',
                                    whiteSpace: 'nowrap', maxWidth: '55%',
                                } }>{ sug.anchor }</code>
                                { inserted[ idx ] ? (
                                    <span style={ { fontSize: '12px', color: '#00a32a', display: 'flex', alignItems: 'center', gap: '2px' } }>
                                        <Icon icon="yes" size={ 16 } />
                                        { inserted[ idx ] === 'wrapped' ? 'Linked' : 'Added' }
                                    </span>
                                ) : (
                                    <Button
                                        variant="secondary"
                                        isSmall
                                        onClick={ () => handleInsert( sug, idx ) }
                                    >
                                        Insert
                                    </Button>
                                ) }
                            </div>
                        </div>
                    ) ) }
                </div>
            ) }
        </PanelBody>
    );
}
