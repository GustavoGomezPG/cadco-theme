/**
 * Cadco hero — entrance animation.
 *
 * Background photograph eases out of a slow push-in while the copy arrives:
 * eyebrow, then the heading revealed line by line out of a mask (GSAP
 * SplitText), then the button.
 *
 * Two pieces of sequencing matter here.
 *
 * 1. The intro overlay. proto-intro.js has two exits and only one fires an event:
 *
 *      fresh session   overlay plays -> finish() -> `proto:intro-complete`
 *      repeat visit    the inline <head> guard adds .proto-intro-skip, the
 *                      overlay is removed at once and NO event is dispatched
 *
 *    Waiting on the event alone would strand the hero on every navigation after
 *    the first. gate() covers both, plus the case where this script runs so late
 *    that the intro has already been and gone.
 *
 * 2. The webfont. Barlow is fetched async, so splitting before it swaps measures
 *    line boxes in the fallback face — and worse, SplitText's autoSplit then
 *    re-splits on the swap and throws away the very elements the timeline is
 *    animating, leaving a heading that never reveals. So: fonts first, then
 *    split, then gate.
 *
 * Loaded as `proto-blocks-cadco-hero`; functions.php appends proto-gsap and
 * proto-split-text to its dependencies. Absent either, the hero simply shows.
 */
(function () {
	'use strict';

	var PENDING = 'is-anim-pending';

	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); return; }
		document.addEventListener('DOMContentLoaded', fn, { once: true });
	}

	/**
	 * Run `fn` once the heading's own face is usable.
	 *
	 * document.fonts.ready is not enough on its own: it resolves when the loads
	 * pending *at that moment* settle, so a face still being discovered resolves
	 * it early, swaps in afterwards, and autoSplit re-splits on top of a reveal
	 * that is already running. Asking for the exact face first closes that gap.
	 */
	function whenFonts(el, fn) {
		var once = false;
		var go = function () { if (once) { return; } once = true; fn(); };

		if (!document.fonts || !document.fonts.ready) { return go(); }

		var waits = [];
		try {
			var cs = window.getComputedStyle(el);
			var family = cs.fontFamily.split(',')[0].replace(/["']/g, '').trim();
			if (family) { waits.push(document.fonts.load(cs.fontWeight + ' ' + cs.fontSize + ' "' + family + '"')); }
		} catch (e) { /* fall through to fonts.ready alone */ }
		waits.push(document.fonts.ready);

		Promise.all(waits).then(function () { return document.fonts.ready; }).then(go).catch(go);

		// Never let a stalled font host hold the hero back.
		window.setTimeout(go, 3000);
	}

	/** Run `fn` once the intro is out of the way — immediately if there is none. */
	function gate(fn) {
		var once = false;
		var go = function () { if (once) { return; } once = true; fn(); };

		// Seen this session: the overlay is torn down without an event.
		if (document.documentElement.classList.contains('proto-intro-skip')) { return go(); }

		// No overlay in the document: either this template has none, or it has
		// already finished and removed itself before we got here.
		if (!document.querySelector('.proto-intro')) { return go(); }

		document.addEventListener('proto:intro-complete', go, { once: true });

		// proto-intro.js caps itself at 8s. Outlast that so a broken or missing
		// Lottie can never leave the hero stuck in its pre-animation state.
		window.setTimeout(go, 9000);
	}

	function init(section) {
		var img     = section.querySelector('[data-hero-image]');
		var eyebrow = section.querySelector('[data-hero-eyebrow]');
		var heading = section.querySelector('[data-hero-heading]');
		var cta     = section.querySelector('[data-hero-cta]');

		var reveal = function () { section.classList.remove(PENDING); };

		// No GSAP, or the visitor asked for less motion: show it, plainly.
		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		if (!window.gsap || reduce) { reveal(); return; }

		var gsap  = window.gsap;
		var Split = window.SplitText;
		if (Split && gsap.registerPlugin) { gsap.registerPlugin(Split); }

		var gateOpen = false;   // the intro is done with; the sequence may run
		var settled  = false;   // the heading has finished revealing
		var split    = null;
		var linesTween = null;

		/**
		 * Reveal (or re-reveal) the heading's current line elements.
		 *
		 * Keyed on *finished*, not *started*: a re-split mid-reveal hands us a new
		 * set of nodes while the old tween is animating detached ones, so the
		 * reveal has to pick up on the new lines rather than snap them visible.
		 * Snapping is only right once the reader has already watched it play.
		 */
		var revealLines = function (lines) {
			if (linesTween) { linesTween.kill(); }
			linesTween = gsap.fromTo(
				lines,
				{ yPercent: 110 },
				{
					yPercent: 0,
					duration: 0.9,
					stagger: 0.09,
					ease: 'power3.out',
					onComplete: function () { settled = true; }
				}
			);
			return linesTween;
		};

		whenFonts(heading || section, function () {
			if (Split && heading) {
				split = Split.create(heading, {
					type: 'lines',
					mask: 'lines',
					autoSplit: true,
					aria: 'auto',
					// Fires on the first split and again on every re-split — a font
					// landing late, or the element changing width. A re-split discards
					// the very elements the tween is animating, so the current state
					// has to be re-established on the new ones.
					onSplit: function (self) {
						if (!gateOpen) { gsap.set(self.lines, { yPercent: 110 }); return; }
						if (settled)   { gsap.set(self.lines, { yPercent: 0 }); return; }
						return revealLines(self.lines);
					}
				});
			}

			gate(function () {
				var lines = split && split.lines && split.lines.length ? split.lines : null;

				// Pin the start state inline *before* dropping the CSS that is
				// holding everything back, so no frame shows the un-animated hero.
				if (img)     { gsap.set(img, { scale: 1.18, transformOrigin: '50% 50%' }); }
				if (eyebrow) { gsap.set(eyebrow, { autoAlpha: 0, y: 24 }); }
				if (cta)     { gsap.set(cta, { autoAlpha: 0, y: 20 }); }
				if (lines)        { gsap.set(lines, { yPercent: 110 }); }
				else if (heading) { gsap.set(heading, { autoAlpha: 0, y: 24 }); }

				gateOpen = true;
				reveal();

				var tl = gsap.timeline({ defaults: { ease: 'power3.out' } });

				// The photograph settles across the whole sequence — slow and
				// ambient, started first so the copy lands against movement
				// rather than against a still frame.
				if (img) { tl.to(img, { scale: 1, duration: 2.2, ease: 'power2.out' }, 0); }

				if (eyebrow) { tl.to(eyebrow, { autoAlpha: 1, y: 0, duration: 0.6 }, 0.15); }

				// Driven through a callback rather than added to the timeline, so a
				// re-split can rebuild it against the new nodes without tearing a
				// hole in the master sequence.
				if (lines) {
					tl.call(function () { revealLines(split.lines); }, null, 0.3);
				} else if (heading) {
					tl.to(heading, { autoAlpha: 1, y: 0, duration: 0.9 }, 0.3);
				}

				if (cta) { tl.to(cta, { autoAlpha: 1, y: 0, duration: 0.6 }, 0.85); }
			});
		});
	}

	ready(function () {
		var nodes = document.querySelectorAll('.cadco-hero[data-cadco-hero]');
		Array.prototype.forEach.call(nodes, init);
	});
})();
