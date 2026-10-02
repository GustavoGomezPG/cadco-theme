/**
 * Cadco — scroll-triggered section reveals.
 *
 * One script for every section below the fold, rather than a near-identical
 * timeline copied into each block's view.js. The sections share a motion
 * vocabulary on purpose: a page where each module arrives differently reads as
 * a page of unrelated parts, and the eye starts watching the transitions
 * instead of the content. Three verbs cover everything here.
 *
 *   lines   a heading revealed line by line out of a mask (GSAP SplitText)
 *   rise    one element fading up a short distance -- eyebrows, prose, buttons
 *   items   a container's children rising in sequence -- cards, grids, rows
 *   fade    opacity only, no transform -- for elements that already own their
 *           own transforms and would fight a second writer for them
 *
 * Markup contract (see any cadco-* template.php):
 *
 *   <section data-proto-animate="manual" data-cadco-reveal-group>
 *       <p  data-cadco-reveal="rise">
 *       <h2 data-cadco-reveal="lines">
 *       <ul data-cadco-reveal="items">
 *
 * Order comes from the DOM, so a template can reorder its own parts without
 * touching this file, and there is no per-element timing to keep in sync.
 *
 * NOTHING HERE CAN LEAVE CONTENT HIDDEN. The pre-hidden state lives in CSS
 * keyed to `[data-proto-animate="manual"]`, which is Proto-Blocks' reveal
 * runtime contract: the runtime force-reveals any `manual` element that has
 * not reached `done` shortly after it enters view, prints a <noscript> rule
 * that reveals everything when JS is off, and reveals instantly under reduced
 * motion. This script flips the section to `done` the moment GSAP has written
 * its start states inline -- ownership passes from the stylesheet to GSAP in
 * the same frame, so there is no gap to flash through and the runtime's
 * watchdog never has cause to fire mid-animation.
 *
 * Enqueued as `cadco-reveal` with proto-gsap, proto-scroll-trigger and
 * proto-split-text as dependencies. Absent any of them, every section simply
 * shows.
 */
