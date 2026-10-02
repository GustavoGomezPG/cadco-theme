/**
 * Page-transition curtain.
 *
 * Taxi fades the outgoing view out and only appends the incoming one once the
 * fetch resolves. Between those two moments the document has no view at all, so
 * a navigation started before the hover prefetch finished showed an empty page
 * until the server answered -- which reads as a broken site rather than a slow
 * one.
 *
 * The curtain covers that window. It wipes closed on proto:page-leave, stays
 * closed for however long the fetch takes, and wipes open on proto:page-ready.
 * The existing ProtoFade still runs underneath; it is simply no longer visible.
 *
 * It lives on <body>, outside [data-taxi], so the view swap never removes it.
 *
 * Panels wipe in sequence rather than as one block: a stagger reads as
 * deliberate where a single slab reads as a stall, which is the whole point
 * when the thing it is hiding is an unknown wait.
 */
(function () {
	'use strict';

	var PANELS = 5;

	/*
	 * Closing is deliberately quicker than opening. ProtoFade takes the
	 * outgoing view to opacity 0 over 0.4s, so anything still uncovered at
	 * that point shows the blank this curtain exists to hide -- measured at
	 * the original 0.5s/0.06s the last panel was only a quarter closed when
	 * the page had already gone. Cover therefore finishes in
	 * 0.3 + 4x0.035 = 0.44s, near enough that the fade is doing the rest.
	 *
	 * Opening has no such deadline: the page underneath is already complete,
	 * so it can take its time and read as a reveal rather than a flinch.
	 */
	var COVER_DURATION  = 0.3;
	var COVER_STAGGER   = 0.035;
	var REVEAL_DURATION = 0.5;
	var REVEAL_STAGGER  = 0.06;
	var EASE_IN         = 'power3.in';
	var EASE_OUT        = 'power3.inOut';

	/*
	 * If a navigation dies after the curtain closes, nothing reopens it and the
	 * site is behind an opaque panel for good -- strictly worse than the blank
	 * page this replaces. proto-taxi.js documents exactly that failure: a
	 * listener throwing inside afterFetch rejects the promise, so NAVIGATE_END
	 * (and therefore proto:page-ready) never runs. This is the backstop.
	 */
	var FAILSAFE_MS = 8000;

	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');

	var root     = null;
	var panels   = [];
	var covering = null;  // resolves when the cover animation has finished
	var failsafe = null;

	function build() {
		if (root) { return root; }

		root = document.createElement('div');
		root.className = 'cadco-curtain';
		root.setAttribute('aria-hidden', 'true');
		root.style.cssText = [
			'position:fixed',
			'inset:0',
			'z-index:2147483000',
			'display:flex',
			'pointer-events:none',
			'visibility:hidden'
		].join(';');

		for (var i = 0; i < PANELS; i++) {
			var panel = document.createElement('div');

			/* scaleY with a switched origin gives the wipe without animating
			   layout: closing grows each panel down from the top, opening
			   shrinks it away to the bottom, so the two halves read as one
			   continuous movement rather than a bounce. */
			panel.style.cssText = [
				'flex:1 1 0%',
				'background:#00476e',
				'transform:scaleY(0)',
				'transform-origin:top',
				'will-change:transform'
			].join(';');

			root.appendChild(panel);
			panels.push(panel);
		}

		document.body.appendChild(root);

		return root;
	}

	function show() { build().style.visibility = 'visible'; root.style.pointerEvents = 'auto'; }
	function hide() { if (root) { root.style.visibility = 'hidden'; root.style.pointerEvents = 'none'; } }

	function clearFailsafe() {
		if (failsafe) { window.clearTimeout(failsafe); failsafe = null; }
	}

	function cover() {
		build();
		show();

		var gsap = window.gsap;

		clearFailsafe();
		failsafe = window.setTimeout(function () {
			console.warn('[cadco-curtain] no page-ready within ' + FAILSAFE_MS + 'ms; opening anyway');
			reveal();
		}, FAILSAFE_MS);

		if (!gsap || (reduced && reduced.matches)) {
			panels.forEach(function (p) { p.style.transformOrigin = 'top'; p.style.transform = 'scaleY(1)'; });
			covering = Promise.resolve();
			return covering;
		}

		covering = new Promise(function (resolve) {
			gsap.killTweensOf(panels);
			gsap.set(panels, { transformOrigin: 'top' });
			gsap.fromTo(
				panels,
				{ scaleY: 0 },
				{
					scaleY: 1,
					duration: COVER_DURATION,
					ease: EASE_IN,
					stagger: COVER_STAGGER,
					onComplete: resolve
				}
			);
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
				hide();
				return;
			}

			gsap.killTweensOf(panels);
			gsap.set(panels, { transformOrigin: 'bottom' });
			gsap.to(panels, {
				scaleY: 0,
				duration: REVEAL_DURATION,
				ease: EASE_OUT,
				stagger: REVEAL_STAGGER,
				onComplete: hide
			});
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
