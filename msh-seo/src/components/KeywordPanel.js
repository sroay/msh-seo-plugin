import { useSelect, useDispatch } from '@wordpress/data';
import { PanelBody, TextControl, Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import mshFetch from '../msh-fetch';

function getDifficultyColor( difficulty ) {
    if ( difficulty <= 30 ) return '#00a32a';
    if ( difficulty <= 60 ) return '#dba617';
    return '#d63638';
}

function getIntentBadge( intent ) {
    const colors = {
        informational: { bg: '#e7f5ff', text: '#1971c2' },
        commercial: { bg: '#fff9db', text: '#e67700' },
        transactional: { bg: '#d3f9d8', text: '#2b8a3e' },
        navigational: { bg: '#f3d9fa', text: '#862e9c' },
    };
    const style = colors[ intent ] || colors.informational;
    return (
        <span style={ {
            display: 'inline-block',
            padding: '2px 8px',
            borderRadius: '10px',
            fontSize: '11px',
            fontWeight: '600',
            background: style.bg,
            color: style.text,
            textTransform: 'capitalize',
        } }>
            { intent }
        </span>
    );
}

export default function KeywordPanel() {
    const { editPost } = useDispatch( 'core/editor' );

    const meta = useSelect( ( select ) => {
        return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
    } );

    const keyword = meta._msh_seo_focus_keyword || '';

    const [ loading, setLoading ] = useState( false );
    const [ keywordData, setKeywordData ] = useState( null );

    function setKeyword( value ) {
        editPost( { meta: { _msh_seo_focus_keyword: value } } );
        // Clear cached data when keyword changes.
        setKeywordData( null );
    }

    async function fetchKeywordData() {
        if ( ! keyword || ! window.mshSeoData?.isConnected ) return;
        setLoading( true );
        try {
            const result = await mshFetch( 'analyze', {
                title: '',
                content: '',
                keyword,
                url: '',
            } );
            if ( result.keyword_data ) {
                setKeywordData( result.keyword_data );
            }
        } catch ( err ) {
            // Silent fail.
        }
        setLoading( false );
    }

    return (
        <PanelBody title="Focus Keyword" initialOpen={ true }>
            <TextControl
                value={ keyword }
                onChange={ setKeyword }
                placeholder="Enter focus keyword..."
            />

            { keyword && window.mshSeoData?.isConnected && ! keywordData && (
                <Button
                    variant="secondary"
                    onClick={ fetchKeywordData }
                    isBusy={ loading }
                    disabled={ loading }
                    style={ { width: '100%', marginTop: '4px' } }
                >
                    { loading ? 'Loading...' : 'Get Keyword Data' }
                </Button>
            ) }

            { keywordData && (
                <div style={ {
                    marginTop: '12px',
                    padding: '12px',
                    background: '#f9f9f9',
                    borderRadius: '6px',
                    fontSize: '13px',
                } }>
                    <div style={ { display: 'flex', justifyContent: 'space-between', marginBottom: '8px' } }>
                        <span style={ { color: '#757575' } }>Search Volume</span>
                        <strong>{ keywordData.volume?.toLocaleString() || 'N/A' }</strong>
                    </div>

                    <div style={ { marginBottom: '8px' } }>
                        <div style={ { display: 'flex', justifyContent: 'space-between', marginBottom: '4px' } }>
                            <span style={ { color: '#757575' } }>Keyword Difficulty</span>
                            <strong>{ keywordData.difficulty ?? 'N/A' }</strong>
                        </div>
                        { keywordData.difficulty !== undefined && (
                            <div style={ {
                                height: '6px',
                                borderRadius: '3px',
                                background: '#e0e0e0',
                                overflow: 'hidden',
                            } }>
                                <div style={ {
                                    width: keywordData.difficulty + '%',
                                    height: '100%',
                                    borderRadius: '3px',
                                    background: getDifficultyColor( keywordData.difficulty ),
                                    transition: 'width 0.3s ease',
                                } } />
                            </div>
                        ) }
                    </div>

                    <div style={ { display: 'flex', justifyContent: 'space-between', marginBottom: '8px' } }>
                        <span style={ { color: '#757575' } }>CPC</span>
                        <strong>{ keywordData.cpc ? '$' + keywordData.cpc.toFixed( 2 ) : 'N/A' }</strong>
                    </div>

                    { keywordData.intent && (
                        <div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
                            <span style={ { color: '#757575' } }>Search Intent</span>
                            { getIntentBadge( keywordData.intent ) }
                        </div>
                    ) }
                </div>
            ) }

            { ! keyword && (
                <p style={ { fontSize: '12px', color: '#757575', margin: '8px 0 0' } }>
                    Set a focus keyword to track SEO optimization for this post.
                </p>
            ) }
        </PanelBody>
    );
}
