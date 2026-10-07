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

	function init() {
		document.querySelectorAll('[data-cadco-archive-filters]').forEach(bind);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	/* Taxi swaps the view without a page load, so the new form needs binding. */
	document.addEventListener('proto:page-ready', init);
})();
