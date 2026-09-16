import { PanelBody } from '@wordpress/components';

export default function ConnectionStatus() {
    const data = window.mshSeoData || {};
    const isConnected = data.isConnected;
    const info = data.connectionInfo;

    if ( isConnected && info ) {
        return (
            <div style={ {
                padding: '10px 16px',
                borderBottom: '1px solid #e0e0e0',
                display: 'flex',
                alignItems: 'center',
                gap: '8px',
                fontSize: '12px',
            } }>
                <span style={ {
                    width: '8px',
                    height: '8px',
                    borderRadius: '50%',
                    background: '#00a32a',
                    flexShrink: 0,
                } } />
                <span style={ { color: '#1e1e1e' } }>
                    Connected to MSH
                    { info.plan ? ' (' + info.plan + ')' : '' }
                </span>
                { info.usage && info.limits && (
                    <span style={ {
                        marginLeft: 'auto',
                        padding: '2px 6px',
                        borderRadius: '10px',
                        background: '#f0f0f0',
                        fontSize: '11px',
                        color: '#757575',
                        whiteSpace: 'nowrap',
                    } }>
                        { info.usage.ai_calls || 0 }/{ info.limits.ai_calls || '~' } AI
                    </span>
                ) }
            </div>
        );
    }

    return (
        <div style={ {
            padding: '10px 16px',
            borderBottom: '1px solid #e0e0e0',
            background: '#fff8e1',
            fontSize: '12px',
        } }>
            <span style={ { color: '#e67700' } }>
                Connect to MSH for AI-powered features.
            </span>
            { data.settingsUrl && (
                <>
                    { ' ' }
                    <a
                        href={ data.settingsUrl }
                        style={ { color: '#1971c2', textDecoration: 'none' } }
                    >
                        Go to Settings
                    </a>
                </>
            ) }
        </div>
    );
}
