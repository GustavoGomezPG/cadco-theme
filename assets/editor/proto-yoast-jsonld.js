/**
 * Proto-theme — Custom JSON-LD row for the Yoast SEO editor UI.
 *
 * Yoast renders its editor rows through two SlotFill slots, "YoastMetabox"
 * (the metabox below the block editor) and "YoastSidebar" (the Yoast panel
 * in the editor sidebar), and sorts every fill child by its `renderPriority`
 * prop. This is the same extension point Yoast Premium uses. We add one
 * collapsible "JSON-LD" row to each, built from Yoast's own public
 * components (window.yoast.editorModules.components) so it looks native.
 *
 * The code field is core's CodeMirror (`wp.codeEditor`, configured in
 * inc/proto-yoast-jsonld.php): line numbers, JSON-LD highlighting, bracket
 * matching, auto-indent and core's jsonlint gutter. If the user disabled
 * syntax highlighting in their profile it falls back to a plain textarea.
 * Either way it edits the `_proto_jsonld` post meta, which is saved with the
 * normal Update button.
 *
 * Plain script, no build step: wp.element.createElement only.
 */
( function () {
	var wp = window.wp || {};
	var plugins = wp.plugins;
	var components = wp.components;
	var data = wp.data;
	var element = wp.element;
	var i18n = wp.i18n;

	if ( ! plugins || ! components || ! data || ! element || ! i18n || ! components.Fill ) {
		return;
	}

	var el = element.createElement;
	var Fragment = element.Fragment;
	var useMemo = element.useMemo;
	var useRef = element.useRef;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var useSelect = data.useSelect;
	var useDispatch = data.useDispatch;
	var Fill = components.Fill;
	var __ = i18n.__;
	var _n = i18n._n;
	var sprintf = i18n.sprintf;

	var CONFIG = window.protoYoastJsonLd || {};
	var META_KEY = CONFIG.metaKey || '_proto_jsonld';

	// Core code-editor settings, or null → plain textarea fallback.
	var CODE_EDITOR = CONFIG.codeEditor && wp.codeEditor && wp.codeEditor.initialize ? CONFIG.codeEditor : null;

	// Editor → post meta sync is debounced; it is also flushed on blur, so
	// clicking Update (which blurs the editor first) never saves stale text.
	var SYNC_DELAY = 200;

	// Yoast's own rows end at "Insights": priority 52 in the metabox, 32 in
	// the sidebar. Sit right after them.
	var METABOX_PRIORITY = 60;
	var SIDEBAR_PRIORITY = 40;

	var COLORS = { valid: '#007017', invalid: '#cc1818', empty: '#757575' };

	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	/**
	 * Yoast's public editor components (from the `yoast-seo-editor-modules`
	 * script, enqueued as our dependency), read lazily at render time.
	 */
	function yoastComponents() {
		var modules = window.yoast && window.yoast.editorModules;
		return ( modules && modules.components ) || null;
	}

	function isPlainObject( value ) {
		return !! value && typeof value === 'object' && ! Array.isArray( value );
	}

	/* ------------------------------------------------------------------ *
	 * Parse-error location. Browsers word JSON.parse errors differently  *
	 * (V8: "at position N", Firefox: "at line L column C", Safari: none), *
	 * so the line/column is computed here from whatever is available.    *
	 * ------------------------------------------------------------------ */

	function offsetToLocation( text, offset ) {
		var lines = text.slice( 0, offset ).split( '\n' );
		return { line: lines.length, column: lines[ lines.length - 1 ].length + 1, offset: offset };
	}

	function locationToOffset( text, line, column ) {
		var lines = text.split( '\n' );
		var offset = 0;
		for ( var i = 0; i < line - 1 && i < lines.length; i++ ) {
			offset += lines[ i ].length + 1;
		}
		return Math.min( offset + Math.max( column - 1, 0 ), text.length );
	}

	function locateError( raw, message ) {
		var m = /position (\d+)/.exec( message );
		if ( m ) {
			return offsetToLocation( raw, Math.min( parseInt( m[ 1 ], 10 ), raw.length ) );
		}
		m = /line (\d+) column (\d+)/.exec( message );
		if ( m ) {
			var line = parseInt( m[ 1 ], 10 );
			var column = parseInt( m[ 2 ], 10 );
			return { line: line, column: column, offset: locationToOffset( raw, line, column ) };
		}
		if ( /end of (JSON )?(input|data)/i.test( message ) ) {
			return offsetToLocation( raw, raw.replace( /\s+$/, '' ).length );
		}
		// Last resort: core's jsonlint (loaded with the code editor).
		if ( window.jsonlint && window.jsonlint.parse ) {
			try {
				window.jsonlint.parse( raw );
			} catch ( e ) {
				m = /line (\d+)/.exec( e.message );
				if ( m ) {
					var l = parseInt( m[ 1 ], 10 );
					return { line: l, column: 1, offset: locationToOffset( raw, l, 1 ) };
				}
			}
		}
		return null;
	}

	/** Strip engine-specific prefixes/suffixes; the location is shown separately. */
	function cleanMessage( message ) {
		return String( message )
			.replace( /^JSON\.parse:\s*/, '' )
			.replace( /^JSON Parse error:\s*/, '' )
			.replace( /\s+in JSON at position \d+.*$/, '' )
			.replace( /\s+at line \d+ column \d+ of the JSON data$/, '' )
			.replace( /\.\s*$/, '' );
	}

	/**
	 * Mirror of proto_jsonld_parse() in PHP: validate the JSON and the shape,
	 * and list the nodes that would be output.
	 *
	 * @return {{state: string, error: string, location: ?Object, types: string[], skipped: number}}
	 */
	function analyse( raw ) {
		var result = { state: 'empty', error: '', location: null, types: [], skipped: 0 };
		if ( ! raw || ! raw.trim() ) { return result; }

		var parsed;
		try {
			parsed = JSON.parse( raw );
		} catch ( e ) {
			result.state = 'invalid';
			result.error = cleanMessage( e.message );
			result.location = locateError( raw, e.message );
			return result;
		}

		var nodes;
		if ( Array.isArray( parsed ) ) {
			nodes = parsed;
		} else if ( isPlainObject( parsed ) && parsed[ '@graph' ] !== undefined ) {
			nodes = Array.isArray( parsed[ '@graph' ] ) ? parsed[ '@graph' ] : [ parsed[ '@graph' ] ];
		} else if ( isPlainObject( parsed ) ) {
			nodes = [ parsed ];
		} else {
			result.state = 'invalid';
			result.error = __( 'Expected an object, an array of objects, or an object with @graph', 'proto-theme' );
			return result;
		}

		nodes.forEach( function ( node ) {
			if ( ! isPlainObject( node ) || Object.keys( node ).filter( function ( k ) { return k !== '@context'; } ).length === 0 ) {
				result.skipped++;
				return;
			}
			var type = node[ '@type' ];
			var types = ( Array.isArray( type ) ? type : [ type ] ).filter( function ( t ) { return typeof t === 'string'; } );
			result.types.push( types.length ? types.join( ' + ' ) : __( '(no @type)', 'proto-theme' ) );
		} );

		result.state = 'valid';
		return result;
	}

	/** Move keyboard focus to the next/previous focusable element after `node`. */
	function focusSibling( node, forward ) {
		var all = Array.prototype.filter.call( document.querySelectorAll( FOCUSABLE ), function ( candidate ) {
			return candidate.offsetParent !== null && ! node.contains( candidate );
		} );
		var target = null;
		all.forEach( function ( candidate ) {
			// eslint-disable-next-line no-bitwise
			var after = node.compareDocumentPosition( candidate ) & Node.DOCUMENT_POSITION_FOLLOWING;
			if ( forward && after && ! target ) { target = candidate; }
			if ( ! forward && ! after ) { target = candidate; }
		} );
		if ( target ) { target.focus(); }
	}

	/**
	 * Replace the whole document in place (keeps undo history, unlike
	 * setValue), restore the selection when the editor is focused, and
	 * re-lint right away instead of waiting for the lint delay.
	 */
	function replaceDocument( cm, text, origin ) {
		cm.operation( function () {
			var selections = cm.hasFocus() ? cm.listSelections() : null;
			cm.replaceRange( text, { line: cm.firstLine(), ch: 0 }, { line: cm.lastLine() }, origin );
			if ( selections ) { cm.setSelections( selections ); } // Out-of-range positions are clipped.
		} );
		if ( cm.performLint ) { cm.performLint(); }
	}

	/**
	 * Lint options on top of core's (jsonlint via the "json" lint helper):
	 * an empty document is not an error (core's jsonlint reports "got EOF"),
	 * and hover tooltips are off: they float over the help text, and the
	 * status line plus the tinted line already explain the error.
	 */
	function lintOptions( coreLint ) {
		if ( ! coreLint ) { return coreLint; }
		var jsonLint = wp.CodeMirror && wp.CodeMirror.helpers && wp.CodeMirror.helpers.lint && wp.CodeMirror.helpers.lint.json;
		var options = Object.assign( {}, coreLint === true ? {} : coreLint, { tooltips: false } );
		if ( jsonLint ) {
			options.getAnnotations = function ( text, opts, cm ) {
				return text.trim() ? jsonLint( text, opts, cm ) : [];
			};
		}
		return options;
	}

	function StatusLine( props ) {
		var info = props.info;
		var children;

		if ( info.state === 'empty' ) {
			children = __( 'No custom JSON-LD. Yoast outputs its normal graph.', 'proto-theme' );
		} else if ( info.state === 'invalid' ) {
			var loc = info.location;
			children = [
				el( 'span', { key: 'msg' },
					loc
						? sprintf(
							/* translators: 1: line number, 2: column number, 3: JSON parse error. */
							__( 'Invalid JSON on line %1$d, column %2$d: %3$s.', 'proto-theme' ),
							loc.line, loc.column, info.error
						)
						: sprintf(
							/* translators: %s: JSON parse error. */
							__( 'Invalid JSON: %s.', 'proto-theme' ),
							info.error
						),
					' ',
					__( 'It is saved as typed but not output until fixed.', 'proto-theme' )
				),
				loc && el( 'button', {
					key: 'goto',
					type: 'button',
					className: 'proto-jsonld__goto',
					onClick: function () { props.onGoTo( loc ); },
					style: {
						marginLeft: '6px', padding: 0, border: 0, background: 'none',
						color: 'inherit', font: 'inherit', textDecoration: 'underline', cursor: 'pointer',
					},
				}, sprintf(
					/* translators: %d: line number. */
					__( 'Go to line %d', 'proto-theme' ),
					loc.line
				) ),
			];
		} else {
			children = sprintf(
				/* translators: 1: node count, 2: comma-separated @type list. */
				_n( 'Valid JSON. %1$d node: %2$s', 'Valid JSON. %1$d nodes: %2$s', info.types.length, 'proto-theme' ),
				info.types.length,
				info.types.join( ', ' ) || '—'
			);
			if ( info.skipped ) {
				children += ' ' + sprintf(
					/* translators: %d: number of ignored entries. */
					_n( '(%d entry ignored: not an object.)', '(%d entries ignored: not objects.)', info.skipped, 'proto-theme' ),
					info.skipped
				);
			}
		}

		return el(
			'div',
			{
				className: 'proto-jsonld__status is-' + info.state,
				role: 'status',
				'aria-live': 'polite',
				style: {
					flex: '1 1 20em', // Wraps the Format button below it in the narrow sidebar.
					margin: 0,
					color: COLORS[ info.state ],
					fontWeight: info.state === 'empty' ? 400 : 600,
					lineHeight: 1.5,
					overflowWrap: 'anywhere',
				},
			},
			children
		);
	}

	/**
	 * The row's body — shared by the metabox and sidebar rows.
	 *
	 * Data flow (the post meta is the single source of truth):
	 * - On mount the editor is created from the textarea, whose value is the
	 *   current meta.
	 * - Every editor change (typing, paste, undo, Format, programmatic
	 *   replacement) updates `doc` at once, so the status line, the error
	 *   line and the Format button always describe what is on screen. The
	 *   meta write is debounced and flushed on blur and on unmount.
	 * - A meta change this editor did not make (the other row, Update/revert)
	 *   replaces the document in place, keeping the selection when focused,
	 *   then re-lints.
	 *
	 * Markup mirrors Yoast's own field rows (e.g. "Advanced"): a
	 * `.yoast-field-group` with a `__title` label and `field-group-description`
	 * help text, so spacing and type come from Yoast's stylesheet.
	 *
	 * Yoast's collapsibles unmount their content when closed, so collapsing
	 * and re-expanding the row tears the CodeMirror instance down and mounts
	 * a fresh one — never two editors on one textarea.
	 */
	function JsonLdEditor( props ) {
		var raw = useSelect( function ( select ) {
			var meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
			return typeof meta[ META_KEY ] === 'string' ? meta[ META_KEY ] : '';
		}, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;
		var inputId = 'proto-jsonld-input-' + props.location;

		var textareaRef = useRef( null );
		var cmRef = useRef( null );
		var flushRef = useRef( function () {} );
		var lastSyncedRef = useRef( raw ); // Last value written to / received from the meta.

		// The editor's live document. Without CodeMirror (fallback textarea)
		// the meta itself is the document.
		var docState = useState( raw );
		var cmDoc = docState[ 0 ];
		var setCmDoc = docState[ 1 ];
		var readyState = useState( false );
		var ready = readyState[ 0 ];
		var setReady = readyState[ 1 ];
		var doc = ready ? cmDoc : raw;

		var info = useMemo( function () { return analyse( doc ); }, [ doc ] );

		function setRaw( next ) {
			var meta = {};
			meta[ META_KEY ] = next;
			editPost( { meta: meta } );
		}
		var setRawRef = useRef( setRaw );
		setRawRef.current = setRaw;

		// Tint the line a parse error points at. Declared before the mount
		// effect so its cleanup runs while the editor still exists.
		useEffect( function () {
			var cm = cmRef.current;
			var loc = info.state === 'invalid' && info.location;
			if ( ! cm || ! loc ) { return undefined; }
			var handle = cm.addLineClass( Math.min( loc.line - 1, cm.lineCount() - 1 ), 'background', 'proto-jsonld-error-line' );
			cm.addLineClass( handle, 'wrap', 'proto-jsonld-error-wrap' );
			return function () {
				cm.removeLineClass( handle, 'background', 'proto-jsonld-error-line' );
				cm.removeLineClass( handle, 'wrap', 'proto-jsonld-error-wrap' );
			};
		}, [ info, ready ] );

		// Meta → editor, for changes this editor did not make.
		useEffect( function () {
			var cm = cmRef.current;
			if ( ! cm || raw === lastSyncedRef.current ) { return; }
			lastSyncedRef.current = raw; // Set first: the change handler must not write it back.
			if ( cm.getValue() !== raw ) {
				replaceDocument( cm, raw, 'proto-sync' );
			}
		}, [ raw, ready ] );

		// Mount core's CodeMirror on the textarea; tear it down on unmount.
		useEffect( function () {
			if ( ! CODE_EDITOR || ! textareaRef.current ) { return undefined; }

			var timer = null;
			var codemirror = Object.assign( {}, CODE_EDITOR.codemirror, {
				// The placeholder addon copies the textarea placeholder, and core
				// ships no CSS for it, so it would render like real content.
				// Use plain-language hint text (styled grey in PHP) instead.
				placeholder: __( 'Paste or type JSON-LD here.', 'proto-theme' ),
				lint: lintOptions( CODE_EDITOR.codemirror && CODE_EDITOR.codemirror.lint ),
				extraKeys: Object.assign( {}, CODE_EDITOR.codemirror && CODE_EDITOR.codemirror.extraKeys, {
					// Indent with spaces: a literal tab inside a string is invalid JSON.
					Tab: function ( editor ) {
						if ( editor.somethingSelected() ) { editor.indentSelection( 'add' ); } else { editor.execCommand( 'insertSoftTab' ); }
					},
					'Shift-Tab': function ( editor ) { editor.indentSelection( 'subtract' ); },
				} ),
			} );
			var instance = wp.codeEditor.initialize( textareaRef.current, Object.assign( {}, CODE_EDITOR, {
				codemirror: codemirror,
				// Escape, then Tab / Shift+Tab leaves the editor (core behaviour).
				onTabNext: function ( editor ) { focusSibling( editor.getWrapperElement(), true ); },
				onTabPrevious: function ( editor ) { focusSibling( editor.getWrapperElement(), false ); },
			} ) );
			var cm = instance.codemirror;

			// The textarea was rendered from the meta; make that explicit so the
			// editor, `doc` and the meta start identical.
			if ( cm.getValue() !== lastSyncedRef.current ) {
				cm.setValue( lastSyncedRef.current );
			}

			// Core offers JavaScript keyword hints in this mode; noise for JSON.
			cm.showHint = function () {};

			function flush() {
				clearTimeout( timer );
				timer = null;
				var value = cm.getValue();
				if ( value !== lastSyncedRef.current ) {
					lastSyncedRef.current = value;
					setRawRef.current( value );
				}
			}
			flushRef.current = flush;

			// Every change — typing, paste, undo, Format, sync — updates the
			// live document; the meta write is debounced (a no-op for sync).
			cm.on( 'change', function () {
				setCmDoc( cm.getValue() );
				clearTimeout( timer );
				timer = setTimeout( flush, SYNC_DELAY );
			} );
			cm.on( 'blur', flush );

			// CodeMirror measures on init; re-measure when the row's width
			// changes (sidebar opened, Yoast tab switched, window resized).
			var host = cm.getWrapperElement().parentNode;
			var lastWidth = host.clientWidth;
			var observer = window.ResizeObserver ? new window.ResizeObserver( function () {
				if ( host.clientWidth !== lastWidth ) {
					lastWidth = host.clientWidth;
					cm.refresh();
				}
			} ) : null;
			if ( observer ) { observer.observe( host ); }

			cmRef.current = cm;
			setCmDoc( cm.getValue() );
			setReady( true );
			if ( cm.performLint ) { cm.performLint(); }

			return function () {
				flush();
				if ( observer ) { observer.disconnect(); }
				flushRef.current = function () {};
				cmRef.current = null;
				cm.toTextArea();
			};
		}, [] );

		function format() {
			var cm = cmRef.current;
			var pretty;
			try { pretty = JSON.stringify( JSON.parse( cm ? cm.getValue() : raw ), null, 2 ); } catch ( e ) { return; }
			if ( cm ) {
				replaceDocument( cm, pretty, 'proto-format' );
				flushRef.current();
			} else {
				setRaw( pretty );
			}
		}

		function goTo( loc ) {
			var cm = cmRef.current;
			if ( cm ) {
				var pos = { line: Math.min( loc.line - 1, cm.lineCount() - 1 ), ch: Math.max( loc.column - 1, 0 ) };
				cm.focus();
				cm.setCursor( pos );
				cm.scrollIntoView( pos, 60 );
				return;
			}
			var textarea = textareaRef.current;
			if ( textarea ) {
				textarea.focus();
				textarea.setSelectionRange( loc.offset, loc.offset );
			}
		}

		return el(
			'div',
			{ className: 'yoast proto-jsonld' },
			el(
				'div',
				{ className: 'yoast-field-group' },
				el( 'div', { className: 'yoast-field-group__title' },
					el( 'label', { htmlFor: inputId }, __( 'JSON-LD code', 'proto-theme' ) )
				),
				el( 'p', { className: 'field-group-description' },
					__( 'Accepts a single node object, an array of nodes, or an object with "@graph" ("@context" is optional and removed).', 'proto-theme' )
				),
				el( 'p', { className: 'field-group-description' },
					__( 'Nodes that describe this page (a WebPage type such as FAQPage, or "@id": "#webpage") merge into Yoast\'s WebPage; every other node is appended to Yoast\'s graph, with "#…" ids resolved against this page\'s URL.', 'proto-theme' )
				),
				// Own wrapper: CodeMirror inserts its DOM next to the textarea,
				// so keep that out of React-managed siblings.
				el( 'div', { className: 'proto-jsonld__editor' },
					el( 'textarea', {
						id: inputId,
						ref: textareaRef,
						className: 'yoast-field-group__textarea proto-jsonld__textarea',
						value: raw,
						onChange: function ( event ) { setRaw( event.target.value ); },
						rows: 14,
						spellCheck: false,
						autoComplete: 'off',
						placeholder: __( 'Paste or type JSON-LD here.', 'proto-theme' ),
						style: {
							display: 'block',
							fontFamily: 'Menlo, Consolas, Monaco, "Liberation Mono", monospace',
							fontSize: '12px',
							lineHeight: 1.5,
							resize: 'vertical',
						},
					} )
				),
				el(
					'div',
					{
						className: 'proto-jsonld__footer',
						style: { display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '8px 16px', marginTop: '8px' },
					},
					el( StatusLine, { info: info, onGoTo: goTo } ),
					el( 'button', {
						type: 'button',
						className: 'yoast-button yoast-button--secondary proto-jsonld__format',
						onClick: format,
						disabled: info.state !== 'valid',
					}, __( 'Format', 'proto-theme' ) )
				)
			)
		);
	}

	/**
	 * Fallback wrapper with the prop Yoast's slots sort by — used only if a
	 * future Yoast stops exposing SidebarItem.
	 */
	function PriorityItem( props ) {
		return el( 'div', null, props.children );
	}

	function ProtoYoastJsonLd() {
		var hasMeta = useSelect( function ( select ) {
			var meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' );
			return !! meta && Object.prototype.hasOwnProperty.call( meta, META_KEY );
		}, [] );
		var yoast = yoastComponents();

		// Not a Yoast-enabled screen, or meta not exposed for this post type.
		if ( ! yoast || ! hasMeta ) { return null; }

		var Item = yoast.SidebarItem || PriorityItem;
		var title = __( 'JSON-LD', 'proto-theme' );

		return el(
			Fragment,
			null,
			yoast.MetaboxCollapsible && el(
				Fill,
				{ name: 'YoastMetabox' },
				el( Item, { key: 'proto-jsonld', renderPriority: METABOX_PRIORITY },
					el( yoast.MetaboxCollapsible, { id: 'proto-jsonld-metabox', title: title },
						el( JsonLdEditor, { location: 'metabox' } )
					)
				)
			),
			yoast.SidebarCollapsible && el(
				Fill,
				{ name: 'YoastSidebar' },
				el( Item, { key: 'proto-jsonld', renderPriority: SIDEBAR_PRIORITY },
					el( yoast.SidebarCollapsible, { id: 'proto-jsonld-sidebar', title: title },
						el( JsonLdEditor, { location: 'sidebar' } )
					)
				)
			)
		);
	}

	plugins.registerPlugin( 'proto-yoast-jsonld', { render: ProtoYoastJsonLd } );
} )();
