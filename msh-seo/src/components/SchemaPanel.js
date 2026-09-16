import { useSelect, useDispatch } from '@wordpress/data';
import { PanelBody, SelectControl } from '@wordpress/components';

const SCHEMA_TYPES = [
    { label: 'Auto-detect (recommended)', value: '' },
    { label: 'Article', value: 'Article' },
    { label: 'Blog Posting', value: 'BlogPosting' },
    { label: 'FAQ Page', value: 'FAQPage' },
    { label: 'How-To', value: 'HowTo' },
    { label: 'Product', value: 'Product' },
    { label: 'Review', value: 'Review' },
    { label: 'Recipe', value: 'Recipe' },
    { label: 'Event', value: 'Event' },
    { label: 'Local Business', value: 'LocalBusiness' },
    { label: 'Course', value: 'Course' },
    { label: 'Software Application', value: 'SoftwareApplication' },
    { label: 'Video Object', value: 'VideoObject' },
    { label: 'None (disable schema)', value: 'none' },
];

export default function SchemaPanel() {
    const meta = useSelect( ( select ) => {
        return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
    } );

    const { editPost } = useDispatch( 'core/editor' );
    const currentType = meta._msh_seo_schema_type || '';

    return (
        <PanelBody title="Schema Markup" initialOpen={ false }>
            <SelectControl
                label="Schema Type"
                value={ currentType }
                options={ SCHEMA_TYPES }
                onChange={ ( value ) => editPost( { meta: { _msh_seo_schema_type: value } } ) }
                help="Controls the JSON-LD structured data for this post. Auto-detect will choose Article, FAQ, or HowTo based on content."
            />
            { currentType === 'FAQPage' && (
                <p style={ { fontSize: '12px', color: '#2271b1', marginTop: '4px' } }>
                    FAQ schema will be auto-generated from question-heading patterns (H2/H3 ending with ?).
                </p>
            ) }
            { currentType === 'none' && (
                <p style={ { fontSize: '12px', color: '#d63638', marginTop: '4px' } }>
                    Schema markup is disabled for this post. Search engines won't show rich results.
                </p>
            ) }
        </PanelBody>
    );
}
