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
 *     lockSigners: true,                        // optional: hide Add/Remove signer (the host's signer rows decide)
 *     resolveValues: function () -> Promise<{token: value}|null>  // optional: enables the "Preview with values" checkbox (null = no customer yet)
 *     previewDefault: true,                     // optional: start with the preview on
 *     blanks:      [ {page,x,y,w,h,kind} ]       // optional: suggested containers saved with a form (kind = line | box); read back with getBlanks()
*   });  ed.getFields(); ed.setSigners( [ … ] ); ed.destroy();
 *   AJEsignFieldEditor.open( opts + onSave / onCancel )   // same editor in a full-screen dialog
 *
 * `fill` empty = the signer fills the box in BreezeDoc; `fill` set = the text ({{tokens}} allowed) is
 * stamped onto the PDF by the server before it is sent.
 */
( function () {
	'use strict';

	var TYPES = [
		[ 'signature', 'Signature', 180, 36 ], [ 'initials', 'Initials', 60, 28 ], [ 'text', 'Text', 160, 14 ],
[ 'email', 'Email', 160, 14 ], [ 'date', 'Date', 90, 14 ], [ 'checkbox', 'Checkbox', 12, 12 ], [ 'label', 'Label', 180, 14 ]
	];
	var COLORS = [ '#4f46e5', '#059669', '#d97706', '#db2777', '#0891b2', '#7c3aed' ];
	var CSS = '' +
		'.ajfe-root{display:flex;gap:12px;align-items:flex-start;font:13px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;min-width:0}' +
		'.ajfe-main{flex:1;min-width:0;display:flex;flex-direction:column;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;background:#fff}' +
		'.ajfe-side{width:300px;flex:none;position:sticky;top:var(--ajfe-sticky,44px);max-height:calc(100vh - 70px);overflow:auto;border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:10px;display:flex;flex-direction:column;gap:8px}' +
		'.ajfe-side h4{margin:0;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#64748b}' +
		'.ajfe-own button,.ajfe-own select,.ajfe-own input,.ajfe-bar button,.ajfe-bar select{height:30px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;padding:0 10px;font:inherit;color:#0f172a;box-sizing:border-box}' +
		'.ajfe-own input,.ajfe-own select{width:100%}.ajfe-own{display:flex;flex-direction:column;gap:8px}.ajfe-mlist{display:flex;flex-direction:column;gap:3px;max-height:200px;overflow:auto}.ajfe-own details{border-top:1px solid #e2e8f0;padding-top:6px}.ajfe-own details>summary{list-style:none}.ajfe-own details>summary::-webkit-details-marker{display:none}.ajfe-own details>summary:before{content:"▾"!important;display:inline!important;width:auto!important;height:auto!important;background:none!important;border-radius:0!important;margin-right:5px!important;color:#64748b!important;font-size:11px!important}.ajfe-own details:not([open])>summary:before{content:"▸"!important}.ajfe-own details>summary{cursor:pointer;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;font-weight:600;margin-bottom:6px}.ajfe-mlist .ajfe-chip{height:26px;font-weight:500;font-size:12px}' +
		'.ajfe-chip{display:flex;align-items:center;gap:8px;width:100%;text-align:left;cursor:grab;font-weight:600;user-select:none}.ajfe-chip:active{cursor:grabbing}' +
		'.ajfe-chip.on{background:#4f46e5;border-color:#4f46e5;color:#fff}.ajfe-chip i{width:10px;height:10px;border-radius:2px;flex:none}' +
		'.ajfe-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding:6px 8px;border-bottom:1px solid #e2e8f0;background:#f8fafc}.ajfe-bar button,.ajfe-bar select{height:28px!important;padding:0 8px!important}.ajfe-bar .ajfe-hint{white-space:nowrap;margin-left:auto}.ajfe-bar select{width:auto!important;min-width:120px;max-width:170px;appearance:auto!important;-webkit-appearance:menulist!important;background-image:none}.ajfe-own .ajfe-chip{height:28px!important;margin:0 0 3px!important;font-size:12.5px}' +
		'.ajfe-bar button{cursor:pointer;font-weight:600;white-space:nowrap}.ajfe-bar button:hover,.ajfe-own button:hover{background:#f1f5f9}' +
		'.ajfe-bar .dng,.ajfe-own .dng{color:#b91c1c}.ajfe-bar .sp{flex:1}.ajfe-hint{color:#64748b;font-size:12px}' +
		'.ajfe-stage{overflow:auto;background:#e2e8f0;padding:8px;text-align:center;height:calc(100vh - 230px);min-height:520px}' +
		'.ajfe-wrap{position:relative;display:inline-block;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.25);text-align:left}' +
		'.ajfe-wrap canvas{display:block}.ajfe-layer{position:absolute;inset:0}.ajfe-layer.tool{cursor:crosshair}.ajfe-layer.pv .ajfe-box{box-shadow:none}.ajfe-layer.drop{outline:3px dashed #4f46e5;outline-offset:-3px}' +
		'.ajfe-box{position:absolute;box-sizing:border-box;border:1.5px solid;border-radius:3px;font-size:10px;line-height:1.1;overflow:hidden;cursor:move;user-select:none;touch-action:none;display:flex;align-items:center;padding:0 3px;white-space:nowrap}' +
		'.ajfe-box{z-index:3}.ajfe-box.sel{box-shadow:0 0 0 2px rgba(15,23,42,.55);z-index:4}.ajfe-blank{position:absolute;box-sizing:border-box;border:1.5px dashed #94a3b8;background:rgba(148,163,184,.12);border-radius:3px;cursor:pointer;z-index:1}.ajfe-blank:hover{border-color:#475569}.ajfe-blank.sel{border:2px solid #0f172a;background:rgba(15,23,42,.14);z-index:2}.ajfe-bar2{display:none;flex-wrap:wrap;gap:6px;align-items:center;padding:5px 8px;border-bottom:1px solid #e2e8f0;background:#fffbeb;font-size:12px}.ajfe-bar2 button{height:26px!important;padding:0 8px!important;border:1px solid #cbd5e1;border-radius:6px;background:#fff;font:inherit;font-weight:600;cursor:pointer}.ajfe-bar2 .dng{color:#b91c1c}.ajfe-layer:focus{outline:none}.ajfe-assign{display:none;padding:8px;border-bottom:1px solid #e2e8f0;background:#f8fafc;font-size:12px}.ajfe-assign button{height:26px;padding:0 8px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;font:inherit;font-weight:600;cursor:pointer}.ajfe-assign button:disabled{opacity:.5;cursor:default}' +
		'.ajfe-box .rz{position:absolute;right:0;bottom:0;width:9px;height:9px;background:currentColor;cursor:nwse-resize;opacity:.8}' +
		'.ajfe-box i{font-style:italic;opacity:.8;margin-left:4px;overflow:hidden;text-overflow:ellipsis}' +
		'.ajfe-ov{position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.6);display:flex;padding:14px}' +
		'.ajfe-pn{background:#fff;border-radius:12px;flex:1;display:flex;flex-direction:column;min-width:0;overflow:hidden}' +
		'.ajfe-pn .ajfe-root{padding:12px;flex:1;overflow:auto}.ajfe-pn .ajfe-stage{height:calc(100vh - 190px)}.ajfe-pn .ajfe-side{position:static}' +
		'.ajfe-top{display:flex;gap:8px;align-items:center;padding:10px 12px;border-bottom:1px solid #e2e8f0}.ajfe-top .sp{flex:1}' +
		'.ajfe-top button{height:30px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;padding:0 12px;font:inherit;font-weight:600;cursor:pointer}.ajfe-top .pri{background:#4f46e5;border-color:#4f46e5;color:#fff}' +
		'.ajfe-body{display:flex;min-width:0}.ajfe-body .ajfe-stage{flex:1;min-width:0}.ajfe-left{display:none;width:210px;flex:none;border-right:1px solid #e2e8f0;background:#fff;overflow:auto;height:calc(100vh - 230px);min-height:520px;font-size:12px}.ajfe-pn .ajfe-left{height:calc(100vh - 190px)}' +
		'.ajfe-left button{height:24px;padding:0 7px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;font:inherit;font-weight:600;cursor:pointer}.ajfe-left button:disabled{opacity:.45;cursor:default}.ajfe-left .dng{color:#b91c1c}' +
		'.ajfe-brow{display:flex;gap:6px;align-items:center;padding:4px 8px;cursor:pointer;border-bottom:1px solid #f1f5f9}.ajfe-brow:hover{background:#f8fafc}.ajfe-brow.sel{background:#eef2ff}.ajfe-brow.multi{background:#fee2e2}.ajfe-brow input{width:auto!important;height:auto!important;margin:0}.ajfe-brow span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
		'.ajfe-blank.multi{border:2px solid #dc2626;background:rgba(220,38,38,.16)}.ajfe-bl{position:absolute;top:-15px;left:0;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font:10px/14px system-ui,sans-serif;color:#0f172a;background:#fff;border:1px solid #cbd5e1;border-radius:3px;padding:0 4px;pointer-events:none}.ajfe-rb{position:absolute;border:1.5px dashed #dc2626;background:rgba(220,38,38,.1);pointer-events:none;z-index:6}' +
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
	// Width (pt) of some text set in Times 11 pt, the size it is stamped at, plus a little padding.
	var measureCtx = null;
	function fitW( text ) {
		measureCtx = measureCtx || document.createElement( 'canvas' ).getContext( '2d' );
		measureCtx.font = '11px "Times New Roman", Times, serif';
		return Math.max( 30, Math.min( 330, Math.ceil( measureCtx.measureText( String( text || '' ) ).width ) + 8 ) );
	}
	// Default size (pt) of the text box a variable drops as, by what it holds: a ZIP is short, a county medium, an address long.
	function tokenSize( tok ) {
		var t = String( tok || '' ).toLowerCase();
		if ( /csz/.test( t ) ) { return [ 170, 14 ]; }
		if ( /address|street/.test( t ) ) { return [ 260, 14 ]; }
		if ( /zip|postal/.test( t ) ) { return [ 52, 14 ]; }
		if ( /(^|[._])state$/.test( t ) ) { return [ 30, 14 ]; }
		if ( /county/.test( t ) ) { return [ 95, 14 ]; }
		if ( /city/.test( t ) ) { return [ 115, 14 ]; }
		if ( /phone/.test( t ) ) { return [ 105, 14 ]; }
		if ( /email/.test( t ) ) { return [ 175, 14 ]; }
		if ( /number|pmb|year2|date\.day/.test( t ) ) { return [ 60, 14 ]; }
		if ( /date\.year/.test( t ) ) { return [ 44, 14 ]; }
		if ( /date\.month/.test( t ) ) { return [ 80, 14 ]; }
		if ( 0 === t.indexOf( 'date' ) ) { return [ 90, 14 ]; }
		if ( /(^|[._])name$|individual_name|business_name/.test( t ) || '__ask' === t || 0 === t.indexOf( 'ask.' ) ) { return [ 180, 14 ]; }
		return [ 160, 14 ];
	}
function clean( f ) { return { type: f.type, party: f.party, page: f.page, x: round( f.x ), y: round( f.y ), w: round( f.w ), h: round( f.h ), fill: f.fill || '', checked: !! f.checked }; }
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
		var previewMode = !! ( opts.previewDefault && opts.resolveValues ), values = null;
		var blanks = ( opts.blanks || [] ).map( function ( b ) { return { page: b.page, x: b.x, y: b.y, w: b.w, h: b.h,kind: 'box' === b.kind ? 'box' : 'line', label: b.label || '' }; } ), selBlank = null, pendingScroll = false, multi = [], undoStack = []; // blanks: suggested containers from Auto-detect (not saved, never sent)
		var pdf = null, pageNo = 1, pageW = 612, pageH = 792, tool = '', sel = null, rendering = false, dead = false;

		host.innerHTML = '';
		var root = el( 'div', { cls: 'ajfe-root' } ), main = el( 'div', { cls: 'ajfe-main' } ), side = el( 'div', { cls: 'ajfe-side' } );
		var bar = el( 'div', { cls: 'ajfe-bar' } ), stage = el( 'div', { cls: 'ajfe-stage' } ), wrap = el( 'div', { cls: 'ajfe-wrap' } );
var canvas = el( 'canvas' ), layer = el( 'div', { cls: 'ajfe-layer' } ); layer.tabIndex = 0;
		wrap.appendChild( canvas );wrap.appendChild( layer ); stage.appendChild( wrap );
		var leftList = el( 'div', { cls: 'ajfe-left' } ), pbody = el( 'div', { cls: 'ajfe-body' } ); pbody.appendChild( leftList ); pbody.appendChild( stage );
var bar2 = el( 'div', { cls: 'ajfe-bar2' } ); main.appendChild( bar );main.appendChild( bar2 ); main.appendChild( pbody ); root.appendChild( main ); root.appendChild( side ); host.appendChild( root );

function changed() { if ( opts.onChange ) { opts.onChange( fields.map( clean ), blanks ); } }
		function onKey( e ) {
			var tag = ( e.target && e.target.tagName ) || '';
			if ( 'Escape' === e.key ) { if ( tool ) { setTool( '' ); } else if ( selBlank ) { selBlank = null; paint(); renderBlankBar(); } return; }
			if ( ( 'z' === e.key || 'Z' === e.key ) && ( e.ctrlKey || e.metaKey ) && undoStack.length && ! /INPUT|SELECT|TEXTAREA/.test( tag ) && host.contains( document.activeElement ) ) { e.preventDefault(); undoDelete(); return; }
			if ( ( selBlank || multi.length ) && ! /INPUT|SELECT|TEXTAREA/.test( tag ) && ! e.target.isContentEditable && host.contains( document.activeElement ) ) {
				if ( ( 'Delete' === e.key || 'Backspace' === e.key ) && multi.length > 1 ) { e.preventDefault(); deleteBlanks( multi.slice() ); return; }
				if ( ! selBlank ) { return; }
				if ( 'Delete' === e.key || 'Backspace' === e.key ) { e.preventDefault(); removeBlank( selBlank ); return; }
				if ( 'ArrowRight' === e.key || 'ArrowDown' === e.key ) { e.preventDefault(); stepBlank( 1 ); return; }
				if ( 'ArrowLeft' === e.key || 'ArrowUp' === e.key ) { e.preventDefault(); stepBlank( -1 ); return; }
				if ( 'Enter' === e.key || 't' === e.key.toLowerCase() ) { e.preventDefault(); makeField( selBlank ); return; }
			}
			if ( ( 'Delete' === e.key || 'Backspace' === e.key ) && sel && ! /INPUT|SELECT|TEXTAREA/.test( tag ) && ! e.target.isContentEditable && host.contains( document.activeElement ) ) { e.preventDefault(); removeSel(); }
		}
		document.addEventListener( 'keydown', onKey );

		// ── toolbar (above the page) ─────────────────────────────────────────────
		var prev = el( 'button', { text: '◀', title: 'Previous page' } ), next = el( 'button', { text: '▶', title: 'Next page' } ), pgLabel = el( 'span', { text: 'Page 1' } );
		var detect = el( 'button', { text: 'Auto-detect', title: 'Mark the blanks (lines and boxes) on the page. They are only containers: drop a field on one to fill it.' } );
		var clearPg = el( 'button', { text: 'Clear page', cls: 'dng' } ), count = el( 'span', { cls: 'ajfe-hint' } );
		var partySel = el( 'select', { title: 'Signer for new fields — and for the selected field' } ), addSg = el( 'button', { text: '+ Signer', title: 'Another person who signs this document' } ), rmSg = el( 'button', { text: '−', title: 'Remove the last signer' } );
		partySel.style.maxWidth = '180px';
		bar.appendChild( prev ); bar.appendChild( pgLabel ); bar.appendChild( next ); bar.appendChild( partySel );
		if ( ! opts.lockSigners ) { bar.appendChild( addSg ); bar.appendChild( rmSg ); } // otherwise the host's signer rows decide who signs
		bar.appendChild( detect ); bar.appendChild( clearPg );
		bar.appendChild( el( 'span', { cls: 'sp' } ) );
		if ( opts.resolveValues ) {
			var pvLabel = el( 'label', { title: 'Show the real customer values on the page, as they will print. You can still drag, drop and edit.' } ),pvBox = el( 'input', { type: 'checkbox' } ); pvBox.checked = previewMode; if ( previewMode ) { setTimeout( function () { layer.classList.add( 'pv' ); loadValues(); }, 0 ); }
			pvLabel.style.cssText = 'display:inline-flex;align-items:center;gap:6px;font-weight:600;cursor:pointer'; pvBox.style.cssText = 'width:auto;height:auto';
			pvLabel.appendChild( pvBox ); pvLabel.appendChild( document.createTextNode( 'Preview with values' ) ); bar.appendChild( pvLabel );
			pvBox.addEventListener( 'change', function () { previewMode = pvBox.checked; layer.classList.toggle( 'pv', previewMode ); if ( previewMode ) { loadValues(); } else { paint(); } } );
		}
		bar.appendChild( count );

		// ── right panel: one field palette (signer fields + customer & company) with one search ──
		var chips = {}, mChips = {}, dragging = null;
		if ( opts.sideTop ) { side.appendChild( opts.sideTop ); }
		var own = el( 'div', { cls: 'ajfe-own' } ); side.appendChild( own );
		function section( title, open ) { var d = el( 'details' ); if ( open ) { d.setAttribute( 'open', '' ); } d.appendChild( el( 'summary', { text: title } ) ); return d; }
		function canvasScale() { return canvas.width && pageW ? canvas.width / pageW : 1; }
		// The drag image is the real field: same size as it will be on the page, at the page's current zoom, held by its centre.
		function ghost( e, wpt, hpt, label, isBox ) {
			var sc = canvasScale(), c = color( parseInt( partySel.value, 10 ) || 1 ), g = el( 'div', { text: isBox ? '' : label } );
			g.style.cssText = 'position:fixed;top:-600px;left:-600px;box-sizing:border-box;width:' + wpt * sc + 'px;height:' + hpt * sc + 'px;border:1.5px solid ' + c + ';background:' + c + '33;color:' + c + ';border-radius:3px;font:10px/1 system-ui,sans-serif;display:flex;align-items:center;padding:0 3px;overflow:hidden;white-space:nowrap';
			document.body.appendChild( g );
			try { e.dataTransfer.setDragImage( g, wpt * sc / 2, hpt * sc / 2 ); } catch ( err ) { /* browser default */ }
			setTimeout( function () { g.remove(); }, 0 );
dragging = { w: wpt, h: hpt, type: isBox ? 'checkbox' : 'text' };
		}
		var fieldsSec = section( 'Fields — drag onto the page', true );
		var searchAll = el( 'input', { type: 'search', placeholder: 'Search fields…' } );
		searchAll.style.margin = '4px 0 8px'; fieldsSec.appendChild( searchAll );
		function groupLabel( t ) { var h = el( 'h4', { text: t } ); h.style.margin = '4px 0 6px'; return h; }
		function makeTypeChip( type, host ) {
			var t = TYPES.filter( function ( x ) { return x[0] === type; } )[0];
			var c = el( 'button', { cls: 'ajfe-chip', draggable: 'true', title: 'Drag onto the page, or click then click the page' } );
			c.appendChild( el( 'i' ) ); c.appendChild( el( 'span', { text: t[1] } ) ); c.setAttribute( 'data-label', t[1].toLowerCase() );
			c.addEventListener( 'dragstart', function ( e ) { e.dataTransfer.setData( 'text/plain', 'ajfe:' + t[0] ); e.dataTransfer.effectAllowed = 'copy'; ghost( e, t[2], t[3], t[1], 'checkbox' === t[0] ); } );
			c.addEventListener( 'dragend', function () { dragging = null; hideHint(); } );
			c.addEventListener( 'click', function () { setTool( tool === t[0] ? '' : t[0] ); } );
			chips[ t[0] ] = c; host.appendChild( c );
		}
		// Signer fields: only what the signer does. Fields I fill in myself (printed text, ticked boxes) are separate.
		var grpA = el( 'div' ), grpM = el( 'div' ), palA = el( 'div' ), palM = el( 'div' );
		palA.style.cssText = 'display:grid;grid-template-columns:repeat(3,1fr);gap:4px';palM.style.cssText = 'display:grid;grid-template-columns:repeat(2,1fr);gap:4px';
		[ 'signature', 'initials', 'text' ].forEach( function ( k ) { makeTypeChip( k, palA ); } );
[ 'label', 'date', 'email', 'checkbox' ].forEach( function ( k ) { makeTypeChip( k, palM ); } );
chips.label.title = 'Text I type once (or build from variables) — printed on every document'; chips.checkbox.title = 'A box I tick — printed as a bold X'; chips.date.title = 'A date I print (today by default) — not left for the signer'; chips.email.title = 'An email address I print';
grpA.appendChild( groupLabel( 'Signer fills in' ) ); grpA.appendChild( palA );
		var mineSec = section( 'I fill in — with my customer & company data below', true ); mineSec.appendChild( palM ); grpM.appendChild( mineSec );
		fieldsSec.appendChild( grpA ); fieldsSec.appendChild( grpM );

		// Customer, company and asked-when-sending values: collapsible groups of ready-made variables.
		var groupDefs = [ [ 'our', 'Our company', true ], [ 'customer', 'Customer', true ], [ 'ask', 'Asked when sending', true ], [ 'business', 'Business profile', false ], [ 'mailbox', 'Mailbox', false ], [ 'other', 'Dates & signer', false ] ], groups = {};
		function groupOf( tok ) { if ( '__ask' === tok ) { return 'ask'; } var p = String( tok ).split( '.' )[0]; return { our: 'our', customer: 'customer', ask: 'ask', business: 'business', mailbox: 'mailbox' }[ p ] || 'other'; }
		groupDefs.forEach( function ( g ) {
			var d = section( g[1], g[2] ), list = el( 'div', { cls: 'ajfe-mlist' } );
			list.style.maxHeight = 'none'; d.appendChild( list ); groups[ g[0] ] = { el: d, list: list, def: g, n: 0, sum: d.firstChild };
		} );
		var mEntries = [ [ '__ask', 'Ask when sending…' ] ].concat( Object.keys( merge ).map( function ( k ) { return [ k, merge[ k ] ]; } ) );
		mEntries.forEach( function ( m ) {
			var g = groups[ groupOf( m[0] ) ], shown = String( m[1] ).replace( /^(Customer|Business|Our company|Mailbox|Signer 1|Asked) — /, '' );
			var c = el( 'button', { cls: 'ajfe-chip', draggable: 'true', title: '__ask' === m[0] ? 'A value you type in each time you send' : '{{' + m[0] + '}}' } );
			c.appendChild( el( 'span', { text: shown } ) ); c.setAttribute( 'data-label', String( m[1] ).toLowerCase() + ' ' + m[0].toLowerCase() + ' ' + g.def[1].toLowerCase() );
c.addEventListener( 'dragstart', function ( e ) { var sz = mergeSize( m[0] ); e.dataTransfer.setData( 'text/plain', 'ajfe:merge:' + m[0] ); e.dataTransfer.effectAllowed = 'copy'; ghost( e, sz[0], sz[1], shown, false ); } );
			c.addEventListener( 'dragend', function () { dragging = null; hideHint(); } );
			c.addEventListener( 'click', function () { setTool( tool === 'merge:' + m[0] ? '' : 'merge:' + m[0] ); } );
			mChips[ m[0] ] = c; g.list.appendChild( c ); g.n++;
		} );
		groupDefs.forEach( function ( g ) { var G = groups[ g[0] ]; if ( G.n ) { G.sum.textContent = g[1] + ' (' + G.n + ')'; fieldsSec.appendChild( G.el ); } } );
		own.appendChild( fieldsSec );
		searchAll.addEventListener( 'input', function () {
			var q = searchAll.value.toLowerCase().trim(), na = 0, nm = 0;
			[ 'signature', 'initials', 'text' ].forEach( function ( k ) { var ok = ! q || chips[ k ].getAttribute( 'data-label' ).indexOf( q ) >= 0; chips[ k ].style.display = ok ? '' : 'none'; if ( ok ) { na++; } } );
[ 'label', 'date', 'email', 'checkbox' ].forEach( function ( k ) { var ok = ! q || chips[ k ].getAttribute( 'data-label' ).indexOf( q ) >= 0; chips[ k ].style.display = ok ? '' : 'none'; if ( ok ) { nm++; } } );
			grpA.style.display = na ? '' : 'none'; grpM.style.display = nm ? '' : 'none';
			groupDefs.forEach( function ( g ) {
				var G = groups[ g[0] ], n = 0; if ( ! G.n ) { return; }
				G.list.childNodes.forEach( function ( ch ) { var ok = ! q || ch.getAttribute( 'data-label' ).indexOf( q ) >= 0; ch.style.display = ok ? '' : 'none'; if ( ok ) { n++; } } );
				G.el.style.display = n ? '' : 'none';
				if ( q ) { G.el.setAttribute( 'open', '' ); } else if ( G.def[2] ) { G.el.setAttribute( 'open', '' ); } else { G.el.removeAttribute( 'open' ); }
			} );
		} );
		var insp = el( 'div' ); own.insertBefore( insp, own.firstChild ); // the selected field sits at the top of the panel

		// Signer picker (top bar): new fields go to this signer, and picking a field shows / changes its signer.
		function renderSigners() {
			var cur = partySel.value; partySel.innerHTML = '';
			signers.forEach( function ( r, i ) { partySel.appendChild( el( 'option', { value: String( i + 1 ), text: ( i + 1 ) + '. ' + ( r || 'Signer' ) } ) ); } );
			if ( cur && parseInt( cur, 10 ) <= signers.length ) { partySel.value = cur; }
TYPES.forEach( function ( t ) { if ( chips[ t[0] ] && chips[ t[0] ].firstChild ) { chips[ t[0] ].firstChild.style.background = color( parseInt( partySel.value, 10 ) || 1 ); } } );
		}
		function syncSignerSel() { if ( sel ) { partySel.value = String( sel.party ); renderSigners(); } }
		partySel.addEventListener( 'change', function () {
			if ( sel ) { sel.party = parseInt( partySel.value, 10 ) || 1; paint(); changed(); }
			renderSigners();
		} );
		addSg.addEventListener( 'click', function () { signers.push( 'Signer ' + ( signers.length + 1 ) ); renderSigners(); partySel.value = String( signers.length ); renderSigners(); renderInsp(); changed(); } );
		rmSg.addEventListener( 'click', function () {
			if ( signers.length < 2 ) { return; }
			if ( fields.some( function ( f ) { return f.party === signers.length; } ) ) { window.alert( 'Signer ' + signers.length + ' still has fields on the page. Delete or reassign them first.' ); return; }
			signers.pop(); renderSigners(); renderInsp(); changed();
		} );
		function setTool( t ) {
			tool = t;
			Object.keys( chips ).forEach( function ( k ) { chips[ k ].classList.toggle( 'on', k === t ); } );
			Object.keys( mChips ).forEach( function ( k ) { mChips[ k ].classList.toggle( 'on', 'merge:' + k === t ); } );
			layer.classList.toggle( 'tool', !! t );
		}

		function renderInsp() {
			insp.innerHTML = '';
			if ( ! sel ) {
				insp.appendChild( el( 'p', { cls: 'ajfe-hint',text: 'Select a box to edit it.' } ) );
				return;
			}
			insp.appendChild( el( 'h4', { text: 'Selected field' } ) );
			var typeSel = el( 'select' );
TYPES.forEach( function ( t ) { if ( ! chips[ t[0] ] && t[0] !== sel.type ) { return; } var o = el( 'option', { value: t[0], text: t[1] } ); if ( t[0] === sel.type ) { o.selected = true; } typeSel.appendChild( o ); } );
			typeSel.addEventListener( 'change', function () { sel.type = typeSel.value; paint(); renderInsp(); changed(); } );
			typeSel.style.marginBottom = '6px'; insp.appendChild( typeSel );
			if ( 'checkbox' === sel.type ) {
				var tg = el( 'button', { cls: 'ajfe-chip' + ( sel.checked ? ' on' : '' ), text: ( sel.checked ? '✓' : '☐' ) + ' Checked', title: 'Print this box checked (a bold X). Unchecked, the signer ticks it.' } );
				tg.style.marginBottom = '6px';
				tg.addEventListener( 'click', function () { sel.checked = ! sel.checked; paint(); renderInsp(); changed(); } );
				insp.appendChild( tg );
			}
			if ( 'signature' !== sel.type && 'initials' !== sel.type && 'checkbox' !== sel.type ) {
insp.appendChild( el( 'h4', { text: 'label' === sel.type ? 'Text to print' : 'Fill with' } ) );
				var fill = el( 'input', { type: 'text', placeholder: 'label' === sel.type ? 'Type text, or insert a variable below' : 'empty = signer fills in', value: sel.fill || '' } );
fill.addEventListener( 'input', function () { sel.fill = fill.value; if ( 'label' === sel.type && ! sel.manualW && ! /\{\{/.test( fill.value ) ) { sel.w = Math.min( 1 - sel.x, fitW( fill.value || 'Label' ) / pageW ); } paint(); changed(); } );
				function insertToken( k ) {
					var add = '{{' + k + '}}';
					if ( '__ask' === k ) {
						var nm = window.prompt( 'Name of the value to ask for when sending (e.g. LLC name)' ), slug = ( nm || '' ).toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' );
						if ( ! slug ) { return; }
						add = '{{ask.' + slug + '}}';
					}
					fill.value += add; sel.fill = fill.value; paint(); changed();
				}
				var pick = el( 'input', { type: 'search', placeholder: 'Search fields to insert…' } ), found = el( 'div', { cls: 'ajfe-mlist' } );
				found.style.cssText = 'max-height:150px;display:none;margin-top:4px';
				pick.addEventListener( 'input', function () {
					var q = pick.value.toLowerCase().trim(), n = 0; found.innerHTML = '';
					if ( q ) {
						[ [ '__ask', 'Ask when sending…' ] ].concat( Object.keys( merge ).map( function ( k ) { return [ k, merge[ k ] ]; } ) ).forEach( function ( m ) {
							if ( n >= 12 || ( m[1].toLowerCase().indexOf( q ) < 0 && m[0].toLowerCase().indexOf( q ) < 0 ) ) { return; }
							n++; var bt = el( 'button', { cls: 'ajfe-chip', text: m[1] } ); bt.addEventListener( 'click', function () { insertToken( m[0] ); pick.value = ''; found.style.display = 'none'; found.innerHTML = ''; } ); found.appendChild( bt );
						} );
					}
					found.style.display = n ? '' : 'none';
				} );
				fill.style.marginBottom = '6px'; insp.appendChild( fill ); insp.appendChild( pick ); insp.appendChild( found );
			}
			if ( 'signature' !== sel.type && 'initials' !== sel.type && 'checkbox' !== sel.type ) {
				var fit = el( 'button', { text: 'Fit width to text', title: 'Make this box as wide as its text' } ); fit.style.marginTop = '4px';
				fit.addEventListener( 'click', function () {
					var txt = previewMode && values ? resolveText( sel.fill ).trim() : '', w;
					if ( txt ) { w = fitW( txt ); } else { var m = /\{\{\s*([a-z0-9_.]+)\s*\}\}/i.exec( sel.fill || '' ); w = m ? mergeSize( m[1].toLowerCase() )[0] : fitW( sel.fill || 'Text' ); }
					sel.w = Math.min( 1 - sel.x, w / pageW ); paint(); changed();
				} );
				insp.appendChild( fit );
			}
			var del = el( 'button', { text: 'Delete field', cls: 'dng' } ); del.style.marginTop = '6px'; del.addEventListener( 'click', removeSel ); insp.appendChild( del );
		}
		function removeSel() { if ( ! sel ) { return; } fields.splice( fields.indexOf( sel ), 1 ); sel = null; paint(); renderInsp(); changed(); }
		clearPg.addEventListener( 'click', function () {
			if ( ! window.confirm( 'Remove every field on this page?' ) ) { return; }
			fields = fields.filter( function ( f ) { return f.page !== pageNo; } ); sel = null; paint(); renderInsp(); changed();
		} );

		// ── boxes on the page ────────────────────────────────────────────────────
		function loadValues() {
Promise.resolve( opts.resolveValues() ).then( function ( v ) { values = v || null; paint(); } ).catch( function () { values = null; paint(); } );
		}
		function resolveText( t ) { return String( t || '' ).replace( /\{\{\s*([a-z0-9_.]+)\s*\}\}/gi, function ( m, k ) { var v = values[ k.toLowerCase() ]; return v === undefined || v === null ? '' : String( v ); } ); }
		// ── blanks: containers found by Auto-detect. Review them, delete the wrong ones, drop a field on a blank to fill it. ──
		function compat( kind, type ) { return 'box' === kind ? 'checkbox' === type : 'checkbox' !== type; }
		function sortBlanks() { blanks.sort( function ( a, b ) { return a.page - b.page || ( Math.abs( a.y - b.y ) > 0.008 ? a.y - b.y : a.x - b.x ); } ); }
		function blankAtPoint( cx, cy, type ) {
			var r = layer.getBoundingClientRect(), px = ( cx - r.left ) / r.width, py = ( cy - r.top ) / r.height, mx = 4 / r.width, my = 4 / r.height, hit = null;
			blanks.forEach( function ( bk ) { if ( bk.page === pageNo && compat( bk.kind, type ) && px >= bk.x - mx && px <= bk.x + bk.w + mx && py >= bk.y - my && py <= bk.y + bk.h + my ) { hit = bk; } } );
			return hit;
		}
		function scrollToBlank() {
			var d = layer.querySelector( '.ajfe-blank.sel' ); if ( ! d ) { return; }
			var top = d.getBoundingClientRect().top - stage.getBoundingClientRect().top + stage.scrollTop;
			if ( top < stage.scrollTop + 40 || top > stage.scrollTop + stage.clientHeight - 90 ) { stage.scrollTop = Math.max( 0, top - stage.clientHeight / 3 ); }
		}
		function selectBlank( b ) {
			selBlank = b; sel = b ? null : sel; renderInsp();
			if ( b && b.page !== pageNo ) { pendingScroll = true; showPage( b.page ); } else { paint(); scrollToBlank(); }
			renderBlankBar();
		}
		function stepBlank( d ) {
			if ( ! blanks.length ) { return; } sortBlanks();
			var i = selBlank ? blanks.indexOf( selBlank ) : ( d > 0 ? -1 : 0 );
			selectBlank( blanks[ ( i + d + blanks.length ) % blanks.length ] );
		}
function inMulti( b ) { return multi.indexOf( b ) >= 0; }
		function toggleMulti( b ) { var i = multi.indexOf( b ); if ( i >= 0 ) { multi.splice( i, 1 ); } else { multi.push( b ); } }
		// Deleting is undoable (Ctrl/Cmd+Z or the Undo button).
		function deleteBlanks( list ) {
			if ( ! list.length ) { return; }
			undoStack.push( list.slice() );
			list.forEach( function ( b ) { var i = blanks.indexOf( b ); if ( i >= 0 ) { blanks.splice( i, 1 ); } } );
			multi = multi.filter( function ( b ) { return blanks.indexOf( b ) >= 0; } );
			if ( selBlank && blanks.indexOf( selBlank ) < 0 ) { selBlank = blanks[ 0 ] || null; }
			changed(); paint(); renderBlankBar();
		}
		function undoDelete() { var l = undoStack.pop(); if ( ! l ) { return; } l.forEach( function ( b ) { blanks.push( b ); } ); sortBlanks(); paint(); renderBlankBar(); changed(); }
function dropBlank( b ) { var i = blanks.indexOf( b ); if ( i >= 0 ) { blanks.splice( i, 1 ); multi = multi.filter( function ( x ) { return x !== b; } ); changed(); } if ( selBlank === b ) { selBlank = blanks.length ? blanks[ Math.min( i, blanks.length - 1 ) ] : null; } return i; }
function removeBlank( b ) { var i = blanks.indexOf( b ); deleteBlanks( [ b ] ); if ( blanks.length ) { selectBlank( blanks[ Math.min( Math.max( i, 0 ), blanks.length - 1 ) ] ); } }
function removeKind( kind ) { deleteBlanks( blanks.filter( function ( b ) { return b.kind === kind; } ) ); }
		function makeField( b ) {
			var type = 'box' === b.kind ? 'checkbox' : 'text';
			dropBlank( b );
			var f = { type: type, party: parseInt( partySel.value, 10 ) || 1, page: b.page, x: b.x, y: b.y, w: b.w, h: b.h, fill: '', checked: false };
			fields.push( f ); sel = f; selBlank = blanks.length ? selBlank : null; paint(); renderInsp(); renderBlankBar(); changed();
		}
		// Assign…: queue variables, then fill blanks in reading order — or one whole row (left to right) — in one go.
		var assignBox = el( 'div', { cls: 'ajfe-assign' } ), seq = [], rowOnly = true, assignOpen = false;
main.insertBefore( assignBox, pbody );
		function seqLabel( q ) { return '__skip' === q.tok ? 'skip a blank' : '__tick' === q.tok ? '☑ checked box' : '__box' === q.tok ? '☐ empty box' : String( merge[ q.tok ] || q.tok ).replace( /^(Customer|Business|Our company|Mailbox|Signer 1|Asked) — /, '' ); }
		function renderAssign() {
			assignBox.innerHTML = ''; assignBox.style.display = assignOpen && blanks.length ? 'block' : 'none';
			if ( ! assignOpen ) { return; }
			var head = el( 'div' ); head.style.cssText = 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:6px';
			head.appendChild( el( 'strong', { text: 'Assign in order' } ) );
			var rl = el( 'label' ), rc = el( 'input', { type: 'checkbox' } ); rl.style.cssText = 'display:inline-flex;align-items:center;gap:4px;cursor:pointer'; rc.style.cssText = 'width:auto;height:auto'; rc.checked = rowOnly;
			rl.appendChild( rc ); rl.appendChild( document.createTextNode( 'only the selected blank\'s row' ) ); rc.addEventListener( 'change', function () { rowOnly = rc.checked; } ); head.appendChild( rl );
			var go = el( 'button', { text: 'Apply (' + seq.length + ')' } ), cl = el( 'button', { text: 'Close' } ); go.disabled = ! seq.length;
			go.addEventListener( 'click', applySeq ); cl.addEventListener( 'click', function () { assignOpen = false; renderAssign(); } ); head.appendChild( go ); head.appendChild( cl );
			assignBox.appendChild( head );
			var chipsRow = el( 'div' ); chipsRow.style.cssText = 'display:flex;gap:4px;flex-wrap:wrap;margin-bottom:6px';
			if ( ! seq.length ) { chipsRow.appendChild( el( 'span', { cls: 'ajfe-hint', text: 'Pick variables below in the order they should fill the blanks (left to right, then down). Use “skip” to leave one.' } ) ); }
			seq.forEach( function ( q, i ) { var ch = el( 'button', { text: ( i + 1 ) + '. ' + seqLabel( q ) + ' ✕', title: 'Remove' } ); ch.addEventListener( 'click', function () { seq.splice( i, 1 ); renderAssign(); } ); chipsRow.appendChild( ch ); } );
			assignBox.appendChild( chipsRow );
			var q = el( 'input', { type: 'search', placeholder: 'Search variables to add…' } ), res = el( 'div' ); q.style.cssText = 'width:100%;max-width:340px;height:28px;border:1px solid #cbd5e1;border-radius:6px;padding:0 8px'; res.style.cssText = 'display:flex;gap:4px;flex-wrap:wrap;margin-top:6px';
			function fillRes() {
				res.innerHTML = ''; var term = q.value.toLowerCase().trim(), n = 0;
				[ [ '__skip', 'skip a blank' ], [ '__tick', '☑ checked box' ], [ '__box', '☐ empty box' ], [ '__ask', 'Ask when sending…' ] ].concat( Object.keys( merge ).map( function ( k ) { return [ k, merge[ k ] ]; } ) ).forEach( function ( m ) {
					if ( n >= 14 || ( term && m[1].toLowerCase().indexOf( term ) < 0 && m[0].toLowerCase().indexOf( term ) < 0 ) ) { return; }
					if ( ! term && n >= 8 ) { return; }
					n++; var b = el( 'button', { text: '+ ' + String( m[1] ).replace( /^(Customer|Business|Our company|Mailbox|Signer 1|Asked) — /, '' ) } );
					b.addEventListener( 'click', function () {
						if ( '__ask' === m[0] ) { var nm = window.prompt( 'Name of the value to ask for when sending (e.g. LLC name)' ), slug = ( nm || '' ).toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' ); if ( ! slug ) { return; } seq.push( { tok: 'ask.' + slug, fill: '{{ask.' + slug + '}}' } ); }
						else { seq.push( { tok: m[0], fill: m[0].indexOf( '__' ) === 0 ? '' : '{{' + m[0] + '}}' } ); }
						renderAssign();
					} );
					res.appendChild( b );
				} );
			}
			q.addEventListener( 'input', fillRes ); fillRes(); assignBox.appendChild( q ); assignBox.appendChild( res );
		}
		function applySeq() {
			if ( ! blanks.length || ! seq.length ) { return; }
			sortBlanks();
			var start = selBlank || blanks[ 0 ], pool;
			if ( rowOnly ) { pool = blanks.filter( function ( b ) { return b.page === start.page && Math.abs( b.y - start.y ) < 0.012; } ).sort( function ( a, b ) { return a.x - b.x; } ); }
			else { pool = blanks.slice( blanks.indexOf( start ) ); }
			var made = 0;
			seq.forEach( function ( q ) {
				var box = '__tick' === q.tok || '__box' === q.tok, i = -1, k;
				for ( k = 0; k < pool.length; k++ ) { if ( ( box && 'box' === pool[ k ].kind ) || ( ! box && 'line' === pool[ k ].kind ) ) { i = k; break; } }
				if ( i < 0 ) { return; }
				var b = pool.splice( i, 1 )[ 0 ];
				if ( '__skip' === q.tok ) { return; } // consumed from the queue, the blank itself stays
				var f = { type: box ? 'checkbox' : 'text', party: parseInt( partySel.value, 10 ) || 1, page: b.page, x: b.x, y: b.y, w: b.w, h: b.h, fill: box ? '' : q.fill, checked: '__tick' === q.tok };
				blanks.splice( blanks.indexOf( b ), 1 ); fields.push( f ); made++;
			} );
seq = []; multi = []; selBlank = blanks.length ? blanks[ 0 ] : null; sel = null;
			paint(); renderInsp(); renderBlankBar(); renderAssign(); changed();
			if ( made ) { updateCount(); }
		}
		function renderBlankList() {
			leftList.innerHTML = '';
			if ( ! blanks.length ) { leftList.style.display = 'none'; return; }
			leftList.style.display = 'block'; sortBlanks();
			var head = el( 'div' ); head.style.cssText = 'position:sticky;top:0;z-index:2;background:#fff;padding:6px 8px;border-bottom:1px solid #e2e8f0;display:flex;gap:5px;align-items:center;flex-wrap:wrap';
			head.appendChild( el( 'strong', { text: 'Blanks (' + blanks.length + ')' } ) );
			var all = el( 'button', { text: multi.length === blanks.length ? 'None' : 'All', title: 'Tick all / none' } ), del = el( 'button', { text: 'Delete (' + multi.length + ')', cls: 'dng', title: 'Delete the ticked blanks' } ); del.disabled = ! multi.length;
			all.addEventListener( 'click', function () { multi = multi.length === blanks.length ? [] : blanks.slice(); paint(); renderBlankBar(); } );
			del.addEventListener( 'click', function () { deleteBlanks( multi.slice() ); } );
			head.appendChild( all ); head.appendChild( del ); leftList.appendChild( head );
			var selRow = null;
			blanks.forEach( function ( b ) {
				var row = el( 'div', { cls: 'ajfe-brow' + ( b === selBlank ? ' sel' : '' ) + ( inMulti( b ) ? ' multi' : '' ), title: 'Click to jump · double-click to rename' } ), cb = el( 'input', { type: 'checkbox' } );
				cb.checked = inMulti( b );
				cb.addEventListener( 'click', function ( e ) { e.stopPropagation(); } );
				cb.addEventListener( 'change', function () { toggleMulti( b ); paint(); renderBlankBar(); } );
				row.appendChild( cb ); row.appendChild( el( 'span', { text: 'p' + b.page + ' · ' + ( 'box' === b.kind ? '☐ ' : '' ) + ( b.label || ( 'box' === b.kind ? 'Box' : 'Blank' ) ) } ) );
				row.addEventListener( 'click', function ( e ) { if ( e.shiftKey || e.ctrlKey || e.metaKey ) { toggleMulti( b ); selBlank = b; paint(); renderBlankBar(); } else { multi = []; selectBlank( b ); } layer.focus( { preventScroll: true } ); } );
				row.addEventListener( 'dblclick', function () { var nl = window.prompt( 'Label for this blank', b.label || '' ); if ( null !== nl ) { b.label = nl.trim().slice( 0, 60 ); changed(); paint(); renderBlankList(); } } );
				if ( b === selBlank ) { selRow = row; }
				leftList.appendChild( row );
			} );
			if ( selRow ) { selRow.scrollIntoView( { block: 'nearest' } ); }
		}
		function renderBlankBar() {
			renderBlankList();
			bar2.innerHTML = '';
			if ( ! blanks.length ) { bar2.style.display = 'none'; assignBox.style.display = 'none'; return; }
			bar2.style.display = 'flex'; sortBlanks();
			var lines = blanks.filter( function ( b ) { return 'line' === b.kind; } ).length, boxes = blanks.length - lines, idx = selBlank ? blanks.indexOf( selBlank ) + 1 : 0;
			function btn( text, fn, cls, title ) { var b = el( 'button', { text: text, cls: cls || '', title: title || '' } ); b.addEventListener( 'click', function () { fn(); layer.focus( { preventScroll: true } ); } ); bar2.appendChild( b ); return b; }
			bar2.appendChild( el( 'strong', { text: 'Blanks ' + ( idx ? idx + ' / ' : '' ) + blanks.length } ) );
			btn( '◀', function () { stepBlank( -1 ); }, '', 'Previous (←)' ); btn( '▶', function () { stepBlank( 1 ); }, '', 'Next (→)' );
			if ( multi.length > 1 ) { btn( 'Delete ' + multi.length + ' selected', function () { deleteBlanks( multi.slice() ); }, 'dng', 'Delete key' ); }
			if ( undoStack.length ) { btn( 'Undo', undoDelete, '', 'Ctrl/Cmd+Z' ); }
			if ( selBlank && multi.length < 2 ) {
				btn( 'Remove', function () { removeBlank( selBlank ); }, 'dng', 'Delete key' );
				btn( 'box' === selBlank.kind ? 'Make checkbox' : 'Make text field', function () { makeField( selBlank ); }, '', 'Enter or T' );
			}
			if ( boxes ) { btn( 'Remove all ☐ (' + boxes + ')', function () { removeKind( 'box' ); }, 'dng', 'Remove every checkbox-shaped blank' ); }
			if ( lines ) { btn( 'Remove all lines (' + lines + ')', function () { removeKind( 'line' ); }, 'dng', 'Remove every underline-shaped blank' ); }
			btn( 'Assign…', function () { assignOpen = ! assignOpen; renderAssign(); }, '', 'Fill blanks in order, or a whole row, with a queue of variables' );
btn( 'Clear', function () { deleteBlanks( blanks.slice() ); }, 'dng', 'Remove all blanks (Undo brings them back)' );
			bar2.appendChild( el( 'span', { cls: 'ajfe-hint', text:'← → step · Del remove · Shift-click or drag a rectangle to select several · drag a field onto a blank to fill it' } ) );
		}
		function placeTool( cx, cy ) { if ( 0 === tool.indexOf( 'merge:' ) ) { dropMerge( tool.slice( 6 ), cx, cy ); } else { dropAt( tool, cx, cy ); } }
		function paint() {
			layer.innerHTML = '';
			blanks.forEach( function ( bk ) {
				if ( bk.page !== pageNo ) { return; }
var d = el( 'div', { cls: 'ajfe-blank' + ( bk === selBlank ? ' sel' : '' ) + ( inMulti( bk ) ? ' multi' : '' ) } ); if ( bk.label ) { d.title = bk.label; if ( bk === selBlank ) { d.appendChild( el( 'span', { cls: 'ajfe-bl', text: bk.label } ) ); } }
				d.style.cssText = 'left:' + bk.x * 100 + '%;top:' + bk.y * 100 + '%;width:' + bk.w * 100 + '%;height:' + bk.h * 100 + '%';
				d.addEventListener( 'pointerdown', function ( e ) { e.stopPropagation(); e.preventDefault(); layer.focus( { preventScroll: true } );if ( tool ) { placeTool( e.clientX, e.clientY ); } else if ( e.shiftKey || e.ctrlKey || e.metaKey ) { if ( selBlank && ! inMulti( selBlank ) ) { multi.push( selBlank ); } toggleMulti( bk ); selBlank = bk; sel = null; paint(); renderBlankBar(); } else { multi = []; selectBlank( bk ); } } );
				layer.appendChild( d );
			} );
			fields.forEach( function ( f ) {
				if ( f.page !== pageNo ) { return; }
				var c = color( f.party ), b = el( 'div', { cls: 'ajfe-box' + ( f === sel ? ' sel' : '' ) } );
				b.style.cssText = 'left:' + f.x * 100 + '%;top:' + f.y * 100 + '%;width:' + f.w * 100 + '%;height:' + f.h * 100 + '%;border-color:' + c + ';background:' + c + '22;color:' + c;
				var label = ( TYPES.filter( function ( t ) { return t[0] === f.type; } )[0] || [ '', f.type ] )[1];
				if ( 'checkbox' === f.type ) { label = f.checked ? '✓' : ''; }
				if ( 'checkbox' === f.type && ! ( previewMode && f.fill ) ) { b.style.padding = '0'; b.style.background = c + '1a'; }
var showVals = previewMode && values && ( f.fill || ( 'checkbox' === f.type && f.checked ) ) && 'signature' !== f.type && 'initials' !== f.type;
				var pvText = showVals ? ( 'checkbox' === f.type && f.checked ? 'X' : resolveText( f.fill ).trim() ) : null;
				if ( showVals ) { // live view: the value as it will be printed
					var scale = canvas.width / pageW, hpt = f.h * pageH, wpt = f.w * pageW, fpt = hpt < 13 ? Math.max( 8, hpt * 0.85 ) : 11;
					b.style.background = 'transparent'; b.style.color = '#000'; b.style.borderStyle = 'dashed'; b.style.borderColor = c + '88';
					b.style.fontFamily = '"Times New Roman",Times,serif'; b.style.padding = '0 2px'; b.style.alignItems = 'flex-end';
					if ( 'checkbox' === f.type ) {
						b.style.padding = '0'; b.style.alignItems = 'center'; b.style.justifyContent = 'center'; b.style.fontWeight = '700'; b.style.fontSize = Math.max( 8, Math.min( 13, Math.min( wpt, hpt ) * 0.95 ) ) * scale + 'px';
						if ( pvText ) { b.appendChild( el( 'span', { text: 'X' } ) ); }
					}
					else if ( pvText ) {
						var mctx = ( paint._m = paint._m || document.createElement( 'canvas' ).getContext( '2d' ) );
						while ( fpt > 8 ) { mctx.font = fpt + 'px "Times New Roman", Times, serif'; if ( mctx.measureText( pvText ).width <= wpt - 2 ) { break; } fpt -= 0.5; }
						b.style.fontSize = fpt * scale + 'px'; b.style.paddingBottom = Math.max( 0, 2 * scale ) + 'px';
						b.appendChild( el( 'span', { text: pvText } ) );
					}
					else { var em = el( 'span', { text: 'signer fills in' } ); em.style.cssText = 'color:#94a3b8;font-style:italic;font-size:9px'; b.appendChild( em ); }
					} else {
						if ( 'label' === f.type ) { label = f.fill || 'Label'; }
						var lb = el( 'span', { text: label } ); if ( 'checkbox' === f.type ) { lb.style.cssText = 'display:block;width:100%;text-align:center;font-weight:700;font-size:11px'; }
						b.appendChild( lb );
if ( f.fill && 'checkbox' !== f.type && 'label' !== f.type ) { b.appendChild( el( 'i', { text: f.fill } ) ); }
					}
				var rz = el( 'span', { cls: 'rz' } ); b.appendChild( rz );
				b.addEventListener( 'pointerdown', function ( e ) {
					e.stopPropagation(); e.preventDefault();
						var resizing = e.target === rz, sx = e.clientX, sy = e.clientY, ox = f.x, oy = f.y, ow = f.w, oh = f.h, W = wrap.clientWidth, H = wrap.clientHeight, moved = false, wasSel = sel === f;
layer.focus( { preventScroll: true } ); if ( selBlank ) { selBlank = null; renderBlankBar(); }
						if ( sel !== f ) { sel = f; renderInsp(); syncSignerSel(); layer.querySelectorAll( '.sel' ).forEach( function ( n ) { n.classList.remove( 'sel' ); } ); b.classList.add( 'sel' ); }
					function mv( ev ) {
						if ( ! moved && Math.abs( ev.clientX - sx ) + Math.abs( ev.clientY - sy ) < 3 ) { return; } // a click, not a drag
						var dx = ( ev.clientX - sx ) / W, dy = ( ev.clientY - sy ) / H; moved = true;
						if ( resizing && 'checkbox' === f.type ) { f.w = Math.max( 0.012, Math.min( 1 - f.x, ow + dx ) ); f.h = Math.min( 1 - f.y, f.w * pageW / pageH ); f.w = f.h * pageH / pageW; b.style.width = f.w * 100 + '%'; b.style.height = f.h * 100 + '%'; }
else if ( resizing ) { f.manualW = true; f.w = Math.max( 0.008, Math.min( 1 - f.x, ow + dx ) ); f.h = Math.max( 0.006, Math.min( 1 - f.y, oh + dy ) ); b.style.width = f.w * 100 + '%'; b.style.height = f.h * 100 + '%'; }
						else { f.x = Math.max( 0, Math.min( 1 - f.w, ox + dx ) ); f.y = Math.max( 0, Math.min( 1 - f.h, oy + dy ) ); b.style.left = f.x * 100 + '%'; b.style.top = f.y * 100 + '%'; }
					}
						function up() {
							document.removeEventListener( 'pointermove', mv ); document.removeEventListener( 'pointerup', up );
							if ( moved ) { updateCount(); changed(); }
							else if ( ! resizing && wasSel && 'checkbox' === f.type ) { f.checked = ! f.checked; paint(); renderInsp(); changed(); } // clicking the selected checkbox ticks it
						}
					document.addEventListener( 'pointermove', mv ); document.addEventListener( 'pointerup', up );
				} );
				layer.appendChild( b );
			} );
			updateCount();
		}
		function updateCount() {
			var onPage = fields.filter( function ( f ) { return f.page === pageNo; } ).length;
			count.textContent = fields.length + ' fields · ' + onPage + ' here';
		}
		// Where a field of w × h pt lands for a cursor position: centred on the cursor, which is where the drag image was held.
		function placeAt( clientX, clientY, wpt, hpt ) {
			var r = layer.getBoundingClientRect(), w = wpt / pageW, h = hpt / pageH;
			return { w: w, h: h, x: Math.max( 0, Math.min( 1 - w, ( clientX - r.left ) / r.width - w / 2 ) ), y: Math.max( 0, Math.min( 1 - h, ( clientY - r.top ) / r.height - h / 2 ) ) };
		}
		var hint = null;
		function showHint( clientX, clientY ) {
			if ( ! dragging ) { return; }
			var p = placeAt( clientX, clientY, dragging.w, dragging.h ), bk = blankAtPoint( clientX, clientY, dragging.type );
			if ( bk ) { p = { x: bk.x, y: bk.y, w: bk.w, h: bk.h }; } // it will snap into this blank
			if ( ! hint ) { hint = el( 'div' ); hint.style.cssText = 'position:absolute;pointer-events:none;box-sizing:border-box;border:1.5px dashed #4f46e5;background:rgba(79,70,229,.12);border-radius:3px;z-index:3'; }
			if ( hint.parentNode !== layer ) { layer.appendChild( hint ); }
hint.style.left = p.x * 100 + '%'; hint.style.top = p.y * 100 + '%'; hint.style.width = p.w * 100 + '%'; hint.style.height = p.h * 100 + '%';
			hint.style.borderStyle = bk ? 'solid' : 'dashed'; hint.style.background = bk ? 'rgba(79,70,229,.28)' : 'rgba(79,70,229,.12)';
		}
		function hideHint() { if ( hint && hint.parentNode ) { hint.parentNode.removeChild( hint ); } }
		function dropAt( type, clientX, clientY, fillText, sizePt ) {
			var t = TYPES.filter( function ( x ) { return x[0] === type; } )[0]; if ( ! t ) { return; }
var sz = sizePt || [ t[2], t[3] ], p = placeAt( clientX, clientY, sz[0], sz[1] ), bk = blankAtPoint( clientX, clientY, type );
			if ( bk ) { p = { x: bk.x, y: bk.y, w: bk.w, h: bk.h }; dropBlank( bk ); renderBlankBar(); } // the field takes the blank's place and size
var f = { type: type, party: parseInt( partySel.value, 10 ) || 1, page: pageNo, x: p.x, y: p.y, w: p.w, h: p.h, fill: fillText || ( 'date' === type ? '{{date.short}}' : '' ), checked: false };
			fields.push( f ); sel = f; paint(); renderInsp(); changed();
		}
		// A variable's real text, when known: our company's own text, or the chosen customer's value once the preview has it.
		function mergeSize( tok ) {
			var v = values && values[ tok ];
			if ( ! v && 0 === String( tok ).indexOf( 'our.' ) ) { var lab = String( merge[ tok ] || '' ), i = lab.indexOf( ': ' ); if ( i >= 0 ) { v = lab.slice( i + 2 ); } }
			return v ? [ fitW( String( v ) ), 14 ] : tokenSize( tok );
		}
		function dropMerge( token, cx, cy ) {
			var fill = '{{' + token + '}}';
			if ( '__ask' === token ) {
				var nm = window.prompt( 'Name of the value to ask for when sending (e.g. LLC name)' ), slug = ( nm || '' ).toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' );
				if ( ! slug ) { return; }
				fill = '{{ask.' + slug + '}}';
			}
dropAt( 'text', cx, cy, fill, mergeSize( token ) );
		}
		layer.addEventListener( 'pointerdown', function ( e ) {
			if ( e.target !== layer ) { return; }
			layer.focus( { preventScroll: true } );
			if ( ! tool ) {
				var r0 = layer.getBoundingClientRect(), sx = e.clientX, sy = e.clientY, band = null, additive = e.shiftKey || e.ctrlKey || e.metaKey;
				function mv( ev ) {
					if ( ! band && Math.abs( ev.clientX - sx ) + Math.abs( ev.clientY - sy ) < 6 ) { return; }
					if ( ! band ) { band = el( 'div', { cls: 'ajfe-rb' } ); layer.appendChild( band ); }
					var l = Math.min( sx, ev.clientX ) - r0.left, t = Math.min( sy, ev.clientY ) - r0.top;
					band.style.cssText = 'left:' + l + 'px;top:' + t + 'px;width:' + Math.abs( ev.clientX - sx ) + 'px;height:' + Math.abs( ev.clientY - sy ) + 'px';
				}
				function up( ev ) {
					document.removeEventListener( 'pointermove', mv ); document.removeEventListener( 'pointerup', up );
					if ( ! band ) { sel = null; multi = []; if ( selBlank ) { selBlank = null; renderBlankBar(); } paint(); renderInsp(); return; }
					var x0 = ( Math.min( sx, ev.clientX ) - r0.left ) / r0.width, x1 = ( Math.max( sx, ev.clientX ) - r0.left ) / r0.width, y0 = ( Math.min( sy, ev.clientY ) - r0.top ) / r0.height, y1 = ( Math.max( sy, ev.clientY ) - r0.top ) / r0.height;
					var hit = blanks.filter( function ( b ) { return b.page === pageNo && b.x < x1 && b.x + b.w > x0 && b.y < y1 && b.y + b.h > y0; } );
					if ( ! additive ) { multi = []; }
					hit.forEach( function ( b ) { if ( ! inMulti( b ) ) { multi.push( b ); } } );
					sel = null; selBlank = multi[ multi.length - 1 ] || selBlank; paint(); renderInsp(); renderBlankBar();
				}
				document.addEventListener( 'pointermove', mv ); document.addEventListener( 'pointerup', up );
				return;
			}
			placeTool( e.clientX, e.clientY );
		} );
		layer.addEventListener( 'dragover', function ( e ) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; layer.classList.add( 'drop' ); showHint( e.clientX, e.clientY ); } );
		layer.addEventListener( 'dragleave', function () { layer.classList.remove( 'drop' ); hideHint(); } );
		layer.addEventListener( 'drop', function ( e ) {
			e.preventDefault(); layer.classList.remove( 'drop' ); hideHint(); dragging = null;
			var d = e.dataTransfer.getData( 'text/plain' ) || '';
			if ( 0 === d.indexOf( 'ajfe:merge:' ) ) { dropMerge( d.slice( 11 ), e.clientX, e.clientY ); } else if ( 0 === d.indexOf( 'ajfe:' ) ) { dropAt( d.slice( 5 ), e.clientX, e.clientY ); }
		} );

		// ── pages ────────────────────────────────────────────────────────────────
		var fitW = 0, fitTimer = null;
		function showPage( n ) {
			if ( ! pdf || rendering || dead ) { return; }
			pageNo = Math.max( 1, Math.min( pdf.numPages, n ) ); rendering = true; sel = null;
			pdf.getPage( pageNo ).then( function ( page ) {
				var v1 = page.getViewport( { scale: 1 } ); pageW = v1.width; pageH = v1.height;
var avail = stage.clientWidth - 20, scale = Math.min( 2.5, Math.max( 0.6, avail / v1.width ) ), vp = page.getViewport( { scale: scale } );
				fitW = stage.clientWidth;
				canvas.width = Math.floor( vp.width ); canvas.height = Math.floor( vp.height );
				wrap.style.width = canvas.width + 'px'; wrap.style.height = canvas.height + 'px';
				return page.render( { canvasContext: canvas.getContext( '2d' ), viewport: vp } ).promise;
			} ).then( function () { pgLabel.textContent = 'Page ' + pageNo + ' / ' + pdf.numPages;rendering = false; paint(); renderInsp(); if ( pendingScroll ) { pendingScroll = false; scrollToBlank(); } } )
				.catch( function ( e ) { rendering = false; window.alert( 'Could not show the page: ' + ( e && e.message ? e.message : e ) ); } );
		}
		// The pane may not have its final width when the page first renders (hidden tab, panel still laying out): re-fit when it changes.
		if ( window.ResizeObserver ) {
			var ro = new ResizeObserver( function () {
				clearTimeout( fitTimer );
				fitTimer = setTimeout( function () { if ( pdf && ! dead && stage.clientWidth > 0 && Math.abs( stage.clientWidth - fitW ) > 24 ) { showPage( pageNo ); } }, 150 );
			} );
			ro.observe( stage );
		}
		prev.addEventListener( 'click', function () { showPage( pageNo - 1 ); } );
		next.addEventListener( 'click', function () { showPage( pageNo + 1 ); } );

		// ── auto-detect: underscore runs, drawn underlines, squares ──────────────
		function detectPage( page, no ) {
			var v = page.getViewport( { scale: 1 } ), W = v.width, H = v.height, found = [];
var textItems = [];
				function add( type, x, topY, w, h, label ) { found.push( { type: type, party: parseInt( partySel.value, 10 ) || 1, page: no, x: x / W, y: topY / H, w: w / W, h: h / H, fill: '', label: label || '' } ); }
				function tidy( t ) { t = String( t || '' ).replace( /_+/g, ' ' ).replace( /\s+/g, ' ' ).trim().replace( /[:\s]+$/, '' ); return t.length > 44 ? '…' + t.slice( -43 ) : t; }
			return page.getTextContent().then( function ( tc ) {
				tc.items.forEach( function ( it ) {
						var s = it.str; if ( ! s ) { return; }
						var tr = it.transform, fh = Math.abs( tr[3] ) || it.height || 10, L = s.length;
						if ( s.replace( /[_\s]/g, '' ) ) { textItems.push( { s: s, x0: tr[4], x1: tr[4] + it.width, yb: H - tr[5] } ); }
						if ( s.indexOf( '___' ) < 0 ) { return; }
						var re = /_{3,}/g, m, prevEnd = 0;
						while ( ( m = re.exec( s ) ) ) { add( 'text', tr[4] + it.width * m.index / L, H - ( tr[5] + fh * 0.9 ), it.width * m[0].length / L, fh * 1.15, tidy( s.slice( prevEnd, m.index ) ) ); prevEnd = m.index + m[0].length; }
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
					// Name each blank after the text around it: the words before a line, the header above it, the word after a box.
					found.forEach( function ( f ) {
						if ( f.label ) { return; }
						var bx = f.x * W, bw = f.w * W, btop = f.y * H, bbot = btop + f.h * H, best = null, bd = 1e9;
						if ( 'checkbox' === f.type ) {
							textItems.forEach( function ( t ) { var gap = t.x0 - ( bx + bw ); if ( Math.abs( t.yb - ( bbot - 1 ) ) < 9 && gap > -3 && gap < 22 && gap < bd ) { bd = gap; best = t; } } );
							if ( best ) { f.label = tidy( best.s.split( /\s+/ ).slice( 0, 2 ).join( ' ' ) ); }
						} else {
							textItems.forEach( function ( t ) { var gap = bx - t.x1; if ( Math.abs( t.yb - ( bbot - 2 ) ) < 9 && gap > -4 && gap < 260 && gap < bd ) { bd = gap; best = t; } } );
							if ( ! best ) { bd = 1e9; textItems.forEach( function ( t ) { var up = btop - t.yb; if ( t.x0 <= bx + bw && t.x1 >= bx && up > -2 && up < 34 && up < bd ) { bd = up; best = t; } } ); }
							if ( best ) { f.label = tidy( best.s ); }
						}
						if ( ! f.label ) { f.label = 'checkbox' === f.type ? 'Box' : 'Blank'; }
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
				if ( n > pdf.numPages ) {
					detect.disabled = false; detect.textContent = 'Auto-detect'; sortBlanks();
if ( added ) { changed(); selectBlank( blanks[ 0 ] ); } else { paint(); renderBlankBar(); window.alert( 'No blanks found. Drag fields onto the page instead.' ); }
					return;
				}
				pdf.getPage( n ).then( function ( page ) { return detectPage( page, n ); } ).then( function ( list ) {
					list.forEach( function ( f ) {
						var bk = { page: f.page, x: f.x, y: f.y, w: f.w, h: f.h,kind: 'checkbox' === f.type ? 'box' : 'line', label: f.label || '' };
if ( ! fields.some( function ( x ) { return x.page === bk.page && overlaps( x, bk ); } ) && ! blanks.some( function ( x ) { return x.page === bk.page && overlaps( x, bk ); } ) ) { blanks.push( bk ); added++; }
					} );
					n++; step();
				} ).catch( function () { n++; step(); } );
			}
			step();
		} );

		// ── start ────────────────────────────────────────────────────────────────
		renderSigners(); setTool( '' ); renderInsp(); updateCount(); renderBlankBar();
		loadPdfjs( opts.pdfjsBase, function () {
			if ( dead ) { return; }
			window.pdfjsLib.getDocument( { data: new Uint8Array( opts.pdfData ) } ).promise.then( function ( d ) { pdf = d; showPage( 1 ); } )
				.catch( function ( e ) { stage.innerHTML = ''; stage.appendChild( el( 'p', { cls: 'ajfe-hint', text: 'Could not open the PDF: ' + ( e && e.message ? e.message : e ) } ) ); } );
		}, function ( m ) { stage.textContent = m; } );

		return {
			getFields: function () { return fields.map( clean ); },
			getBlanks: function () { return blanks.map( function ( b ) { return { page: b.page, x: round( b.x ), y: round( b.y ), w: round( b.w ), h:round( b.h ), kind: b.kind, label: b.label || '' }; } ); },
			getSigners: function () { return signers.slice(); },
			refreshValues: function () { if ( previewMode ) { loadValues(); } },
			setSigners: function ( list ) { signers = list && list.length ? list.slice() : [ 'Signer' ]; renderSigners(); renderInsp(); paint(); },
destroy: function () { dead = true; if ( window.ResizeObserver && typeof ro !== 'undefined' ) { ro.disconnect(); } document.removeEventListener( 'keydown', onKey ); host.innerHTML = ''; }
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
