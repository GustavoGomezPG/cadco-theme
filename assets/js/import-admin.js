/**
 * Drives the batched apply on Products -> Import.
 *
 * Batching exists because the screen is the only interface: 236 products of
 * taxonomy and meta writes in one request would exceed PHP's execution limit,
 * so the plan is applied in slices and the server reports progress.
 */
(function () {
	'use strict';

	var button = document.getElementById('cadco-import-apply');

	if (!button || typeof window.cadcoImport === 'undefined') {
		return;
	}

	var config = window.cadcoImport;
	var box    = document.getElementById('cadco-import-progress');
	var bar    = box.querySelector('progress');
	var status = box.querySelector('.cadco-import-status');

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

				bar.value = data.total ? Math.round((data.done / data.total) * 100) : 100;
				status.textContent = data.done + ' / ' + data.total;

				if (data.complete) {
					status.textContent = config.i18n.done.replace('%d', data.total);
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
		step(0);
	});
}());
