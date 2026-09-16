import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import SeoScorePanel from './components/SeoScorePanel';
import MetaPanel from './components/MetaPanel';
import KeywordPanel from './components/KeywordPanel';
import ConnectionStatus from './components/ConnectionStatus';
import SocialPreviewPanel from './components/SocialPreviewPanel';
import SchemaPanel from './components/SchemaPanel';
import LinkMeshPanel from './components/LinkMeshPanel';

registerPlugin( 'msh-seo', {
    icon: 'chart-line',
    render: () => (
        <>
            <PluginSidebarMoreMenuItem target="msh-seo-sidebar">
                MSH SEO
            </PluginSidebarMoreMenuItem>
            <PluginSidebar
                name="msh-seo-sidebar"
                title="MSH SEO"
                icon="chart-line"
            >
                <div style={ {
                    background: 'linear-gradient(135deg, #ff5c8a, #ff9a3c)',
                    color: '#fff',
                    padding: '14px 16px',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '10px',
                } }>
                    <span style={ {
                        width: '30px', height: '30px', borderRadius: '8px',
                        background: 'rgba(255,255,255,0.22)', display: 'flex',
                        alignItems: 'center', justifyContent: 'center',
                        fontWeight: 800, fontSize: '13px', letterSpacing: '0.5px',
                    } }>MH</span>
                    <div style={ { lineHeight: 1.2 } }>
                        <div style={ { fontWeight: 700, fontSize: '13px' } }>MSH SEO</div>
                        <div style={ { fontSize: '11px', opacity: 0.9 } }>AI-powered optimization</div>
                    </div>
                </div>
                <ConnectionStatus />
                <KeywordPanel />
                <SeoScorePanel />
                <LinkMeshPanel />
                <MetaPanel />
                <SocialPreviewPanel />
                <SchemaPanel />
            </PluginSidebar>
        </>
    ),
} );
