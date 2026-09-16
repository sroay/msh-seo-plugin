import { useSelect, useDispatch } from '@wordpress/data';
import { PanelBody, TextControl, TextareaControl, Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import mshFetch from '../msh-fetch';

function getCharCountColor( len, min, max ) {
    if ( len === 0 ) return '#757575';
    if ( len >= min && len <= max ) return '#00a32a';
    if ( len > max ) return '#d63638';
    return '#dba617';
}

export default function MetaPanel() {
    const { editPost } = useDispatch( 'core/editor' );

    const { title, content, slug, meta } = useSelect( ( select ) => {
        const editor = select( 'core/editor' );
        return {
            title: editor.getEditedPostAttribute( 'title' ) || '',
            content: editor.getEditedPostAttribute( 'content' ) || '',
            slug: editor.getEditedPostAttribute( 'slug' ) || '',
            meta: editor.getEditedPostAttribute( 'meta' ) || {},
        };
    } );

    const seoTitle = meta._msh_seo_title || '';
    const seoDesc = meta._msh_seo_description || '';
    const keyword = meta._msh_seo_focus_keyword || '';

    const [ aiLoading, setAiLoading ] = useState( false );
    const [ aiTitles, setAiTitles ] = useState( null );
    const [ aiDescs, setAiDescs ] = useState( null );

    const displayTitle = seoTitle || title;
    const displayUrl = window.location.origin + '/' + slug;

    function setMeta( key, value ) {
        editPost( { meta: { [ key ]: value } } );
    }

    async function handleGenerateMeta( field ) {
        if ( ! window.mshSeoData?.isConnected ) return;
        setAiLoading( true );
        try {
            const result = await mshFetch( 'generate-meta', { title, content, keyword } );
            if ( field === 'title' && result.titles ) {
                setAiTitles( result.titles );
            }
            if ( field === 'description' && result.descriptions ) {
                setAiDescs( result.descriptions );
            }
        } catch ( err ) {
            // Silent fail.
        }
        setAiLoading( false );
    }

    return (
        <PanelBody title="Meta Tags" initialOpen={ true }>
            { /* SEO Title */ }
            <div style={ { marginBottom: '16px' } }>
                <TextControl
                    label="SEO Title"
                    value={ seoTitle }
                    onChange={ ( val ) => setMeta( '_msh_seo_title', val ) }
                    placeholder={ title }
                />
                <div style={ {
                    fontSize: '12px',
                    color: getCharCountColor( ( seoTitle || title ).length, 50, 60 ),
                    textAlign: 'right',
                    marginTop: '-8px',
                } }>
                    { ( seoTitle || title ).length } / 60 characters
                </div>
                { window.mshSeoData?.isConnected && (
                    <Button
                        variant="link"
                        onClick={ () => handleGenerateMeta( 'title' ) }
                        disabled={ aiLoading }
                        style={ { fontSize: '12px' } }
                    >
                        Generate with AI
                    </Button>
                ) }
                { aiTitles && (
                    <div style={ { marginTop: '8px', fontSize: '13px' } }>
                        <strong>AI Suggestions:</strong>
                        { aiTitles.map( ( t, i ) => (
                            <button
                                key={ i }
                                onClick={ () => {
                                    setMeta( '_msh_seo_title', t );
                                    setAiTitles( null );
                                } }
                                style={ {
                                    display: 'block',
                                    width: '100%',
                                    textAlign: 'left',
                                    padding: '6px 8px',
                                    margin: '4px 0',
                                    border: '1px solid #ddd',
                                    borderRadius: '4px',
                                    background: '#fff',
                                    cursor: 'pointer',
                                    fontSize: '13px',
                                } }
                            >
                                { t }
                            </button>
                        ) ) }
                    </div>
                ) }
            </div>

            { /* Meta Description */ }
            <div style={ { marginBottom: '16px' } }>
                <TextareaControl
                    label="Meta Description"
                    value={ seoDesc }
                    onChange={ ( val ) => setMeta( '_msh_seo_description', val ) }
                    rows={ 3 }
                />
                <div style={ {
                    fontSize: '12px',
                    color: getCharCountColor( seoDesc.length, 150, 160 ),
                    textAlign: 'right',
                    marginTop: '-8px',
                } }>
                    { seoDesc.length } / 160 characters
                </div>
                { window.mshSeoData?.isConnected && (
                    <Button
                        variant="link"
                        onClick={ () => handleGenerateMeta( 'description' ) }
                        disabled={ aiLoading }
                        style={ { fontSize: '12px' } }
                    >
                        Generate with AI
                    </Button>
                ) }
                { aiDescs && (
                    <div style={ { marginTop: '8px', fontSize: '13px' } }>
                        <strong>AI Suggestions:</strong>
                        { aiDescs.map( ( d, i ) => (
                            <button
                                key={ i }
                                onClick={ () => {
                                    setMeta( '_msh_seo_description', d );
                                    setAiDescs( null );
                                } }
                                style={ {
                                    display: 'block',
                                    width: '100%',
                                    textAlign: 'left',
                                    padding: '6px 8px',
                                    margin: '4px 0',
                                    border: '1px solid #ddd',
                                    borderRadius: '4px',
                                    background: '#fff',
                                    cursor: 'pointer',
                                    fontSize: '13px',
                                } }
                            >
                                { d }
                            </button>
                        ) ) }
                    </div>
                ) }
            </div>

            { /* SERP Preview */ }
            <div style={ {
                border: '1px solid #e0e0e0',
                borderRadius: '8px',
                padding: '12px',
                background: '#fff',
            } }>
                <div style={ { fontSize: '11px', color: '#757575', marginBottom: '4px' } }>
                    SERP Preview
                </div>
                <div style={ {
                    fontSize: '18px',
                    color: '#1a0dab',
                    lineHeight: '1.3',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                    cursor: 'pointer',
                } }>
                    { displayTitle.substring( 0, 60 ) }{ displayTitle.length > 60 ? '...' : '' }
                </div>
                <div style={ {
                    fontSize: '13px',
                    color: '#006621',
                    margin: '2px 0',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                } }>
                    { displayUrl }
                </div>
                <div style={ {
                    fontSize: '13px',
                    color: '#545454',
                    lineHeight: '1.4',
                    display: '-webkit-box',
                    WebkitLineClamp: 2,
                    WebkitBoxOrient: 'vertical',
                    overflow: 'hidden',
                } }>
                    { seoDesc
                        ? seoDesc.substring( 0, 160 )
                        : 'Add a meta description to see how your page will appear in search results.' }
                </div>
            </div>
        </PanelBody>
    );
}
