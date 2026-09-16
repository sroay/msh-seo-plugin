import { useSelect } from '@wordpress/data';
import { PanelBody } from '@wordpress/components';
import { useState } from '@wordpress/element';

export default function SocialPreviewPanel() {
    const { title, featuredImageUrl } = useSelect( ( select ) => {
        const editor = select( 'core/editor' );
        const meta = editor.getEditedPostAttribute( 'meta' ) || {};
        const seoTitle = meta._msh_seo_title || editor.getEditedPostAttribute( 'title' ) || '';

        // Get featured image
        const featuredId = editor.getEditedPostAttribute( 'featured_media' );
        let imgUrl = '';
        if ( featuredId ) {
            const media = select( 'core' ).getMedia( featuredId );
            if ( media?.source_url ) imgUrl = media.source_url;
        }

        return {
            title: seoTitle,
            featuredImageUrl: imgUrl,
        };
    } );

    const meta = useSelect( ( select ) => {
        return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
    } );

    const description = meta._msh_seo_description || '';
    const siteUrl = window.location.hostname;
    const [ activeTab, setActiveTab ] = useState( 'facebook' );

    return (
        <PanelBody title="Social Preview" initialOpen={ false }>
            <div style={ { display: 'flex', gap: '4px', marginBottom: '12px' } }>
                <button
                    onClick={ () => setActiveTab( 'facebook' ) }
                    style={ {
                        flex: 1, padding: '6px', border: '1px solid ' + ( activeTab === 'facebook' ? '#2271b1' : '#ddd' ),
                        borderRadius: '4px', background: activeTab === 'facebook' ? '#f0f6fc' : '#fff',
                        cursor: 'pointer', fontSize: '12px', fontWeight: activeTab === 'facebook' ? '600' : '400',
                        color: activeTab === 'facebook' ? '#2271b1' : '#666',
                    } }
                >
                    Facebook / LinkedIn
                </button>
                <button
                    onClick={ () => setActiveTab( 'twitter' ) }
                    style={ {
                        flex: 1, padding: '6px', border: '1px solid ' + ( activeTab === 'twitter' ? '#2271b1' : '#ddd' ),
                        borderRadius: '4px', background: activeTab === 'twitter' ? '#f0f6fc' : '#fff',
                        cursor: 'pointer', fontSize: '12px', fontWeight: activeTab === 'twitter' ? '600' : '400',
                        color: activeTab === 'twitter' ? '#2271b1' : '#666',
                    } }
                >
                    Twitter / X
                </button>
            </div>

            { activeTab === 'facebook' && (
                <div style={ { border: '1px solid #ddd', borderRadius: '8px', overflow: 'hidden', fontSize: '13px' } }>
                    { featuredImageUrl ? (
                        <div style={ { width: '100%', height: '140px', background: '#f0f0f0', overflow: 'hidden' } }>
                            <img src={ featuredImageUrl } alt="" style={ { width: '100%', height: '100%', objectFit: 'cover' } } />
                        </div>
                    ) : (
                        <div style={ { width: '100%', height: '140px', background: '#f5f5f5', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#999', fontSize: '12px' } }>
                            No featured image set
                        </div>
                    ) }
                    <div style={ { padding: '10px 12px', borderTop: '1px solid #eee' } }>
                        <div style={ { fontSize: '11px', color: '#999', textTransform: 'uppercase', letterSpacing: '0.5px' } }>{ siteUrl }</div>
                        <div style={ { fontWeight: '600', color: '#1d2129', marginTop: '4px', lineHeight: '1.3', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' } }>
                            { title || 'Post Title' }
                        </div>
                        <div style={ { color: '#606770', marginTop: '4px', lineHeight: '1.4', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' } }>
                            { description || 'Add a meta description to control how this post appears on social media.' }
                        </div>
                    </div>
                </div>
            ) }

            { activeTab === 'twitter' && (
                <div style={ { border: '1px solid #ddd', borderRadius: '12px', overflow: 'hidden', fontSize: '13px' } }>
                    { featuredImageUrl ? (
                        <div style={ { width: '100%', height: '140px', background: '#f0f0f0', overflow: 'hidden' } }>
                            <img src={ featuredImageUrl } alt="" style={ { width: '100%', height: '100%', objectFit: 'cover' } } />
                        </div>
                    ) : (
                        <div style={ { width: '100%', height: '140px', background: '#f5f5f5', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#999', fontSize: '12px' } }>
                            No featured image set
                        </div>
                    ) }
                    <div style={ { padding: '10px 12px', borderTop: '1px solid #eee' } }>
                        <div style={ { fontWeight: '600', color: '#0f1419', lineHeight: '1.3', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' } }>
                            { title || 'Post Title' }
                        </div>
                        <div style={ { color: '#536471', marginTop: '4px', lineHeight: '1.4', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' } }>
                            { description || 'Add a meta description...' }
                        </div>
                        <div style={ { fontSize: '11px', color: '#536471', marginTop: '4px' } }>{ siteUrl }</div>
                    </div>
                </div>
            ) }

            { !description && (
                <p style={ { fontSize: '12px', color: '#d63638', marginTop: '8px' } }>
                    No meta description set. Social shares will use auto-generated text from your content.
                </p>
            ) }
        </PanelBody>
    );
}