(function () {
	'use strict';

	/* Where a section starts animating: its top at 78% of the viewport, so the
	   motion begins once the section is meaningfully on screen rather than the
	   instant its first pixel appears. Sections are revealed once and stay
	   revealed -- replaying on every scroll past turns a page into a flipbook
	   and makes it impossible to re-read anything. */
	var START = 'top 78%';

	var LINE_DURATION = 0.8;
	var LINE_STAGGER = 0.08;
	var RISE_DURATION = 0.6;
	var RISE_DISTANCE = 20;
	var ITEM_DURATION = 0.65;
	var ITEM_DISTANCE = 28;
	var ITEM_STAGGER = 0.09;

	/* Where each part starts relative to the one before it: 0.45s before its
	   predecessor ends, so the section arrives as one movement rather than as a
	   queue of separate ones.

	   It has to be a POSITION STRING. GSAP reads a bare number as an absolute
	   time on the timeline, so the obvious-looking `-0.45` does not mean "0.45
	   early" -- it means "at time minus 0.45", which clamps to zero and fires
	   every part simultaneously. `'>'` is the previous tween's end, so
	   `'>-0.45'` is 0.45s before it. */
	var OVERLAP = '>-0.45';

	function reducedMotion() {
		return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
			return;
		}
		document.addEventListener('DOMContentLoaded', fn, { once: true });
	}

	/** Hand the section over to the runtime as revealed. */
	function markDone(section) {
		section.setAttribute('data-proto-animate', 'done');
	}

	/**
	 * Run `fn` once the element's own webfont is usable.
	 *
	 * Splitting before the face swaps measures line boxes in the fallback, and
	 * SplitText's autoSplit then re-splits on the swap -- discarding the very
	 * line elements the tween is animating and leaving a heading that never
	 * arrives. document.fonts.ready alone is not enough, because it resolves
	 * for the loads pending at that moment and a face still being discovered
	 * resolves it early; asking for the exact face first closes that gap.
	 *
	 * Copied in spirit from cadco-hero's view.js, which hit precisely this.
	 */
	function whenFonts(el, fn) {
		var once = false;
		var go = function () {
			if (once) { return; }
			once = true;
			fn();
		};

		if (!document.fonts || !document.fonts.ready) { return go(); }

		var waits = [];

		try {
			var cs = window.getComputedStyle(el);
			var family = cs.fontFamily.split(',')[0].replace(/["']/g, '').trim();

			if (family) {
				waits.push(document.fonts.load(cs.fontWeight + ' ' + cs.fontSize + ' "' + family + '"'));
			}
		} catch (e) { /* fall through to fonts.ready alone */ }

		waits.push(document.fonts.ready);

		Promise.all(waits).then(function () { return document.fonts.ready; }).then(go).catch(go);

		// Never let a stalled font host hold a section back.
		window.setTimeout(go, 3000);
	}

	function parts(section) {
		return Array.prototype.slice.call(section.querySelectorAll('[data-cadco-reveal]'))
			// A nested reveal group owns its own children; without this a section
			// containing another would animate its parts twice, from two timelines.
			.filter(function (el) {
				return el.closest('[data-cadco-reveal-group]') === section;
			});
	}

	function setUp(section) {
		var pieces = parts(section);

		if (!pieces.length) {
			markDone(section);
			return;
		}

		var gsap = window.gsap;
		var ScrollTrigger = window.ScrollTrigger;
		var Split = window.SplitText;

		if (!gsap || !ScrollTrigger || reducedMotion()) {
			markDone(section);
			return;
		}

		gsap.registerPlugin(ScrollTrigger);

		if (Split && gsap.registerPlugin) { gsap.registerPlugin(Split); }

		/* Write every start state inline BEFORE handing the section to the
		   runtime as done. After this line the stylesheet no longer hides
		   anything and GSAP does, which is the same thing to the eye and a far
		   more reliable thing to animate out of. */
		pieces.forEach(function (el) {
			var kind = el.getAttribute('data-cadco-reveal');

			if (kind === 'items') {
				gsap.set(el.children, { autoAlpha: 0, y: ITEM_DISTANCE });
			} else if (kind === 'fade') {
				gsap.set(el, { autoAlpha: 0 });
			} else {
				// A heading bound for a line split is hidden as a whole until
				// its lines exist; the split then takes over the hiding.
				gsap.set(el, { autoAlpha: 0, y: kind === 'lines' ? 0 : RISE_DISTANCE });
			}
		});

		markDone(section);

		var opened = false;   // the section has been scrolled to; parts may move
		var headings = [];

		/**
		 * Reveal (or re-reveal) a heading's CURRENT line elements.
		 *
		 * Keyed on the lines it is handed rather than on ones captured earlier,
		 * because `autoSplit` hands us a new set whenever the element re-splits
		 * -- a webfont landing late, or a resize changing where the text wraps.
		 * A tween still animating the old, now-detached nodes would leave the
		 * heading permanently blank, which is precisely what happened before
		 * this existed.
		 */
		function revealLines(entry) {
			if (entry.tween) { entry.tween.kill(); }

			entry.tween = gsap.fromTo(
				entry.split.lines,
				{ yPercent: 110 },
				{
					yPercent: 0,
					duration: LINE_DURATION,
					stagger: LINE_STAGGER,
					ease: 'power3.out',
					onComplete: function () { entry.settled = true; }
				}
			);

			return entry.tween;
		}

		function build() {
			/* Built PAUSED, with the trigger attached only once every tween is
			   in place.
			   Passing `scrollTrigger` to the timeline constructor instead looks
			   tidier and is wrong here: ScrollTrigger takes its hold on the
			   timeline the moment it is constructed, which -- because this
			   function runs from a font callback and adds its tweens afterwards
			   -- is while the timeline is still empty. The tweens added next
			   started playing immediately, so a section was measurably
			   mid-reveal before it had been scrolled anywhere near, and what
			   arrived on screen was the tail of an animation nobody saw begin. */
			var tl = gsap.timeline({ defaults: { ease: 'power3.out' }, paused: true });

			pieces.forEach(function (el, index) {
				var kind = el.getAttribute('data-cadco-reveal');
				var at = index === 0 ? 0 : OVERLAP;
				var entry = null;

				if (kind === 'lines') {
					for (var i = 0; i < headings.length; i++) {
						if (headings[i].el === el) { entry = headings[i]; break; }
					}
				}

				if (entry && entry.split && entry.split.lines && entry.split.lines.length) {
					// The heading itself is visible; its masked lines are what move.
					gsap.set(el, { autoAlpha: 1 });

					/* Driven through a callback rather than added as a tween, so
					   a re-split can rebuild it against the new nodes without
					   tearing a hole in the master sequence. */
					tl.call(function () { revealLines(entry); }, null, at);

					// The call itself has no duration, so the parts after it
					// would all stack at the same instant. This holds the
					// timeline open for as long as the reveal actually takes.
					tl.to({}, { duration: LINE_DURATION }, at);

					return;
				}

				if (kind === 'lines') {
					// No SplitText: the heading rises as one piece instead.
					gsap.set(el, { y: RISE_DISTANCE });
					tl.to(el, { autoAlpha: 1, y: 0, duration: LINE_DURATION }, at);

					return;
				}

				if (kind === 'fade') {
					tl.to(el, { autoAlpha: 1, duration: RISE_DURATION }, at);

					return;
				}

				if (kind === 'items') {
					tl.to(el.children, {
						autoAlpha: 1,
						y: 0,
						duration: ITEM_DURATION,
						stagger: ITEM_STAGGER
					}, at);

					return;
				}

				tl.to(el, { autoAlpha: 1, y: 0, duration: RISE_DURATION }, at);
			});

			/* `once` retires the trigger after the first crossing and the
			   section stays revealed. Replaying on every pass turns a page into
			   a flipbook and makes it impossible to scroll back and re-read
			   something. A section already past the start when this runs -- a
			   deep link, a restored scroll position, a slow webfont -- gets
			   onEnter immediately, which is the correct outcome: show it. */
			ScrollTrigger.create({
				trigger: section,
				start: START,
				once: true,
				onEnter: function () {
					opened = true;
					tl.play();
				}
			});
		}

		var lineHeadings = pieces.filter(function (el) {
			return el.getAttribute('data-cadco-reveal') === 'lines';
		});

		var pending = lineHeadings.length;

		if (!pending || !Split) {
			build();
			return;
		}

		lineHeadings.forEach(function (el) {
			var entry = { el: el, split: null, tween: null, settled: false };

			headings.push(entry);

			whenFonts(el, function () {
				entry.split = Split.create(el, {
					type: 'lines',
					mask: 'lines',
					autoSplit: true,
					// Keeps the heading one readable string to a screen reader
					// rather than a stack of disconnected line fragments.
					aria: 'auto',
					/* Fires on the first split and again on every re-split. The
					   new lines have none of the state the old ones carried, so
					   whichever stage this heading is at has to be re-applied to
					   them -- otherwise a font landing mid-reveal leaves the
					   heading blank for good. */
					onSplit: function (self) {
						if (!opened)      { return gsap.set(self.lines, { yPercent: 110 }); }
						if (entry.settled) { return gsap.set(self.lines, { yPercent: 0 }); }

						return revealLines(entry);
					}
				});

				pending -= 1;

				if (pending === 0) { build(); }
			});
		});
	}

	/*
	 * Taxi swaps the page container without reloading the document, so
	 * DOMContentLoaded fires once per visit and never again: sections on a
	 * navigated-to page were never set up, and the plugin's watchdog forced
	 * them visible unanimated 1.5s later.
	 *
	 * proto-taxi.js dispatches proto:page-ready for the first load *and* every
	 * NAVIGATE_END precisely so block code has one contract to bind to -- its
	 * own comment says as much, and pb-motion.js and the header block already
	 * use it. Binding here puts this script on the same contract.
	 */
	var BOUND = 'data-cadco-reveal-bound';

	function setUpAll(root) {
		var scope    = (root && root.querySelectorAll) ? root : document;
		var sections = scope.querySelectorAll('[data-cadco-reveal-group]');

		/* The event carries the incoming container. If it ever hands us one the
		   sections are not inside, fall back to the document rather than
		   silently revealing nothing. */
		if (!sections.length && scope !== document) {
			sections = document.querySelectorAll('[data-cadco-reveal-group]');
		}

		Array.prototype.forEach.call(sections, function (section) {
			if (section.hasAttribute(BOUND)) { return; }

			section.setAttribute(BOUND, '');
			setUp(section);
		});
	}

	/**
	 * Drop the plugin's no-JS fallback when it leaks into the live document.
	 *
	 * Proto-Blocks prints that fallback inside <noscript>, where it is inert for
	 * anyone running JS. But Taxi parses the incoming page with scripting
	 * disabled, and in that mode <noscript> contents parse as real elements — so
	 * merging the new head promotes the fallback to a live <style> carrying
	 * `[data-proto-animate]:not([data-proto-animate="done"]) { opacity:1
	 * !important }`.
	 *
	 * That forces every not-yet-revealed section visible, beating both the
	 * theme's start state and GSAP's inline styles, until the section flips to
	 * "done". Which is precisely the reported flash: a navigated-to section
	 * showing at full opacity, then being hidden, then animating in.
	 *
	 * Real no-JS visitors are unaffected: this only ever runs with JS on, and the
	 * <noscript> they get is untouched.
	 */
	function isLeakedFallback(node) {
		if (!node || node.tagName !== 'STYLE') { return false; }

		var css = node.textContent || '';

		return css.indexOf('[data-proto-animate]:not([data-proto-animate="done"])') !== -1
			&& css.indexOf('!important') !== -1;
	}

	function dropLeakedFallback(root) {
		if (isLeakedFallback(root)) { root.remove(); return; }

		if (!root || !root.querySelectorAll) { return; }

		Array.prototype.forEach.call(root.querySelectorAll('style'), function (style) {
			if (isLeakedFallback(style)) { style.remove(); }
		});
	}

	/* Watched across the whole document, not just the head. Taxi promotes the
	   fallback out of its <noscript> and inserts it into the BODY, so a head-only
	   observer never sees it. And it has to be an observer rather than a cleanup
	   on page-ready: the merge happens during NAVIGATE_IN, well before
	   NAVIGATE_END, so page-ready arrives half a second after the leaked rule has
	   already forced the section visible. This removes it in the same microtask
	   it is inserted, before a frame can paint with it. */
	new MutationObserver(function (records) {
		for (var i = 0; i < records.length; i++) {
			var added = records[i].addedNodes;

			for (var j = 0; j < added.length; j++) { dropLeakedFallback(added[j]); }
		}
	}).observe(document.documentElement, { childList: true, subtree: true });

	dropLeakedFallback(document);

	document.addEventListener('proto:page-ready', function (e) {
		setUpAll((e.detail && e.detail.container) || document);
	});

	/* Taxi is optional -- functions.php drops its scripts when the transition is
	   switched off, and then nothing dispatches proto:page-ready. The original
	   DOMContentLoaded path stays as the fallback; the guard above makes both
	   firing harmless. */
	ready(function () {
		setUpAll(document);
	});
})();
