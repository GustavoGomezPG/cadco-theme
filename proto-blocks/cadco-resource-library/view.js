/**
 * Submit the filter form when a select changes.
 *
 * The form works without this: it has a submit button and the server reads the
 * query string, so the library filters with the script switched off or still
 * loading. This only removes the second action for someone using a pointer.
 *
 * The search field is deliberately left alone. Submitting on every keystroke
 * would reload the page mid-word, and submitting on blur would fire when
 * someone tabs to the button they were about to press anyway.
 */
(function () {
	'use strict';

	function bind(form) {
		if (form.dataset.cadcoFiltersBound === '1') {
			return;
		}

		form.dataset.cadcoFiltersBound = '1';

		form.querySelectorAll('select').forEach(function (select) {
			select.addEventListener('change', function () {
				/* Any page the reader was on belongs to the previous filter, so
				   it is dropped rather than carried into a shorter result set
				   where it may not exist. */
				var page = form.querySelector('input[name="rpage"]');

				if (page) {
					page.remove();
				}

				form.submit();
			});
		});
	}

	function init() {
		document.querySelectorAll('[data-cadco-resource-filters]').forEach(bind);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	/* Taxi swaps the view without a page load, so the new form needs binding. */
	document.addEventListener('proto:page-ready', init);
})();
