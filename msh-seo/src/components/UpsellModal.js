import { Modal, Button } from '@wordpress/components';
import { useState } from '@wordpress/element';

export function useUpsellModal() {
    const [ isOpen, setIsOpen ] = useState( false );
    return {
        isOpen,
        open: () => setIsOpen( true ),
        close: () => setIsOpen( false ),
    };
}

export default function UpsellModal( { isOpen, onClose } ) {
    if ( ! isOpen ) return null;

    const data = window.mshSeoData || {};
    const settingsUrl = data.settingsUrl || '/wp-admin/admin.php?page=msh-seo';

    return (
        <Modal
            title="Unlock AI-Powered SEO"
            onRequestClose={ onClose }
            style={ { maxWidth: '480px' } }
        >
            <div style={ { padding: '8px 0' } }>
                <p style={ { fontSize: '14px', color: '#1e1e1e', lineHeight: '1.6', marginBottom: '16px' } }>
                    Connect to MSH to unlock AI features that help you rank faster:
                </p>

                <div style={ { display: 'flex', flexDirection: 'column', gap: '10px', marginBottom: '20px' } }>
                    { [
                        { icon: '\uD83E\uDD16', text: 'AI-powered content analysis with specific fix suggestions' },
                        { icon: '\u270D\uFE0F', text: 'AI-generated meta titles and descriptions' },
                        { icon: '\uD83D\uDD0D', text: 'SERP analysis \u2014 see what competitors rank for' },
                        { icon: '\uD83D\uDCCA', text: 'NLP term recommendations (like SurferSEO)' },
                        { icon: '\uD83D\uDCDD', text: 'Full article generation with SERP optimization' },
                        { icon: '\uD83D\uDD17', text: 'Auto-distribute to 22 platforms for backlinks' },
                    ].map( ( item, i ) => (
                        <div key={ i } style={ { display: 'flex', alignItems: 'center', gap: '10px', fontSize: '13px' } }>
                            <span style={ { fontSize: '16px', flexShrink: 0 } }>{ item.icon }</span>
                            <span>{ item.text }</span>
                        </div>
                    ) ) }
                </div>

                <div style={ { background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: '8px', padding: '12px', marginBottom: '16px', fontSize: '13px' } }>
                    <strong style={ { color: '#15803d' } }>Free to start</strong>
                    <span style={ { color: '#166534' } }> — Create your MSH account, generate an API key, and paste it in settings. Takes 30 seconds.</span>
                </div>

                <div style={ { display: 'flex', gap: '8px' } }>
                    <Button
                        variant="primary"
                        href="https://app.marketingsohigh.com/settings?tab=wordpress_plugin&utm_source=wp-plugin&utm_medium=plugin&utm_campaign=upsell-modal"
                        target="_blank"
                        rel="noopener noreferrer"
                        style={ { flex: 1, justifyContent: 'center' } }
                    >
                        Create Free Account →
                    </Button>
                    <Button
                        variant="secondary"
                        href={ settingsUrl }
                        style={ { flex: 1, justifyContent: 'center' } }
                    >
                        I Have an API Key
                    </Button>
                </div>
            </div>
        </Modal>
    );
}
