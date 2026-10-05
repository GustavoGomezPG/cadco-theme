/**
 * Cadco Comparison Table.
 *
 * Shows one table at a time and switches between them. Every table is in the
 * markup and the inactive ones are hidden, rather than being fetched or built
 * on the client: the content is already on the page, it stays available when
 * the script does not run, and it is all indexable.
 *
 * Lifecycle (docs/reveal-animations.md in proto-blocks-theme):
 * Proto-Blocks stamps block view scripts with data-taxi-reload, so Taxi re-runs
 * this file on every navigation. The readyState guard below is therefore
 * correct, and the listeners are on the section itself, which leaves with the
 * view that owns it.
 */
(function () {
	'use strict';

	function setUp(section) {
		var tabs = section.querySelectorAll('[data-cadco-cmp-tab]');
		var panels = section.querySelectorAll('[data-cadco-cmp-panel]');

		if (!tabs.length) { return; }

		function select(index) {
			Array.prototype.forEach.call(tabs, function (tab) {
				var on = Number(tab.getAttribute('data-cadco-cmp-tab')) === index;

				tab.setAttribute('aria-selected', on ? 'true' : 'false');
				tab.classList.toggle('is-active', on);
				/* Only the selected tab is in the tab order; the arrow keys move
				   between them, which is what a tablist is expected to do. */
				tab.tabIndex = on ? 0 : -1;
			});

			Array.prototype.forEach.call(panels, function (panel) {
				panel.hidden = Number(panel.getAttribute('data-cadco-cmp-panel')) !== index;
			});
		}

		Array.prototype.forEach.call(tabs, function (tab) {
			tab.addEventListener('click', function () {
				select(Number(tab.getAttribute('data-cadco-cmp-tab')));
			});

			tab.addEventListener('keydown', function (e) {
				var step = e.key === 'ArrowRight' ? 1 : (e.key === 'ArrowLeft' ? -1 : 0);

				if (!step) { return; }

				e.preventDefault();

				var next = (Number(tab.getAttribute('data-cadco-cmp-tab')) + step + tabs.length) % tabs.length;

				select(next);
				tabs[next].focus();
			});
		});

		select(0);
	}

	function init() {
		document.querySelectorAll('[data-cadco-comparison]').forEach(function (section) {
			/* Taxi re-runs this file, so a section already wired must not be
			   wired a second time. */
			if (section.hasAttribute('data-cmp-bound')) { return; }

			section.setAttribute('data-cmp-bound', '');
			setUp(section);
		});
	}

	if (document.readyState !== 'loading') {
		init();
	} else {
		document.addEventListener('DOMContentLoaded', init, { once: true });
	}
})();
