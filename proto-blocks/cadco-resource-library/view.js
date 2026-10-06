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

	/**
	 * Video cards open in a dialog.
	 *
	 * The card stays a real link to the video, so a middle click, a right click
	 * and a visitor with no JavaScript all still reach it; this only intercepts
	 * the plain left click. A modifier key is left alone for the same reason --
	 * someone holding cmd is asking for a new tab, not for a dialog.
	 */
	function bindVideos(section) {
		var modal = section.querySelector('[data-cadco-video-modal]');

		if (!modal || modal.dataset.cadcoVideoBound === '1') {
			return;
		}

		modal.dataset.cadcoVideoBound = '1';

		var frame = modal.querySelector('[data-cadco-video-frame]');
		var title = modal.querySelector('#cadco-video-modal-title');

		function close() {
			/* Emptying the frame stops playback. Pausing would need the player's
			   own API and a message channel to it; removing the iframe does not. */
			frame.innerHTML = '';

			if (modal.open) {
				modal.close();
			}
		}

		section.querySelectorAll('[data-cadco-resource-video]').forEach(function (card) {
			card.addEventListener('click', function (event) {
				if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
					return;
				}

				event.preventDefault();

				var src = card.getAttribute('data-cadco-resource-video');
				var name = card.getAttribute('data-cadco-resource-title') || '';

				title.textContent = name;
				frame.innerHTML =
					'<iframe class="h-full w-full" src="' + src +
					'" title="' + name.replace(/"/g, '&quot;') +
					'" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';

				if (typeof modal.showModal === 'function') {
					modal.showModal();
				} else {
					/* No dialog support: let the link do what it would have done. */
					window.open(card.href, '_blank', 'noopener');
					frame.innerHTML = '';
				}
			});
		});

		modal.querySelector('[data-cadco-video-close]').addEventListener('click', close);

		/* Escape fires dialog's own close event, so the frame is cleared there too
		   rather than only on the button. */
		modal.addEventListener('close', function () { frame.innerHTML = ''; });

		/* A click on the backdrop lands on the dialog itself, never on its
		   children, which is what separates it from a click on the video. */
		modal.addEventListener('click', function (event) {
			if (event.target === modal) {
				close();
			}
		});
	}

	function init() {
		document.querySelectorAll('[data-cadco-resource-filters]').forEach(bind);
		document.querySelectorAll('.cadco-resource-library').forEach(bindVideos);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	/* Taxi swaps the view without a page load, so the new form needs binding. */
	document.addEventListener('proto:page-ready', init);
})();
