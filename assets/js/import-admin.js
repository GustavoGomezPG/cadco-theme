/**
 * Drives the batched apply on Products -> Import.
 *
 * Batching exists because the screen is the only interface: 236 products of
 * taxonomy and meta writes in one request would exceed PHP's execution limit,
 * so the plan is applied in slices and the server reports progress.
 */
(function () {
	'use strict';

	/**
	 * Replace {token} placeholders in a translated string.
	 *
	 * Named tokens rather than positional %d/%s: several of these strings
	 * carry more than one number (applied/total/failed), and a translator
	 * reordering them must not silently swap which count lands where.
	 */
	function format(template, tokens) {
		return Object.keys(tokens).reduce(function (str, key) {
			return str.split('{' + key + '}').join(String(tokens[key]));
		}, template);
	}

	/**
	 * Pull the "FAILED ..." lines out of one batch's log.
	 *
	 * run_job() (CADCO_Import_Applier) catches a row's WC_Data_Exception and
	 * logs it as "FAILED <model>: <reason>" rather than aborting the batch —
	 * on purpose, so one bad row cannot take the rest of the run down with
	 * it. This is the other half of that contract: something has to notice
	 * those lines, or the operator only ever sees a done/total count that
	 * cannot tell a successful row from a silently-dropped one.
	 */
	function extractFailures(log) {
		return (log || []).filter(function (line) {
			return (/^FAILED /).test(line);
		});
	}

	// Exposed for a plain `node` test of the string/counting logic above,
	// which has no DOM to run against. No-ops in the browser, where `module`
	// is undefined. Everything past this point touches the DOM, so it is
	// skipped entirely outside a browser rather than merely left unreachable.
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = { format: format, extractFailures: extractFailures };
	}

	if (typeof document === 'undefined') {
		return;
	}

	/**
	 * The change navigator (design spec §6.1): every section already sits on
	 * the page (CADCO_Import_View::review()), so a click here never fetches
	 * anything — it just moves the viewport and focus to the target section
	 * and updates which link is announced as current. The links are plain
	 * `<a href="#cadco-section-...">` anchors, so this is progressive
	 * enhancement: with JS disabled, or before this listener attaches, the
	 * browser's own anchor-jump still works.
	 *
	 * `target.focus()` is what lets a screen-reader user tell what changed
	 * when they click (task brief's accessibility carry-over) — every
	 * section has `tabindex="-1"` (CADCO_Import_View::section_open()) so it
	 * can receive focus without becoming a permanent tab stop.
	 */
	var navLinks = document.querySelectorAll('.cadco-import-navigator .cadco-import-nav-link[aria-controls]');

	/** Clears aria-current from every live link and sets it on exactly one. */
	function setActiveNavLink(link) {
		Array.prototype.forEach.call(navLinks, function (other) {
			other.removeAttribute('aria-current');
		});
		link.setAttribute('aria-current', 'true');
	}

	Array.prototype.forEach.call(navLinks, function (link) {
		link.addEventListener('click', function (event) {
			var target = document.getElementById(link.getAttribute('aria-controls'));

			if (!target) {
				return;
			}

			event.preventDefault();

			setActiveNavLink(link);

			target.scrollIntoView({ behavior: 'smooth', block: 'start' });
			target.focus({ preventScroll: true });
		});
	});

	/**
	 * Scroll-spy: with no hide/show tabs, "active" has to track wherever the
	 * operator has actually scrolled to, or aria-current goes stale the
	 * moment they scroll manually instead of clicking a nav link — the
	 * navigator would keep claiming "Workbook" is current while they read
	 * Renames. IntersectionObserver is the plain way to do this: watch a
	 * thin band near the top of the viewport (just below the fixed
	 * #wpadminbar) and mark whichever section is crossing it as current.
	 *
	 * Deliberately simple, not exhaustive (the brief's own instruction):
	 * this does not try to be exactly right at every scroll-position edge
	 * case, only to track "roughly which section is in view" well enough
	 * that aria-current stays honest during ordinary reading.
	 */
	if (typeof IntersectionObserver !== 'undefined') {
		var linkBySectionId = {};

		Array.prototype.forEach.call(navLinks, function (link) {
			linkBySectionId[link.getAttribute('aria-controls')] = link;
		});

		var observer = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
					if (!entry.isIntersecting) {
						return;
					}

					var link = linkBySectionId[entry.target.id];

					if (link) {
						setActiveNavLink(link);
					}
				});
			},
			// A thin activation band starting just under the admin bar: a
			// section counts as "current" while its top has scrolled past
			// that band but the rest of it hasn't yet scrolled past too.
			{ rootMargin: '-40px 0px -85% 0px', threshold: 0 }
		);

		Object.keys(linkBySectionId).forEach(function (sectionId) {
			var section = document.getElementById(sectionId);

			if (section) {
				observer.observe(section);
			}
		});
	}

	var button = document.getElementById('cadco-import-apply');

	if (!button || typeof window.cadcoImport === 'undefined') {
		return;
	}

	var config         = window.cadcoImport;
	var box            = document.getElementById('cadco-import-progress');
	var bar            = box.querySelector('progress');
	var status         = box.querySelector('.cadco-import-status');
	var failuresBox    = document.getElementById('cadco-import-failures');
	var failuresHeading = failuresBox.querySelector('.cadco-import-failures-heading');
	var failuresList   = failuresBox.querySelector('.cadco-import-failures-list');

	/** Accumulated across every batch of this run — never reset mid-run. */
	var failures = [];

	/**
	 * UPCs of the renames the operator ticked. Read fresh on every batch so
	 * the set cannot drift from what is on screen.
	 */
	function approved() {
		return Array.prototype.map.call(
			document.querySelectorAll('.cadco-rename:checked'),
			function (input) { return input.value; }
		);
	}

	/**
	 * Fold this batch's failures into the running total and, if there are
	 * any, show them. Rendered incrementally rather than only at the end, so
	 * an operator watching a long run sees a problem as soon as it happens
	 * rather than only in the final summary.
	 */
	function recordFailures(log) {
		var newFailures = extractFailures(log);

		if (newFailures.length === 0) {
			return;
		}

		failures = failures.concat(newFailures);

		failuresBox.hidden = false;
		failuresHeading.textContent = format(config.i18n.failuresHeading, { count: failures.length });

		newFailures.forEach(function (line) {
			var li = document.createElement('li');
			li.textContent = line;
			failuresList.appendChild(li);
		});
	}

	function step(offset) {
		var body = new FormData();

		body.append('action', 'cadco_import_batch');
		body.append('_wpnonce', config.nonce);
		body.append('offset', offset);
		body.append('size', config.batchSize);
		approved().forEach(function (upc) { body.append('approved[]', upc); });

		window.fetch(config.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin'
		})
			.then(function (response) { return response.json(); })
			.then(function (result) {
				if (!result.success) {
					status.textContent = (result.data && result.data.message) || config.i18n.failed;
					button.disabled = false;
					return;
				}

				var data = result.data;

				recordFailures(data.log);

				bar.value = data.total ? Math.round((data.done / data.total) * 100) : 100;
				status.textContent = data.done + ' / ' + data.total;

				if (data.complete) {
					// data.total is the queue length, not a count of what
					// actually succeeded — a row that threw is still one of
					// the "done" jobs apply_jobs() counted. The applied
					// count has to be computed here, from what recordFailures
					// has been accumulating, or a run with failures reports
					// the full total as if every row had succeeded.
					var applied = data.total - failures.length;

					status.textContent = failures.length
						? format(config.i18n.doneWithFailures, { applied: applied, total: data.total, failed: failures.length })
						: format(config.i18n.done, { applied: applied });

					return;
				}

				step(data.done);
			})
			.catch(function () {
				status.textContent = config.i18n.network;
				button.disabled = false;
			});
	}

	button.addEventListener('click', function () {
		button.disabled = true;
		box.hidden = false;
		bar.value = 0;
		failures = [];
		failuresBox.hidden = true;
		failuresList.innerHTML = '';
		step(0);
	});
}());
