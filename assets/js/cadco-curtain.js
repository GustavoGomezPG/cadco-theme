/**
 * Page-transition curtain.
 *
 * Taxi fades the outgoing view out and only appends the incoming one once the
 * fetch resolves. Between those two moments the document has no view at all, so
 * a navigation started before the hover prefetch finished showed an empty page
 * until the server answered -- which reads as a broken site rather than a slow
 * one.
 *
 * The curtain covers that window. It wipes closed on proto:page-leave, holds
 * the Cadco mark for however long the fetch takes, and wipes open on
 * proto:page-ready. It lives on <body>, outside [data-taxi], so the view swap
 * never removes it.
 *
 * Holding the outgoing page
 * -------------------------
 * The old view must not start dissolving before the curtain is over it. Its
 * fade lives in ProtoFade inside the vendored scripts/proto-taxi.js, which is
 * not ours to edit, and its onComplete is what calls props.done() -- cancel the
 * tween and navigation never proceeds. So the tween is left alone to run and
 * fire on schedule, and the outgoing container is pinned opaque with a CSS
 * !important rule for as long as the curtain is closing. The fade still
 * happens; it is simply not visible.
 *
 * That leaves removal, which Taxi does after done() at 0.4s. The cover is timed
 * to finish at ~0.34s so the view is taken away behind a curtain that is
 * already fully closed, rather than through one still on its way down.
 */
