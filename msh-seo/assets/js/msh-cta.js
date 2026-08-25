/**
 * MSH SEO — intent-personalized CTA + conversion tracking (no dependencies).
 *
 * Classifies the visitor's intent from referrer / UTM / returning status, swaps
 * the CTA to the matching variant, and reports impression + click events to the
 * plugin (which forwards them to the MSH brain). SSR renders the default
 * variant, so the CTA works even with JS disabled.
 */
( function () {
	function classifyIntent() {
		try {
			var params = new URLSearchParams( location.search );
			var ref = document.referrer || '';
			var refHost = '';
			try { refHost = ref ? new URL( ref ).hostname : ''; } catch ( e ) {}
			var sameSite = refHost && location.hostname.indexOf( refHost.replace( /^www\./, '' ) ) !== -1;

			var haystack = (
				ref + ' ' + location.search + ' ' +
				( params.get( 'utm_campaign' ) || '' ) + ' ' +
				( params.get( 'utm_term' ) || '' )
			).toLowerCase();

			var medium = ( params.get( 'utm_medium' ) || '' ).toLowerCase();
			var highIntent =
				/(pricing|price|buy|purchase|demo|trial|quote|vs|versus|alternative|alternatives|best|top|review|compare|comparison|switch)/.test( haystack ) ||
				[ 'cpc', 'ppc', 'paid', 'email', 'affiliate' ].indexOf( medium ) !== -1;

			var returning = false;
			try {
				returning = !! localStorage.getItem( 'msh_seen' );
				localStorage.setItem( 'msh_seen', '1' );
			} catch ( e ) {}

			var fromSearch = /(google\.|bing\.|duckduckgo\.|yahoo\.|ecosia\.|search\?)/.test( refHost + ' ' + ref );

			if ( highIntent ) return 'high_intent';
			if ( returning ) return 'returning';
			if ( fromSearch && ! sameSite ) return 'research';
			return 'default';
		} catch ( e ) {
			return 'default';
		}
	}

	function sendEvent( type, bucket ) {
		try {
			if ( ! window.mshCta || ! window.mshCta.endpoint ) return;
			var payload = JSON.stringify( { url: location.href, type: type, bucket: bucket } );
			if ( navigator.sendBeacon ) {
				navigator.sendBeacon( window.mshCta.endpoint, new Blob( [ payload ], { type: 'application/json' } ) );
			} else {
				fetch( window.mshCta.endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: payload,
					keepalive: true,
				} );
			}
		} catch ( e ) {}
	}

	function initCta( el ) {
		var bucket = classifyIntent();
		var variants = {};
		try { variants = JSON.parse( el.getAttribute( 'data-variants' ) || '{}' ); } catch ( e ) {}
		var v = variants[ bucket ] || variants.default;

		if ( v ) {
			var h = el.querySelector( '.msh-cta__headline' );
			var b = el.querySelector( '.msh-cta__body' );
			var btn = el.querySelector( '[data-msh-cta-btn]' );
			if ( h && v.headline ) h.textContent = v.headline;
			if ( b && v.body ) b.textContent = v.body;
			if ( btn ) {
				if ( v.button_label ) btn.textContent = v.button_label;
				if ( v.button_url && /^https?:\/\//i.test( v.button_url ) ) btn.setAttribute( 'href', v.button_url );
			}
		}
		el.setAttribute( 'data-bucket', bucket );

		var impressed = false;
		function impress() {
			if ( impressed ) return;
			impressed = true;
			sendEvent( 'impression', bucket );
		}
		if ( 'IntersectionObserver' in window ) {
			var io = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) { impress(); io.disconnect(); }
				} );
			}, { threshold: 0.4 } );
			io.observe( el );
		} else {
			impress();
		}

		var clickBtn = el.querySelector( '[data-msh-cta-btn]' );
		if ( clickBtn ) {
			clickBtn.addEventListener( 'click', function () { sendEvent( 'click', bucket ); } );
		}
	}

	function boot() {
		var nodes = document.querySelectorAll( '[data-msh-cta]' );
		for ( var i = 0; i < nodes.length; i++ ) initCta( nodes[ i ] );
	}

	if ( document.readyState !== 'loading' ) boot();
	else document.addEventListener( 'DOMContentLoaded', boot );
} )();
