/**
 * AJCore e-sign field editor: place signature / text / date / checkbox boxes on a PDF.
 * Self-contained (vanilla JS + pdf.js), shared by the AJCore-RA admin tab and AJOps.
 *
 *   var ed = AJEsignFieldEditor.mount( containerEl, {   // preview + editor (centre) and field palette (right)
 *     pdfData:     ArrayBuffer,                 // the PDF
 *     pdfjsBase:   'https://…/pdfjs/',          // folder holding pdf.min.js + pdf.worker.min.js
 *     fields:      [ {type,party,page,x,y,w,h,fill} ],  // x,y,w,h = fractions of the page, from top-left
 *     signers:     [ 'Client', 'Company' ],     // role labels; party = index + 1
 *     mergeFields: { 'customer.name': 'Customer name', … },
 *     onChange:    function ( fields ) {},
 *     sideTop:     HTMLElement,                 // optional: shown at the top of the right panel (template settings)
 *     lockSigners: true                         // optional: hide Add/Remove signer (the host's signer rows decide)
 *   });  ed.getFields(); ed.setSigners( [ … ] ); ed.destroy();
 *   AJEsignFieldEditor.open( opts + onSave / onCancel )   // same editor in a full-screen dialog
 *
 * `fill` empty = the signer fills the box in BreezeDoc; `fill` set = the text ({{tokens}} allowed) is
 * stamped onto the PDF by the server before it is sent.
 */
