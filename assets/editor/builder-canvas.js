/**
 * Proto-theme — builder canvas editor enhancement.
 *
 * Pages use the builder canvas (page.html) by default, so on any `page`
 * we hide WordPress's default post-title input from the canvas and add a
 * "Page Title" panel to the document sidebar. The title still lives in
 * the DB (slug, menus, breadcrumbs, SEO, browser tab keep working).
 */
( function () {
	var plugins = window.wp && window.wp.plugins;
	var components = window.wp && window.wp.components;
	var data = window.wp && window.wp.data;
	var element = window.wp && window.wp.element;
	var i18n = window.wp && window.wp.i18n;

	var PluginDocumentSettingPanel =
		( window.wp.editor && window.wp.editor.PluginDocumentSettingPanel ) ||
		( window.wp.editPost && window.wp.editPost.PluginDocumentSettingPanel );

	if ( ! plugins || ! components || ! data || ! element || ! i18n || ! PluginDocumentSettingPanel ) {
		return;
	}

	var registerPlugin = plugins.registerPlugin;
	var TextControl = components.TextControl;
	var useSelect = data.useSelect;
	var useDispatch = data.useDispatch;
	var el = element.createElement;
	var useEffect = element.useEffect;
	var __ = i18n.__;

	var BUILDER_POST_TYPES = [ 'page' ];
	var BODY_CLASS = 'proto-builder-canvas';

	function setClassOn( node, on ) {
		if ( node && node.classList ) { node.classList.toggle( BODY_CLASS, on ); }
	}
	function syncBodies( on ) {
		setClassOn( document.body, on );
		var iframe = document.querySelector( 'iframe[name="editor-canvas"]' );
		if ( ! iframe ) { return; }
		try { setClassOn( iframe.contentDocument && iframe.contentDocument.body, on ); } catch ( e ) {}
	}

	function ProtoBuilderCanvasPanel() {
		var state = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				title: editor.getEditedPostAttribute( 'title' ) || '',
				postType: editor.getCurrentPostType() || '',
			};
		}, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;
		var isBuilder = BUILDER_POST_TYPES.indexOf( state.postType ) !== -1;

		useEffect( function () {
			syncBodies( isBuilder );
			if ( ! isBuilder ) { return undefined; }
			var observer = new MutationObserver( function () { syncBodies( true ); } );
			observer.observe( document.body, { childList: true, subtree: true } );
			return function () { observer.disconnect(); syncBodies( false ); };
		}, [ isBuilder ] );

		if ( ! isBuilder ) { return null; }

		return el(
			PluginDocumentSettingPanel,
			{ name: 'proto-builder-canvas', title: __( 'Page Title', 'cadco-theme' ), className: 'proto-builder-canvas-panel' },
			el( TextControl, {
				label: __( 'Title', 'cadco-theme' ),
				value: state.title,
				onChange: function ( next ) { editPost( { title: next } ); },
				help: __( 'Hidden from the canvas on pages. Still used for the slug, menus, browser tab, and SEO.', 'cadco-theme' ),
			} )
		);
	}

	registerPlugin( 'proto-builder-canvas', { render: ProtoBuilderCanvasPanel } );
} )();
