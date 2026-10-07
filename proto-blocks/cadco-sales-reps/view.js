/**
 * Filter the representative cards by state.
 *
 * Done in the browser because every card is already on the page: there is no
 * second page to fetch, and a reload to hide two cards would be slower and would
 * lose the visitor's place on a long page. The select is only rendered on the
 * front end, so with no JavaScript the visitor simply sees every rep, which is
 * the honest fallback -- the full list was the point.
 */
(function () {
	'use strict';

	function bind(section) {
		if (section.dataset.cadcoRepsBound === '1') {
			return;
		}

		section.dataset.cadcoRepsBound = '1';

		var select = section.querySelector('[data-cadco-reps-filter]');
		var cards = Array.prototype.slice.call(section.querySelectorAll('[data-cadco-rep]'));
		var empty = section.querySelector('[data-cadco-reps-empty]');

		if (!select || !cards.length) {
			return;
		}

		select.addEventListener('change', function () {
			var want = select.value;
			var shown = 0;

			cards.forEach(function (card) {
				/* Split on the separator rather than matching the string, so
				   "Virginia" cannot match "West Virginia". */
				var states = (card.getAttribute('data-states') || '').split('|');
				var match = want === '' || states.indexOf(want) !== -1;

				card.hidden = !match;

				if (match) {
					shown++;
				}
			});

			if (empty) {
				empty.hidden = shown !== 0;
			}
		});
	}

	/** Open a rep's details in the dialog. */
	function bindModal(section) {
		var modal = section.querySelector('[data-cadco-rep-modal]');

		if (!modal || modal.dataset.cadcoRepBound === '1') {
			return;
		}

		modal.dataset.cadcoRepBound = '1';

		var logo = modal.querySelector('[data-rep-modal-logo]');
		var name = modal.querySelector('[data-rep-modal-name]');
		var terr = modal.querySelector('[data-rep-modal-territory]');
		var addr = modal.querySelector('[data-rep-modal-address]');
		var links = modal.querySelector('[data-rep-modal-links]');
		var site = modal.querySelector('[data-rep-modal-site]');
		var closing = false;

		function reduced() {
			return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		}

		function finish() {
			closing = false;
			modal.classList.remove('is-closing');
			if (modal.open) { modal.close(); }
		}

		function close() {
			if (closing || !modal.open) { return; }
			if (reduced()) { finish(); return; }

			closing = true;
			modal.classList.add('is-closing');

			var done = false;
			var once = function () {
				if (done) { return; }
				done = true;
				modal.removeEventListener('animationend', once);
				finish();
			};

			modal.addEventListener('animationend', once);
			window.setTimeout(once, 400);
		}

		function linkTo(href, text, kind) {
			var a = document.createElement('a');
			a.className = 'cadco-rep-link';
			a.href = href;
			a.textContent = text;
			a.setAttribute('data-kind', kind);
			return a;
		}

		section.querySelectorAll('[data-cadco-rep-open]').forEach(function (button) {
			button.addEventListener('click', function () {
				var card = button.closest('[data-cadco-rep]');

				if (!card) { return; }

				var src = card.getAttribute('data-rep-logo') || '';
				logo.innerHTML = '';

				if (src) {
					var img = document.createElement('img');
					img.src = src;
					img.alt = card.getAttribute('data-rep-logo-alt') || '';
					img.className = 'block h-auto max-h-[76px] w-auto max-w-[180px]';
					logo.appendChild(img);
				}

				name.textContent = card.getAttribute('data-rep-name') || '';
				terr.textContent = card.getAttribute('data-rep-territory') || '';

				var address = card.getAttribute('data-rep-address') || '';
				addr.textContent = address;
				addr.hidden = address === '';

				links.innerHTML = '';

				var phone = card.getAttribute('data-rep-phone') || '';
				var email = card.getAttribute('data-rep-email') || '';

				/* Stripped to digits for the href so a number written 610.363.5688
				   still dials, while the visible text keeps the rep's own format. */
				if (phone) {
					links.appendChild(linkTo('tel:' + phone.replace(/[^0-9+]/g, ''), phone, 'phone'));
				}

				if (email) {
					links.appendChild(linkTo('mailto:' + email, email, 'email'));
				}

				var website = card.getAttribute('data-rep-website') || '';

				if (website) {
					site.href = website;
					/* Shown without the scheme and any trailing slash: the reader is
					   being told which site, not handed a URL to read aloud. */
					site.textContent = website.replace(/^https?:\/\//, '').replace(/\/$/, '');
					site.parentElement.hidden = false;
				} else {
					site.parentElement.hidden = true;
				}

				if (typeof modal.showModal === 'function') {
					modal.showModal();
				}
			});
		});

		modal.querySelector('[data-cadco-rep-close]').addEventListener('click', close);

		modal.addEventListener('cancel', function (event) {
			event.preventDefault();
			close();
		});

		modal.addEventListener('close', function () {
			closing = false;
			modal.classList.remove('is-closing');
		});

		/* A click on the backdrop lands on the dialog itself, never on its children. */
		modal.addEventListener('click', function (event) {
			if (event.target === modal) { close(); }
		});
	}

	function init() {
		document.querySelectorAll('[data-cadco-reps]').forEach(function (section) {
			bind(section);
			bindModal(section);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	/* Taxi swaps the view without a page load, so the new section needs binding. */
	document.addEventListener('proto:page-ready', init);
})();
