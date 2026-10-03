/**
 * Cadco Timeline.
 *
 * The section pins and the rail travels sideways while it is pinned, which is
 * what the Helio reference does: the page stops, the timeline scrolls
 * horizontally through its whole length, and the page resumes.
 *
 * Everything is derived from the content, never hard-coded. The distance the
 * track must travel is measured from the DOM, and ScrollTrigger's `end` is that
 * same distance, so the pinned region grows as milestones are added and the
 * rail always finishes exactly as the pin releases. `invalidateOnRefresh` makes
 * ScrollTrigger re-measure on resize and on refresh, so a font load, a
 * breakpoint change or an edit in the block editor re-derives the length rather
 * than keeping a stale one.
 *
 * The arrows do not animate the track themselves. Under a pin the track's
 * position IS the scroll position, so a second animation would fight the
 * scrub; instead they scroll the window to the point in the pinned range where
 * the wanted slide is showing, and the scrub moves the track as it always does.
 *
 * Lifecycle (docs/reveal-animations.md in proto-blocks-theme):
 * Proto-Blocks stamps block view scripts with data-taxi-reload, so Taxi re-runs
 * this file on every navigation. The readyState guard is therefore correct, and
 * everything attached to window, document or ScrollTrigger is torn down when
 * the view that owns it leaves -- a pin that outlives its page would leave the
 * next one with a stray spacer.
 */
(function () {
	'use strict';

	function prefersReducedMotion() {
		return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	function setUp(section) {
		var track = section.querySelector('[data-timeline-track]');
		var items = section.querySelectorAll('[data-timeline-item]');
		var prev  = section.querySelector('[data-timeline-prev]');
		var next  = section.querySelector('[data-timeline-next]');

		if (!track || !items.length) { return; }

		var gsap          = window.gsap;
		var ScrollTrigger = window.ScrollTrigger;

		/**
		 * How far the track must move for its last milestone to reach the right
		 * edge. Measured, not assumed, so it holds for any number of
		 * milestones; `scrollWidth` includes the track's own trailing padding.
		 */
		function distance() {
			return Math.max(0, track.scrollWidth - section.clientWidth);
		}

		/* No GSAP, or the viewer asked for less motion: leave the rail as an
		   ordinary horizontal scroll. No pin, no hijacked page scroll. */
		if (!gsap || !ScrollTrigger || prefersReducedMotion() || section.dataset.scrub !== '1') {
			track.parentElement.classList.add('overflow-x-auto');
			if (prev) { prev.disabled = true; }
			if (next) { next.disabled = true; }
			return;
		}

		var index = 0;

		var tween = gsap.to(track, {
			x: function () { return -distance(); },
			ease: 'none',
			scrollTrigger: {
				trigger: section,
				/* A section taller than the viewport cannot sit flush with the
				   top without its foot being cut off, so it pins against the
				   bottom instead. Resolved as a function, and re-resolved by
				   invalidateOnRefresh, so a window resize across that boundary
				   picks the right one. */
				start: function () {
					return section.offsetHeight > window.innerHeight ? 'bottom bottom' : 'top top';
				},
				/* The pinned length IS the travel distance, so one pixel of
				   scrolling moves the rail one pixel and adding milestones
				   lengthens the pinned region by exactly their width. */
				end: function () { return '+=' + distance(); },
				pin: true,
				pinSpacing: true,
				anticipatePin: 1,
				scrub: 1,
				invalidateOnRefresh: true,
				onRefresh: syncButtons,
				onUpdate: function (self) {
					var span = Math.max(1, items.length - 1);
					index = Math.round(self.progress * span);
					syncButtons();
				}
			}
		});

		var st = tween.scrollTrigger;

		function syncButtons() {
			if (prev) { prev.disabled = index <= 0; }
			if (next) { next.disabled = index >= items.length - 1; }
		}

		/**
		 * Scroll to the page position at which `i` is the current slide. The
		 * pinned range is linear, so the slide's progress maps straight onto it.
		 */
		function go(delta) {
			if (!st) { return; }

			var span = Math.max(1, items.length - 1);

			index = Math.max(0, Math.min(items.length - 1, index + delta));

			var target = st.start + (st.end - st.start) * (index / span);

			/* Native smooth scrolling rather than GSAP's ScrollToPlugin, which
			   the theme does not bundle. The scrub follows the scroll either
			   way, so the rail moves with it. */
			window.scrollTo({ top: target, behavior: 'smooth' });

			syncButtons();
		}

		if (prev) { prev.addEventListener('click', function () { go(-1); }); }
		if (next) { next.addEventListener('click', function () { go(1); }); }

		syncButtons();

		/* Dispose with the view that owns it: a live pin would leave its spacer
		   behind on the next page. */
		document.addEventListener('proto:page-leave', function off(e) {
			var container = e && e.detail && e.detail.container;

			if (container && !container.contains(section)) { return; }

			document.removeEventListener('proto:page-leave', off);

			if (st) { st.kill(true); }
			tween.kill();
			gsap.set(track, { clearProps: 'transform' });
		});
	}

	function init() {
		var sections = document.querySelectorAll('[data-cadco-timeline]');

		Array.prototype.forEach.call(sections, function (section) {
			/* Taxi re-runs this file, so a section already wired on this page
			   must not be wired again. */
			if (section.hasAttribute('data-timeline-bound')) { return; }

			section.setAttribute('data-timeline-bound', '');
			setUp(section);
		});
	}

	if (document.readyState !== 'loading') {
		init();
	} else {
		document.addEventListener('DOMContentLoaded', init, { once: true });
	}
})();
