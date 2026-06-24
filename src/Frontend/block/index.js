import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';

registerBlockType( 'porto-sender/request', {
	edit: () => <p { ...useBlockProps() }>Porto-Code Anforderungsformular (wird im Frontend angezeigt).</p>,
	save: () => null, // dynamic: rendered by PHP render_callback
} );