(function () {
	'use strict';

	var PANELS = 5;

	/*
	 * Closing has a deadline: ProtoFade calls done() at 0.4s and Taxi removes
	 * the view immediately after, so the curtain has to be shut before then or
	 * the removal shows through. 0.24 + 4x0.026 = 0.344s.
	 *
	 * Opening has no deadline -- the page underneath is already complete -- so
	 * it keeps the slower timing and reads as a reveal rather than a flinch.
	 */
	var COVER_DURATION  = 0.24;
	var COVER_STAGGER   = 0.026;
	var REVEAL_DURATION = 0.5;
	var REVEAL_STAGGER  = 0.06;
	var EASE_IN         = 'power3.in';
	var EASE_OUT        = 'power3.inOut';

	var HOLD_CLASS = 'cadco-curtain-holding';

	/*
	 * If a navigation dies after the curtain closes, nothing reopens it and the
	 * site is behind an opaque panel for good -- strictly worse than the blank
	 * page this replaces. proto-taxi.js documents exactly that failure: a
	 * listener throwing inside afterFetch rejects the promise, so NAVIGATE_END
	 * (and therefore proto:page-ready) never runs. This is the backstop.
	 */
	var FAILSAFE_MS = 8000;

	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');
	var markUrl = (window.cadcoCurtain && window.cadcoCurtain.mark) || '';

	var target   = null;   // where the pending navigation is headed
	var root     = null;
	var panels   = [];
	var mark     = null;
	var held     = null;   // the outgoing container pinned opaque
	var covering = null;   // resolves when the cover animation has finished
	var failsafe = null;

	/*
	 * A prefetched page needs no curtain.
	 *
	 * Taxi caches a page on link hover and keys the cache by absolute URL. The
	 * entry only appears once the prefetch has *resolved* -- an in-flight one
	 * reads as a miss -- so cache.has() is exactly the question worth asking:
	 * is this navigation going to wait for the network at all? When it is not,
	 * the view swap is immediate and covering it would add a half-second of
	 * theatre to a transition that had none.
	 *
	 * The destination is not on the page-leave event, so it is taken from the
	 * click that started the navigation, falling back to the address bar for
	 * history moves. Anything we cannot resolve counts as a miss and gets the
	 * curtain: a needless curtain is a far smaller fault than a blank page.
	 */
	function isPrefetched(url) {
		if (!url) { return false; }

		try {
			var cache = window.protoTaxi
				&& window.protoTaxi.core
				&& window.protoTaxi.core.cache;

			return !!(cache && typeof cache.has === 'function' && cache.has(url));
		} catch (err) {
			return false;
		}
	}

	document.addEventListener('click', function (e) {
		var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;

		if (a && a.href) { target = a.href; }
	}, true);

	/* Back and forward never fire a click; by the time Taxi acts the address
	   bar already holds the destination. */
	window.addEventListener('popstate', function () { target = window.location.href; });

	/* Pinning the outgoing view opaque needs to beat GSAP's inline style, which
	   only !important does. Injected once, with the script rather than in the
	   head, so the rule sits next to the logic that depends on it. */
	function injectHoldRule() {
		if (document.getElementById('cadco-curtain-hold')) { return; }

		var style = document.createElement('style');
		style.id = 'cadco-curtain-hold';
		style.textContent =
			'.' + HOLD_CLASS + ',.' + HOLD_CLASS + ' main{opacity:1!important}';
		document.head.appendChild(style);
	}

	function build() {
		if (root) { return root; }

		root = document.createElement('div');
		root.className = 'cadco-curtain';
		root.setAttribute('aria-hidden', 'true');
		root.style.cssText = [
			'position:fixed',
			'inset:0',
			'z-index:2147483000',
			'pointer-events:none',
			'visibility:hidden'
		].join(';');

		var rail = document.createElement('div');
		rail.style.cssText = 'position:absolute;inset:0;display:flex';

		for (var i = 0; i < PANELS; i++) {
			var panel = document.createElement('div');

			/* scaleY with a switched origin gives the wipe without animating
			   layout: closing grows each panel down from the top, opening
			   shrinks it away to the bottom, so the two halves read as one
			   continuous movement rather than a bounce. */
			panel.style.cssText = [
				'flex:1 1 0%',
				'background:#000000',
				'transform:scaleY(0)',
				'transform-origin:top',
				'will-change:transform'
			].join(';');

			rail.appendChild(panel);
			panels.push(panel);
		}

		root.appendChild(rail);

		if (markUrl) {
			mark = document.createElement('img');
			mark.src = markUrl;
			mark.alt = '';
			mark.setAttribute('aria-hidden', 'true');
			mark.style.cssText = [
				'position:absolute',
				'top:50%',
				'left:50%',
				'width:min(180px,38vw)',
				'height:auto',
				'transform:translate(-50%,-50%)',
				'opacity:0',
				'will-change:opacity'
			].join(';');
			root.appendChild(mark);
		}

		document.body.appendChild(root);

		return root;
	}

	function show() {
		build().style.visibility = 'visible';
		root.style.pointerEvents = 'auto';
	}

	function hide() {
		if (!root) { return; }
		root.style.visibility = 'hidden';
		root.style.pointerEvents = 'none';
	}

	function release() {
		if (held) { held.classList.remove(HOLD_CLASS); held = null; }
	}

	function clearFailsafe() {
		if (failsafe) { window.clearTimeout(failsafe); failsafe = null; }
	}

	function cover(e) {
		var dest = target;

		target = null;

		if (isPrefetched(dest)) { return Promise.resolve(); }

		injectHoldRule();
		build();
		show();

		/* Pin the view that is on its way out so it stays solid behind the
		   closing panels instead of dissolving in front of them. */
		var container = (e && e.detail && e.detail.container) || document.querySelector('[data-taxi-view]');

		if (container && container.classList) {
			held = container;
			held.classList.add(HOLD_CLASS);
		}

		var gsap = window.gsap;

		clearFailsafe();
		failsafe = window.setTimeout(function () {
			console.warn('[cadco-curtain] no page-ready within ' + FAILSAFE_MS + 'ms; opening anyway');
			reveal();
		}, FAILSAFE_MS);

		if (!gsap || (reduced && reduced.matches)) {
			panels.forEach(function (p) { p.style.transformOrigin = 'top'; p.style.transform = 'scaleY(1)'; });
			if (mark) { mark.style.opacity = '1'; }
			covering = Promise.resolve();
			return covering;
		}

		covering = new Promise(function (resolve) {
			gsap.killTweensOf(panels);
			if (mark) { gsap.killTweensOf(mark); }

			gsap.set(panels, { transformOrigin: 'top' });

			var tl = gsap.timeline({ onComplete: resolve });

			tl.fromTo(
				panels,
				{ scaleY: 0 },
				{ scaleY: 1, duration: COVER_DURATION, ease: EASE_IN, stagger: COVER_STAGGER }
			);

			/* The mark arrives once the panels are down, so it is never seen
			   sitting on a half-covered page. */
			if (mark) {
				tl.to(mark, { opacity: 1, duration: 0.25, ease: 'power2.out' }, '>-0.05');
			}
		});

		return covering;
	}

	function reveal() {
		if (!root) { return; }

		clearFailsafe();

		/* A fast response can land before the cover has finished. Opening from
		   a half-closed curtain looks like a glitch, so the reveal always waits
		   for the close to complete first. */
		var ready = covering || Promise.resolve();

		ready.then(function () {
			var gsap = window.gsap;

			if (!gsap || (reduced && reduced.matches)) {
				panels.forEach(function (p) { p.style.transform = 'scaleY(0)'; });
				if (mark) { mark.style.opacity = '0'; }
				hide();
				release();
				return;
			}

			gsap.killTweensOf(panels);
			if (mark) { gsap.killTweensOf(mark); }

			var tl = gsap.timeline({
				onComplete: function () { hide(); release(); }
			});

			if (mark) {
				tl.to(mark, { opacity: 0, duration: 0.2, ease: 'power2.in' });
			}

			gsap.set(panels, { transformOrigin: 'bottom' });
			tl.to(
				panels,
				{ scaleY: 0, duration: REVEAL_DURATION, ease: EASE_OUT, stagger: REVEAL_STAGGER },
				mark ? '>-0.05' : 0
			);
		});
	}

	document.addEventListener('proto:page-leave', cover);
	document.addEventListener('proto:page-ready', function () {
		/* page-ready also fires on the very first load, when nothing has
		   covered anything; there is no curtain to open then. */
		if (root && root.style.visibility === 'visible') { reveal(); }
	});

	/* Restoring from the back/forward cache re-shows the document as it was
	   left. If that was mid-navigation, the curtain is still closed. */
	window.addEventListener('pageshow', function (e) {
		if (e.persisted) { reveal(); }
	});
})();