( function () {
	'use strict';

	var TYPES = [
		[ 'signature', 'Signature', 160, 34 ], [ 'initials', 'Initials', 50, 22 ], [ 'text', 'Text', 160, 16 ],
		[ 'email', 'Email', 160, 16 ], [ 'date', 'Date', 90, 16 ], [ 'checkbox', 'Checkbox', 12, 12 ]
	];
	var COLORS = [ '#4f46e5', '#059669', '#d97706', '#db2777', '#0891b2', '#7c3aed' ];
	var CSS = '' +
		'.ajfe-root{display:flex;gap:12px;align-items:flex-start;font:13px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;min-width:0}' +
		'.ajfe-main{flex:1;min-width:0;display:flex;flex-direction:column;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;background:#fff}' +
		'.ajfe-side{width:300px;flex:none;position:sticky;top:var(--ajfe-sticky,44px);max-height:calc(100vh - 70px);overflow:auto;border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:10px;display:flex;flex-direction:column;gap:8px}' +
		'.ajfe-side h4{margin:0;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#64748b}' +
		'.ajfe-own button,.ajfe-own select,.ajfe-own input,.ajfe-bar button,.ajfe-bar select{height:30px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;padding:0 10px;font:inherit;color:#0f172a;box-sizing:border-box}' +
		'.ajfe-own input,.ajfe-own select{width:100%}.ajfe-own{display:flex;flex-direction:column;gap:8px}.ajfe-mlist{display:flex;flex-direction:column;gap:3px;max-height:200px;overflow:auto}.ajfe-own details{border-top:1px solid #e2e8f0;padding-top:8px}.ajfe-own details>summary{cursor:pointer;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;font-weight:600;margin-bottom:6px}.ajfe-mlist .ajfe-chip{height:26px;font-weight:500;font-size:12px}' +
		'.ajfe-chip{display:flex;align-items:center;gap:8px;width:100%;text-align:left;cursor:grab;font-weight:600;user-select:none}.ajfe-chip:active{cursor:grabbing}' +
		'.ajfe-chip.on{background:#4f46e5;border-color:#4f46e5;color:#fff}.ajfe-chip i{width:10px;height:10px;border-radius:2px;flex:none}' +
		'.ajfe-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding:8px 10px;border-bottom:1px solid #e2e8f0;background:#f8fafc}' +
		'.ajfe-bar button{cursor:pointer;font-weight:600;white-space:nowrap}.ajfe-bar button:hover,.ajfe-own button:hover{background:#f1f5f9}' +
		'.ajfe-bar .dng,.ajfe-own .dng{color:#b91c1c}.ajfe-bar .sp{flex:1}.ajfe-hint{color:#64748b;font-size:12px}' +
		'.ajfe-stage{overflow:auto;background:#e2e8f0;padding:14px;text-align:center;height:calc(100vh - 230px);min-height:520px}' +
		'.ajfe-wrap{position:relative;display:inline-block;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.25);text-align:left}' +
		'.ajfe-wrap canvas{display:block}.ajfe-layer{position:absolute;inset:0}.ajfe-layer.tool{cursor:crosshair}.ajfe-layer.drop{outline:3px dashed #4f46e5;outline-offset:-3px}' +
		'.ajfe-box{position:absolute;box-sizing:border-box;border:1.5px solid;border-radius:3px;font-size:10px;line-height:1.1;overflow:hidden;cursor:move;user-select:none;touch-action:none;display:flex;align-items:center;padding:0 3px;white-space:nowrap}' +
		'.ajfe-box.sel{box-shadow:0 0 0 2px rgba(15,23,42,.55);z-index:2}' +
		'.ajfe-box .rz{position:absolute;right:0;bottom:0;width:9px;height:9px;background:currentColor;cursor:nwse-resize;opacity:.8}' +
		'.ajfe-box i{font-style:italic;opacity:.8;margin-left:4px;overflow:hidden;text-overflow:ellipsis}' +
		'.ajfe-ov{position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.6);display:flex;padding:14px}' +
		'.ajfe-pn{background:#fff;border-radius:12px;flex:1;display:flex;flex-direction:column;min-width:0;overflow:hidden}' +
		'.ajfe-pn .ajfe-root{padding:12px;flex:1;overflow:auto}.ajfe-pn .ajfe-stage{height:calc(100vh - 190px)}.ajfe-pn .ajfe-side{position:static}' +
		'.ajfe-top{display:flex;gap:8px;align-items:center;padding:10px 12px;border-bottom:1px solid #e2e8f0}.ajfe-top .sp{flex:1}' +
		'.ajfe-top button{height:30px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;padding:0 12px;font:inherit;font-weight:600;cursor:pointer}.ajfe-top .pri{background:#4f46e5;border-color:#4f46e5;color:#fff}' +
		'@media(max-width:900px){.ajfe-root{flex-direction:column}.ajfe-side{width:auto;align-self:stretch}}';

	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( 'text' === k ) { n.textContent = attrs[ k ]; } else if ( 'cls' === k ) { n.className = attrs[ k ]; } else { n.setAttribute( k, attrs[ k ] ); }
		} );
		( kids || [] ).forEach( function ( c ) { n.appendChild( c ); } );
		return n;
	}
	function round( n ) { return Math.round( n * 10000 ) / 10000; }
	function clean( f ) { return { type: f.type, party: f.party, page: f.page, x: round( f.x ), y: round( f.y ), w: round( f.w ), h: round( f.h ), fill: f.fill || '' }; }
	function color( p ) { return COLORS[ ( p - 1 ) % COLORS.length ]; }

	function loadPdfjs( base, cb, fail ) {
		function ready() { window.pdfjsLib.GlobalWorkerOptions.workerSrc = base + 'pdf.worker.min.js'; cb(); }
		if ( window.pdfjsLib ) { ready(); return; }
		var s = document.createElement( 'script' );
		s.src = base + 'pdf.min.js'; s.onload = ready; s.onerror = function () { fail( 'Could not load the PDF viewer.' ); };
		document.head.appendChild( s );
	}

	function mount( host, opts ) {
		if ( ! document.getElementById( 'ajfe-css' ) ) { var st = el( 'style', { id: 'ajfe-css' } ); st.textContent = CSS; document.head.appendChild( st ); }
		var fields = ( opts.fields || [] ).map( function ( f ) { return JSON.parse( JSON.stringify( f ) ); } );
		var signers = opts.signers && opts.signers.length ? opts.signers.slice() : [ 'Signer' ];
		var merge = opts.mergeFields || {};
		( opts.fields || [] ).forEach( function ( f ) { while ( signers.length < f.party ) { signers.push( 'Signer ' + ( signers.length + 1 ) ); } } );
		var pdf = null, pageNo = 1, pageW = 612, pageH = 792, tool = '', sel = null, rendering = false, dead = false;

		host.innerHTML = '';
		var root = el( 'div', { cls: 'ajfe-root' } ), main = el( 'div', { cls: 'ajfe-main' } ), side = el( 'div', { cls: 'ajfe-side' } );
		var bar = el( 'div', { cls: 'ajfe-bar' } ), stage = el( 'div', { cls: 'ajfe-stage' } ), wrap = el( 'div', { cls: 'ajfe-wrap' } );
		var canvas = el( 'canvas' ), layer = el( 'div', { cls: 'ajfe-layer' } );
		wrap.appendChild( canvas ); wrap.appendChild( layer ); stage.appendChild( wrap );
		main.appendChild( bar ); main.appendChild( stage ); root.appendChild( main ); root.appendChild( side ); host.appendChild( root );

		function changed() { if ( opts.onChange ) { opts.onChange( fields.map( clean ) ); } }
		function onKey( e ) {
			var tag = ( e.target && e.target.tagName ) || '';
			if ( 'Escape' === e.key ) { if ( tool ) { setTool( '' ); } return; }
			if ( ( 'Delete' === e.key || 'Backspace' === e.key ) && sel && ! /INPUT|SELECT|TEXTAREA/.test( tag ) && ! e.target.isContentEditable && host.contains( document.activeElement ) ) { e.preventDefault(); removeSel(); }
		}
		document.addEventListener( 'keydown', onKey );

		// ── toolbar (above the page) ─────────────────────────────────────────────
		var prev = el( 'button', { text: '◀', title: 'Previous page' } ), next = el( 'button', { text: '▶', title: 'Next page' } ), pgLabel = el( 'span', { text: 'Page 1' } );
		var detect = el( 'button', { text: 'Auto-detect blanks', title: 'Suggest text and checkbox fields from underscores, lines and squares' } );
		var clearPg = el( 'button', { text: 'Clear page', cls: 'dng' } ), count = el( 'span', { cls: 'ajfe-hint' } );
		bar.appendChild( prev ); bar.appendChild( pgLabel ); bar.appendChild( next ); bar.appendChild( detect ); bar.appendChild( clearPg );
		bar.appendChild( el( 'span', { cls: 'sp' } ) ); bar.appendChild( count );

		// ── right panel: palette + signer + inspector ────────────────────────────
		var palette = el( 'div' ), chips = {};
		if ( opts.sideTop ) { side.appendChild( opts.sideTop ); }
		var own = el( 'div', { cls: 'ajfe-own' } ); side.appendChild( own );
		function section( title, open ) { var d = el( 'details' ); if ( open ) { d.setAttribute( 'open', '' ); } d.appendChild( el( 'summary', { text: title } ) ); return d; }
		var addSec = section( 'Add fields — drag onto the page', true );
		TYPES.forEach( function ( t ) {
			var c = el( 'button', { cls: 'ajfe-chip', draggable: 'true', title: 'Drag onto the page, or click then click the page' } );
			c.appendChild( el( 'i' ) ); c.appendChild( el( 'span', { text: t[1] } ) );
			c.addEventListener( 'dragstart', function ( e ) { e.dataTransfer.setData( 'text/plain', 'ajfe:' + t[0] ); e.dataTransfer.effectAllowed = 'copy'; } );
			c.addEventListener( 'click', function () { setTool( tool === t[0] ? '' : t[0] ); } );
			chips[ t[0] ] = c; palette.appendChild( c ); palette.lastChild.style.marginBottom = '4px';
		} );
		addSec.appendChild( palette );
		var partyWrap = el( 'div' ), partySel = el( 'select' );
		partyWrap.appendChild( el( 'h4', { text: 'New fields are for' } ) ); partyWrap.appendChild( partySel );
		var sgRow = el( 'div' ), addSg = el( 'button', { text: '+ Add signer', title: 'Another person who signs this document' } ), rmSg = el( 'button', { text: '− Remove last' } );
		sgRow.style.cssText = 'display:flex;gap:6px;margin-top:6px'; sgRow.appendChild( addSg ); sgRow.appendChild( rmSg ); partyWrap.appendChild( sgRow ); addSec.appendChild( partyWrap ); own.appendChild( addSec );
		if ( opts.lockSigners ) { sgRow.style.display = 'none'; } // the host's signer rows decide who signs
		addSg.addEventListener( 'click', function () { signers.push( 'Signer ' + ( signers.length + 1 ) ); renderSigners(); renderInsp(); changed(); partySel.value = String( signers.length ); renderSigners(); } );
		rmSg.addEventListener( 'click', function () {
			if ( signers.length < 2 ) { return; }
			if ( fields.some( function ( f ) { return f.party === signers.length; } ) ) { window.alert( 'Signer ' + signers.length + ' still has fields on the page. Delete or reassign them first.' ); return; }
			signers.pop(); renderSigners(); renderInsp(); changed();
		} );
		// Customer / company fields: drag one onto the page to drop a text box already filled with it.
		var mWrap = section( 'Customer & company fields — drag onto the page', true ), mSearch = el( 'input', { type: 'search', placeholder: 'Search customer & company fields…' } ), mList = el( 'div', { cls: 'ajfe-mlist' } ), mChips = {};
		mSearch.style.margin = '6px 0'; mWrap.appendChild( mSearch ); mWrap.appendChild( mList );
		var mEntries = [ [ '__ask', 'Ask when sending…' ] ].concat( Object.keys( merge ).map( function ( k ) { return [ k, merge[ k ] ]; } ) );
		mEntries.forEach( function ( m ) {
			var c = el( 'button', { cls: 'ajfe-chip', draggable: 'true', title: '__ask' === m[0] ? 'A value you type in each time you send' : '{{' + m[0] + '}}' } );
			c.appendChild( el( 'span', { text: m[1] } ) ); c.setAttribute( 'data-label', m[1].toLowerCase() );
			c.addEventListener( 'dragstart', function ( e ) { e.dataTransfer.setData( 'text/plain', 'ajfe:merge:' + m[0] ); e.dataTransfer.effectAllowed = 'copy'; } );
			c.addEventListener( 'click', function () { setTool( tool === 'merge:' + m[0] ? '' : 'merge:' + m[0] ); } );
			mChips[ m[0] ] = c; mList.appendChild( c );
		} );
		mSearch.addEventListener( 'input', function () { var q = mSearch.value.toLowerCase().trim(); Object.keys( mChips ).forEach( function ( k ) { mChips[ k ].style.display = ! q || mChips[ k ].getAttribute( 'data-label' ).indexOf( q ) >= 0 ? '' : 'none'; } ); } );
		own.appendChild( mWrap );
		var insp = el( 'div' ); own.insertBefore( insp, own.firstChild ); // the selected field sits at the top of the panel
		function renderSigners() {
			var cur = partySel.value; partySel.innerHTML = '';
			signers.forEach( function ( r, i ) { partySel.appendChild( el( 'option', { value: String( i + 1 ), text: ( i + 1 ) + '. ' + ( r || 'Signer' ) } ) ); } );
			if ( cur && parseInt( cur, 10 ) <= signers.length ) { partySel.value = cur; }
			TYPES.forEach( function ( t ) { chips[ t[0] ].firstChild.style.background = color( parseInt( partySel.value, 10 ) || 1 ); } );
		}
		partySel.addEventListener( 'change', renderSigners );
		function setTool( t ) {
			tool = t;
			Object.keys( chips ).forEach( function ( k ) { chips[ k ].classList.toggle( 'on', k === t ); } );
			Object.keys( mChips ).forEach( function ( k ) { mChips[ k ].classList.toggle( 'on', 'merge:' + k === t ); } );
			layer.classList.toggle( 'tool', !! t );
		}

		function renderInsp() {
			insp.innerHTML = '';
			if ( ! sel ) {
				insp.appendChild( el( 'p', { cls: 'ajfe-hint', text: 'Select a box to edit it. Drag to move, drag the corner to resize.' } ) );
				return;
			}
			insp.appendChild( el( 'h4', { text: 'Selected field' } ) );
			var typeSel = el( 'select' );
			TYPES.forEach( function ( t ) { var o = el( 'option', { value: t[0], text: t[1] } ); if ( t[0] === sel.type ) { o.selected = true; } typeSel.appendChild( o ); } );
			typeSel.addEventListener( 'change', function () { sel.type = typeSel.value; paint(); renderInsp(); changed(); } );
			var pSel = el( 'select' );
			signers.forEach( function ( r, i ) { var o = el( 'option', { value: String( i + 1 ), text: ( i + 1 ) + '. ' + ( r || 'Signer' ) } ); if ( i + 1 === sel.party ) { o.selected = true; } pSel.appendChild( o ); } );
			pSel.addEventListener( 'change', function () { sel.party = parseInt( pSel.value, 10 ); paint(); changed(); } );
			[ typeSel, pSel ].forEach( function ( n ) { n.style.marginBottom = '6px'; insp.appendChild( n ); } );
			if ( 'signature' !== sel.type && 'initials' !== sel.type ) {
				insp.appendChild( el( 'h4', { text: 'Fill with' } ) );
				var fill = el( 'input', { type: 'text', placeholder: 'empty = signer fills in', value: sel.fill || '' } );
				fill.addEventListener( 'input', function () { sel.fill = fill.value; paint(); changed(); } );
				var tokens = el( 'select' );
				tokens.appendChild( el( 'option', { value: '', text: 'Insert customer field…' } ) );
				tokens.appendChild( el( 'option', { value: '__ask', text: 'Ask when sending…' } ) );
				Object.keys( merge ).forEach( function ( k ) { tokens.appendChild( el( 'option', { value: k, text: merge[ k ] } ) ); } );
				tokens.addEventListener( 'change', function () {
					if ( '__ask' === tokens.value ) {
						var nm = window.prompt( 'Name of the value to ask for when sending (e.g. LLC name)' ), slug = ( nm || '' ).toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' );
						tokens.value = ''; if ( slug ) { fill.value += '{{ask.' + slug + '}}'; sel.fill = fill.value; paint(); changed(); }
						return;
					}
					if ( tokens.value ) { fill.value += '{{' + tokens.value + '}}'; sel.fill = fill.value; paint(); changed(); tokens.value = ''; } } );
				fill.style.marginBottom = '6px'; insp.appendChild( fill ); insp.appendChild( tokens );
				insp.appendChild( el( 'p', { cls: 'ajfe-hint', text: 'Filled boxes are printed on the PDF when you send. Empty boxes are filled by the signer.' + ( 'checkbox' === sel.type ? ' Any value ticks the box.' : '' ) } ) );
			}
			var del = el( 'button', { text: 'Delete field', cls: 'dng' } ); del.style.marginTop = '6px'; del.addEventListener( 'click', removeSel ); insp.appendChild( del );
		}
		function removeSel() { if ( ! sel ) { return; } fields.splice( fields.indexOf( sel ), 1 ); sel = null; paint(); renderInsp(); changed(); }
		clearPg.addEventListener( 'click', function () {
			if ( ! window.confirm( 'Remove every field on this page?' ) ) { return; }
			fields = fields.filter( function ( f ) { return f.page !== pageNo; } ); sel = null; paint(); renderInsp(); changed();
		} );

		// ── boxes on the page ────────────────────────────────────────────────────
		function paint() {
			layer.innerHTML = '';
			fields.forEach( function ( f ) {
				if ( f.page !== pageNo ) { return; }
				var c = color( f.party ), b = el( 'div', { cls: 'ajfe-box' + ( f === sel ? ' sel' : '' ) } );
				b.style.cssText = 'left:' + f.x * 100 + '%;top:' + f.y * 100 + '%;width:' + f.w * 100 + '%;height:' + f.h * 100 + '%;border-color:' + c + ';background:' + c + '22;color:' + c;
				var label = ( TYPES.filter( function ( t ) { return t[0] === f.type; } )[0] || [ '', f.type ] )[1];
				if ( 'checkbox' === f.type ) { label = '☐'; }
				b.appendChild( el( 'span', { text: label } ) );
				if ( f.fill ) { b.appendChild( el( 'i', { text: f.fill } ) ); }
				var rz = el( 'span', { cls: 'rz' } ); b.appendChild( rz );
				b.addEventListener( 'pointerdown', function ( e ) {
					e.stopPropagation(); e.preventDefault();
					var resizing = e.target === rz, sx = e.clientX, sy = e.clientY, ox = f.x, oy = f.y, ow = f.w, oh = f.h, W = wrap.clientWidth, H = wrap.clientHeight, moved = false;
					if ( sel !== f ) { sel = f; renderInsp(); layer.querySelectorAll( '.sel' ).forEach( function ( n ) { n.classList.remove( 'sel' ); } ); b.classList.add( 'sel' ); }
					function mv( ev ) {
						var dx = ( ev.clientX - sx ) / W, dy = ( ev.clientY - sy ) / H; moved = true;
						if ( resizing ) { f.w = Math.max( 0.008, Math.min( 1 - f.x, ow + dx ) ); f.h = Math.max( 0.006, Math.min( 1 - f.y, oh + dy ) ); b.style.width = f.w * 100 + '%'; b.style.height = f.h * 100 + '%'; }
						else { f.x = Math.max( 0, Math.min( 1 - f.w, ox + dx ) ); f.y = Math.max( 0, Math.min( 1 - f.h, oy + dy ) ); b.style.left = f.x * 100 + '%'; b.style.top = f.y * 100 + '%'; }
					}
					function up() { document.removeEventListener( 'pointermove', mv ); document.removeEventListener( 'pointerup', up ); if ( moved ) { updateCount(); changed(); } }
					document.addEventListener( 'pointermove', mv ); document.addEventListener( 'pointerup', up );
				} );
				layer.appendChild( b );
			} );
			updateCount();
		}
		function updateCount() {
			var onPage = fields.filter( function ( f ) { return f.page === pageNo; } ).length;
			count.textContent = fields.length + ' field(s) · ' + onPage + ' on this page';
		}
		function dropAt( type, clientX, clientY, fillText ) {
			var t = TYPES.filter( function ( x ) { return x[0] === type; } )[0]; if ( ! t ) { return; }
			var r = layer.getBoundingClientRect(), w = t[2] / pageW, h = t[3] / pageH;
			var x = Math.max( 0, Math.min( 1 - w, ( clientX - r.left ) / r.width - w / 2 ) ), y = Math.max( 0, Math.min( 1 - h, ( clientY - r.top ) / r.height - h / 2 ) );
			var f = { type: type, party: parseInt( partySel.value, 10 ) || 1, page: pageNo, x: x, y: y, w: w, h: h, fill: fillText || '' };
			fields.push( f ); sel = f; paint(); renderInsp(); changed();
		}
		function dropMerge( token, cx, cy ) {
			var fill = '{{' + token + '}}';
			if ( '__ask' === token ) {
				var nm = window.prompt( 'Name of the value to ask for when sending (e.g. LLC name)' ), slug = ( nm || '' ).toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' );
				if ( ! slug ) { return; }
				fill = '{{ask.' + slug + '}}';
			}
			dropAt( 'text', cx, cy, fill );
		}
		layer.addEventListener( 'pointerdown', function ( e ) {
			if ( e.target !== layer ) { return; }
			if ( ! tool ) { sel = null; paint(); renderInsp(); return; }
			if ( 0 === tool.indexOf( 'merge:' ) ) { dropMerge( tool.slice( 6 ), e.clientX, e.clientY ); return; }
			dropAt( tool, e.clientX, e.clientY );
		} );
		layer.addEventListener( 'dragover', function ( e ) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; layer.classList.add( 'drop' ); } );
		layer.addEventListener( 'dragleave', function () { layer.classList.remove( 'drop' ); } );
		layer.addEventListener( 'drop', function ( e ) {
			e.preventDefault(); layer.classList.remove( 'drop' );
			var d = e.dataTransfer.getData( 'text/plain' ) || '';
			if ( 0 === d.indexOf( 'ajfe:merge:' ) ) { dropMerge( d.slice( 11 ), e.clientX, e.clientY ); } else if ( 0 === d.indexOf( 'ajfe:' ) ) { dropAt( d.slice( 5 ), e.clientX, e.clientY ); }
		} );

		// ── pages ────────────────────────────────────────────────────────────────
		function showPage( n ) {
			if ( ! pdf || rendering || dead ) { return; }
			pageNo = Math.max( 1, Math.min( pdf.numPages, n ) ); rendering = true; sel = null;
			pdf.getPage( pageNo ).then( function ( page ) {
				var v1 = page.getViewport( { scale: 1 } ); pageW = v1.width; pageH = v1.height;
				var scale = Math.min( 2, Math.max( 0.6, Math.min( stage.clientWidth - 30, 1100 ) / v1.width ) ), vp = page.getViewport( { scale: scale } );
				canvas.width = Math.floor( vp.width ); canvas.height = Math.floor( vp.height );
				wrap.style.width = canvas.width + 'px'; wrap.style.height = canvas.height + 'px';
				return page.render( { canvasContext: canvas.getContext( '2d' ), viewport: vp } ).promise;
			} ).then( function () { pgLabel.textContent = 'Page ' + pageNo + ' / ' + pdf.numPages; rendering = false; paint(); renderInsp(); } )
				.catch( function ( e ) { rendering = false; window.alert( 'Could not show the page: ' + ( e && e.message ? e.message : e ) ); } );
		}
		prev.addEventListener( 'click', function () { showPage( pageNo - 1 ); } );
		next.addEventListener( 'click', function () { showPage( pageNo + 1 ); } );

		// ── auto-detect: underscore runs, drawn underlines, squares ──────────────
		function detectPage( page, no ) {
			var v = page.getViewport( { scale: 1 } ), W = v.width, H = v.height, found = [];
			function add( type, x, topY, w, h ) { found.push( { type: type, party: parseInt( partySel.value, 10 ) || 1, page: no, x: x / W, y: topY / H, w: w / W, h: h / H, fill: '' } ); }
			return page.getTextContent().then( function ( tc ) {
				tc.items.forEach( function ( it ) {
					var s = it.str; if ( ! s || s.indexOf( '___' ) < 0 ) { return; }
					var tr = it.transform, fh = Math.abs( tr[3] ) || it.height || 10, L = s.length, re = /_{3,}/g, m;
					while ( ( m = re.exec( s ) ) ) { add( 'text', tr[4] + it.width * m.index / L, H - ( tr[5] + fh * 0.9 ), it.width * m[0].length / L, fh * 1.15 ); }
				} );
				return page.getOperatorList();
			} ).then( function ( ol ) {
				var O = window.pdfjsLib.OPS, ctm = [ 1, 0, 0, 1, 0, 0 ], stack = [], hs = [];
				function mul( m, n ) { return [ m[0] * n[0] + m[2] * n[1], m[1] * n[0] + m[3] * n[1], m[0] * n[2] + m[2] * n[3], m[1] * n[2] + m[3] * n[3], m[0] * n[4] + m[2] * n[5] + m[4], m[1] * n[4] + m[3] * n[5] + m[5] ]; }
				function ap( x, y ) { return [ ctm[0] * x + ctm[2] * y + ctm[4], ctm[1] * x + ctm[3] * y + ctm[5] ]; }
				function seg( a, b ) {
					var p = ap( a[0], a[1] ), q = ap( b[0], b[1] ), len = Math.abs( q[0] - p[0] );
					if ( Math.abs( q[1] - p[1] ) > 0.8 ) { return; }
					var x = Math.min( p[0], q[0] ), y = p[1];
					if ( len >= 28 && len <= 460 ) { add( 'text', x, H - ( y + 14 ), len, 14 ); } else if ( len >= 6 && len <= 16 ) { hs.push( { x: x, y: y, len: len } ); }
				}
				function rect( x, y, w, h ) {
					var p = ap( x, y ), q = ap( x + w, y + h ), bx = Math.min( p[0], q[0] ), by = Math.min( p[1], q[1] ), bw = Math.abs( q[0] - p[0] ), bh = Math.abs( q[1] - p[1] );
					if ( bh <= 1.6 && bw >= 28 && bw <= 460 ) { add( 'text', bx, H - ( by + 14 ), bw, 14 ); } else if ( bw >= 6 && bw <= 16 && bh >= 6 && bh <= 16 && Math.abs( bw - bh ) < 1.8 ) { add( 'checkbox', bx, H - ( by + bh ), bw, bh ); }
				}
				for ( var i = 0; i < ol.fnArray.length; i++ ) {
					var fn = ol.fnArray[ i ], a = ol.argsArray[ i ];
					if ( O.save === fn ) { stack.push( ctm.slice() ); } else if ( O.restore === fn ) { ctm = stack.pop() || ctm; } else if ( O.transform === fn ) { ctm = mul( ctm, a ); } else if ( O.constructPath === fn ) {
						var ops = a[0], c = a[1], k = 0, cur = null, start = null;
						ops.forEach( function ( op ) {
							if ( O.moveTo === op ) { cur = [ c[k], c[k + 1] ]; start = cur; k += 2; } else if ( O.lineTo === op ) { var nx = [ c[k], c[k + 1] ]; k += 2; if ( cur ) { seg( cur, nx ); } cur = nx; } else if ( O.curveTo === op ) { k += 6; cur = [ c[k - 2], c[k - 1] ]; } else if ( O.curveTo2 === op || O.curveTo3 === op ) { k += 4; cur = [ c[k - 2], c[k - 1] ]; } else if ( O.closePath === op ) { if ( cur && start ) { seg( cur, start ); } cur = start; } else if ( O.rectangle === op ) { rect( c[k], c[k + 1], c[k + 2], c[k + 3] ); k += 4; }
						} );
					}
				}
				hs.forEach( function ( a, ia ) {
					hs.forEach( function ( b, ib ) {
						if ( ib <= ia || Math.abs( a.x - b.x ) > 1.2 || Math.abs( a.len - b.len ) > 1.5 ) { return; }
						if ( Math.abs( Math.abs( a.y - b.y ) - a.len ) > 1.8 ) { return; }
						add( 'checkbox', a.x, H - Math.max( a.y, b.y ), a.len, a.len );
					} );
				} );
				return found;
			} );
		}
		function overlaps( a, b ) {
			var ix = Math.max( 0, Math.min( a.x + a.w, b.x + b.w ) - Math.max( a.x, b.x ) ), iy = Math.max( 0, Math.min( a.y + a.h, b.y + b.h ) - Math.max( a.y, b.y ) );
			return ix * iy > 0.35 * Math.min( a.w * a.h, b.w * b.h );
		}
		detect.addEventListener( 'click', function () {
			if ( ! pdf ) { return; }
			detect.disabled = true; detect.textContent = 'Detecting…';
			var added = 0, n = 1;
			function step() {
				if ( n > pdf.numPages ) { detect.disabled = false; detect.textContent = 'Auto-detect blanks'; paint(); if ( added ) { changed(); } window.alert( added ? added + ' field(s) suggested — adjust or delete any that are wrong.' : 'No blanks found. Drag fields onto the page instead.' ); return; }
				pdf.getPage( n ).then( function ( page ) { return detectPage( page, n ); } ).then( function ( list ) {
					list.forEach( function ( f ) { if ( ! fields.some( function ( x ) { return x.page === f.page && overlaps( x, f ); } ) ) { fields.push( f ); added++; } } );
					n++; step();
				} ).catch( function () { n++; step(); } );
			}
			step();
		} );

		// ── start ────────────────────────────────────────────────────────────────
		renderSigners(); setTool( '' ); renderInsp(); updateCount();
		loadPdfjs( opts.pdfjsBase, function () {
			if ( dead ) { return; }
			window.pdfjsLib.getDocument( { data: new Uint8Array( opts.pdfData ) } ).promise.then( function ( d ) { pdf = d; showPage( 1 ); } )
				.catch( function ( e ) { stage.innerHTML = ''; stage.appendChild( el( 'p', { cls: 'ajfe-hint', text: 'Could not open the PDF: ' + ( e && e.message ? e.message : e ) } ) ); } );
		}, function ( m ) { stage.textContent = m; } );

		return {
			getFields: function () { return fields.map( clean ); },
			getSigners: function () { return signers.slice(); },
			setSigners: function ( list ) { signers = list && list.length ? list.slice() : [ 'Signer' ]; renderSigners(); renderInsp(); paint(); },
			destroy: function () { dead = true; document.removeEventListener( 'keydown', onKey ); host.innerHTML = ''; }
		};
	}

	/** The same editor in a full-screen dialog (used for one-off PDF uploads). */
	function open( opts ) {
		if ( ! document.getElementById( 'ajfe-css' ) ) { var st = el( 'style', { id: 'ajfe-css' } ); st.textContent = CSS; document.head.appendChild( st ); }
		var ov = el( 'div', { cls: 'ajfe-ov' } ), pn = el( 'div', { cls: 'ajfe-pn' } ), top = el( 'div', { cls: 'ajfe-top' } ), body = el( 'div' );
		var cancel = el( 'button', { text: 'Cancel' } ), save = el( 'button', { text: 'Save fields', cls: 'pri' } );
		top.appendChild( el( 'strong', { text: 'Place fields' } ) ); top.appendChild( el( 'span', { cls: 'sp' } ) ); top.appendChild( cancel ); top.appendChild( save );
		pn.appendChild( top ); pn.appendChild( body ); ov.appendChild( pn ); document.body.appendChild( ov );
		var ed = mount( body, { pdfData: opts.pdfData, pdfjsBase: opts.pdfjsBase, fields: opts.fields, signers: opts.signers, mergeFields: opts.mergeFields } );
		cancel.addEventListener( 'click', function () { ed.destroy(); ov.remove(); if ( opts.onCancel ) { opts.onCancel(); } } );
		save.addEventListener( 'click', function () { var f = ed.getFields(); ed.destroy(); ov.remove(); if ( opts.onSave ) { opts.onSave( f ); } } );
	}

	function ensureCss() { if ( ! document.getElementById( 'ajfe-css' ) ) { var st = el( 'style', { id: 'ajfe-css' } ); st.textContent = CSS; document.head.appendChild( st ); } }
	window.AJEsignFieldEditor = { mount: mount, open: open, ensureCss: ensureCss, loadPdfjs: loadPdfjs };
}() );
