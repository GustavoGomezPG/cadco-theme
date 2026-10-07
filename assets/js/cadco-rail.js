/**
 * Horizontal rails: the video strip and the related-products row.
 *
 * The track is a real scroll container with scroll snapping, so it already
 * works before this runs and without it: a trackpad, a touch swipe, Shift+wheel
 * and the keyboard all scroll it natively, and the items are reachable by Tab
 * whether or not they are on screen. This only adds the two arrow buttons the
 * design draws, and keeps them in step with where the track actually is.
 *
 * Markup contract:
 *
 *     <div data-cadco-rail>
 *       <div data-cadco-rail-track> …items… </div>
 *       <button data-cadco-rail-prev> <button data-cadco-rail-next>
 *     </div>
 *
 * The buttons are rendered disabled, so a rail that fits entirely on screen
 * never offers a control that would do nothing, and one whose script never
 * loads does not offer a dead one either.
 */
(function () {
	'use strict';

	/* Enough of a gap at each end that a sub-pixel scroll position, which is
	   what a zoomed-out browser leaves behind, does not read as "more to come". */
	var EDGE = 2;

	function bind(rail) {
		if (rail.dataset.cadcoRailBound === '1') {
			return;
		}

		rail.dataset.cadcoRailBound = '1';

		var track = rail.querySelector('[data-cadco-rail-track]');
		var prev = rail.querySelector('[data-cadco-rail-prev]');
		var next = rail.querySelector('[data-cadco-rail-next]');

		if (!track) {
			return;
		}

		function reduced() {
			return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		}

		/* One item plus the gap between two of them. Measured rather than stored:
		   the item width is a grid decision that changes at every breakpoint, and
		   reading it back is always right where a remembered number is not. */
		function step() {
			var items = track.children;

			if (items.length < 1) {
				return track.clientWidth;
			}

			var first = items[0].getBoundingClientRect();

			if (items.length > 1) {
				var second = items[1].getBoundingClientRect();
				var pitch = second.left - first.left;

				if (pitch > 1) {
					return pitch;
				}
			}

			return first.width;
		}

		function sync() {
			var max = track.scrollWidth - track.clientWidth;
			var at = track.scrollLeft;

			if (prev) {
				prev.disabled = at <= EDGE;
			}

			if (next) {
				next.disabled = at >= max - EDGE;
			}

			/* A rail that fits needs no controls at all. Hidden rather than left
			   disabled: two permanently dead buttons under a short row read as
			   something broken. */
			rail.classList.toggle('cadco-rail--static', max <= EDGE);
		}

		function scrollBy(direction) {
			track.scrollBy({
				left: direction * step(),
				behavior: reduced() ? 'auto' : 'smooth'
			});
		}

		if (prev) {
			prev.addEventListener('click', function () {
				scrollBy(-1);
			});
		}

		if (next) {
			next.addEventListener('click', function () {
				scrollBy(1);
			});
		}

		track.addEventListener('scroll', sync, { passive: true });

		/* The track's width decides whether the controls are needed at all, and
		   it changes with the viewport, with a font swap and with the sidebar
		   on a page that has one. */
		if (typeof ResizeObserver === 'function') {
			new ResizeObserver(sync).observe(track);
		} else {
			window.addEventListener('resize', sync);
		}

		sync();
	}

	function init() {
		document.querySelectorAll('[data-cadco-rail]').forEach(bind);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	/* Taxi swaps the view without a page load, so the new rails need binding.
	   Images inside a rail also settle after it, and a rail whose items have
	   not loaded yet measures short. */
	document.addEventListener('proto:page-ready', init);
	window.addEventListener('load', init);
})();
