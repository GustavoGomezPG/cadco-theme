/**
 * Submit the refinement form when the select changes.
 *
 * The form works without this: it is a GET form with a noscript button and the
 * server reads the query string, so the archive refines with the script off or
 * still loading. This only removes the second action for someone using a
 * pointer.
 */
(function () {
	'use strict';

	function bind(form) {
		if (form.dataset.cadcoArchiveBound === '1') {
			return;
		}

		form.dataset.cadcoArchiveBound = '1';

		form.querySelectorAll('select').forEach(function (select) {
			select.addEventListener('change', function () {
				/* Whatever page the reader was on belongs to the previous
				   refinement. A shorter result set may not have it, so it is
				   dropped rather than carried across. */
				var page = form.querySelector('input[name="ppage"]');

				if (page) {
					page.remove();
				}

				form.submit();
			});
		});
	}

	/*
	 * The category rail is pinned 32px down while it fits the window. A
	 * category with many sub-categories makes it taller than that, and pinned at
	 * that its foot would sit below the window for the whole listing. Then the
	 * top becomes the window height less the rail's height and a bottom margin
	 * (a negative number), so the rail scrolls with the page until its foot is
	 * in view and only then holds still.
	 */
	var RAIL_TOP = 32;
	var RAIL_BOTTOM = 48;
	var rails = [];

	function placeRail(rail) {
		/* Logged-in editors have WordPress's fixed admin bar over the top of
		   the window; the gap is measured from below it. */
		var bar = document.getElementById('wpadminbar');
		var barHeight = bar && getComputedStyle(bar).position === 'fixed' ? bar.offsetHeight : 0;
		/* And below the site header, which stays on screen while it scrolls. */
		var header = document.querySelector('.wp-site-blocks > header');
		var headerHeight = header && getComputedStyle(header).position === 'sticky' ? header.offsetHeight : 0;
		var minTop = RAIL_TOP + barHeight + headerHeight;
		var fits = rail.offsetHeight + minTop + RAIL_BOTTOM <= window.innerHeight;
		var top = fits ? minTop : window.innerHeight - rail.offsetHeight - RAIL_BOTTOM;

		/* Inline, so it outranks the lg:top-[120px] utility. Harmless below lg,
		   where the rail is not sticky and top does nothing. */
		rail.style.top = Math.round(top) + 'px';
	}

	function placeRails() {
		rails = rails.filter(function (rail) { return rail.isConnected; });
		rails.forEach(placeRail);
	}

	function watchRail(rail) {
		if (rail.dataset.cadcoRailBound === '1') {
			return;
		}

		rail.dataset.cadcoRailBound = '1';
		rails.push(rail);
		placeRail(rail);

		if ('ResizeObserver' in window) {
			new ResizeObserver(function () { placeRail(rail); }).observe(rail);
		}
	}

	window.addEventListener('resize', placeRails);

	function init() {
		document.querySelectorAll('[data-cadco-archive-filters]').forEach(bind);
		document.querySelectorAll('[data-cadco-rail]').forEach(watchRail);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	/* Taxi swaps the view without a page load, so the new form needs binding. */
	document.addEventListener('proto:page-ready', init);
})();
