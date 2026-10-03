/**
 * Cadco Timeline.
 *
 * Moves the rail sideways. Two inputs drive one position:
 *
 *   scroll  GSAP ScrollTrigger scrubs the track as the section crosses the
 *           viewport, the technique the Helio reference uses.
 *   arrows  step to the previous or next milestone.
 *
 * They share a single `offset` rather than animating the element separately,
 * so an arrow press mid-scroll cannot leave the two fighting over transform.
 *
 * Lifecycle (docs/reveal-animations.md in proto-blocks-theme):
 * Proto-Blocks stamps block view scripts with data-taxi-reload, so Taxi re-runs
 * this file on every navigation. The readyState guard below is therefore
 * correct, and anything attached to window or document is removed when the view
 * that owns it leaves — otherwise each navigation would leave another resize
 * listener and another ScrollTrigger behind.
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
		var index         = 0;
		var offset        = 0;
		var scrubbed      = 0;
		var trigger       = null;

		/** How far the track can travel before its tail reaches the viewport. */
		function maxOffset() {
			return Math.max(0, track.scrollWidth - section.clientWidth);
		}

		/** The arrows step by one milestone; the step is the item's own width. */
		function step() {
			return items[0] ? items[0].getBoundingClientRect().width : 706;
		}

		function apply() {
			var total = Math.min(maxOffset(), offset + scrubbed);

			if (gsap) {
				gsap.to(track, { x: -total, duration: 0.6, ease: 'power3.out', overwrite: true });
			} else {
				track.style.transform = 'translateX(' + (-total) + 'px)';
			}

			if (prev) { prev.disabled = total <= 0; }
			if (next) { next.disabled = total >= maxOffset() - 1; }
		}

		function go(delta) {
			index  = Math.max(0, Math.min(items.length - 1, index + delta));
			offset = Math.min(maxOffset(), index * step());
			apply();
		}

		if (prev) { prev.addEventListener('click', function () { go(-1); }); }
		if (next) { next.addEventListener('click', function () { go(1); }); }

		/*
		 * The scrub is additive: it contributes its own share of the travel on
		 * top of wherever the arrows have taken the rail, so neither input
		 * resets the other.
		 */
		if (gsap && ScrollTrigger && section.dataset.scrub === '1' && !prefersReducedMotion()) {
			trigger = ScrollTrigger.create({
				trigger: section,
				start: 'top bottom',
				end: 'bottom top',
				scrub: true,
				onUpdate: function (self) {
					scrubbed = self.progress * maxOffset() * 0.6;
					apply();
				}
			});
		}

		var onResize = function () { offset = Math.min(maxOffset(), index * step()); apply(); };

		window.addEventListener('resize', onResize);

		/* Dispose with the view that owns it. */
		document.addEventListener('proto:page-leave', function off(e) {
			var container = e && e.detail && e.detail.container;

			if (container && !container.contains(section)) { return; }

			window.removeEventListener('resize', onResize);
			document.removeEventListener('proto:page-leave', off);

			if (trigger) { trigger.kill(false); trigger = null; }
			if (gsap) { gsap.killTweensOf(track); }
		});

		apply();
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
