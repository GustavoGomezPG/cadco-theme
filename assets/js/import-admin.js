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
