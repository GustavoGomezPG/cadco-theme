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

	/**
	 * Inline label editing (History tab, task brief step 2). This has to sit
	 * ahead of the #cadco-import-apply early return just below: that button
	 * exists only on the Import tab's Review state, but a label input lives
	 * on the History tab, where #cadco-import-apply is not on the page at
	 * all — reaching that return first would skip this block on the one tab
	 * it is meant to run on.
	 *
	 * Saves on blur and on Enter, and only when the value actually changed
	 * (typing into a field and tabbing back out unchanged should not fire a
	 * request). CADCO_Import_Admin::ajax_label() nonce- and capability-checks
	 * the request, then validates the run id with is_valid_run_id() before
	 * it ever reaches the filesystem — this is just the wiring; the security
	 * boundary lives server-side.
	 */
	var labelInputs = document.querySelectorAll('.cadco-import-history-label');

	if (labelInputs.length && typeof window.cadcoImport !== 'undefined') {
		var labelConfig = window.cadcoImport;

		Array.prototype.forEach.call(labelInputs, function (input) {
			var status = input.parentNode
				? input.parentNode.querySelector('.cadco-import-history-label-status')
				: null;
			var saved = input.value;

			function save() {
				if (input.value === saved) {
					return;
				}

				var value = input.value;
				var body = new FormData();

				body.append('action', 'cadco_import_label');
				body.append('_wpnonce', labelConfig.nonce);
				body.append('run_id', input.getAttribute('data-run-id'));
				body.append('label', value);

				window.fetch(labelConfig.ajaxUrl, {
					method: 'POST',
					body: body,
					credentials: 'same-origin'
				})
					.then(function (response) { return response.json(); })
					.then(function (result) {
						if (!result.success) {
							if (status) { status.textContent = labelConfig.i18n.labelFailed; }
							return;
						}

						// The server sanitizes the label on the way in
						// (CADCO_Import_Admin::ajax_label() —
						// sanitize_text_field(), length-capped) and echoes
						// back what it actually stored. Fix round 1,
						// finding 5: this used to keep showing the raw
						// typed value regardless, so a label containing
						// markup that got stripped server-side (e.g. a
						// "<script>" a prior report round confirmed gets
						// removed) still displayed as if it had been saved
						// verbatim, until the next full page load.
						var stored = (result.data && typeof result.data.label === 'string')
							? result.data.label
							: value;

						input.value = stored;
						saved = stored;

						if (status) { status.textContent = labelConfig.i18n.labelSaved; }
					})
					.catch(function () {
						if (status) { status.textContent = labelConfig.i18n.labelFailed; }
					});
			}

			input.addEventListener('blur', save);
			input.addEventListener('keydown', function (event) {
				if (event.key === 'Enter') {
					event.preventDefault();
					input.blur();
				}
			});
		});
	}

	/**
	 * Stage 1's drop target.
	 *
	 * The design frames the upload as a drop zone, so it has to actually
	 * accept a drop. This never replaces the <input type="file"> — it only
	 * assigns to its `files` property, so the form still submits exactly as it
	 * does for a keyboard user who used the file picker, and the E2E suite's
	 * setInputFiles() keeps working untouched.
	 *
	 * Must stay above the `#cadco-import-apply` guard below: that element does
	 * not exist on the Upload screen, and its early return would otherwise
	 * skip all of this.
	 */
	var dropzone = document.getElementById('cadco-import-dropzone');
	var fileInput = document.getElementById('cadco-import-file');

	if (dropzone && fileInput) {
		var filenameOut = document.getElementById('cadco-import-filename');
		var fallback = document.getElementById('cadco-import-submit-fallback');
		var uploadGrid = document.querySelector('.cadco-import-upload-grid');
		var checkingPanel = document.getElementById('cadco-import-checking');
		var checkingConfig = window.cadcoImport;
		var form = fileInput.form;
		var submitted = false;

		// Choosing the workbook IS the step, so the form submits itself and the
		// separate "Check workbook" button is redundant. It stays in the markup
		// for the no-JS case and is hidden here — this line running is itself
		// the proof that the staged check below will work.
		if (fallback) {
			fallback.hidden = true;
		}

		var showFilename = function () {
			if (!filenameOut) {
				return;
			}

			// textContent, never innerHTML: a filename is attacker-supplied.
			filenameOut.textContent = fileInput.files && fileInput.files.length
				? fileInput.files[0].name + ' · '
				: '';
		};

		/**
		 * bytes -> "2.1 MB". Client-side only, straight from the File API's own
		 * `.size` — a real number the browser itself measured, not anything the
		 * server has to be asked for. Good enough for the checking panel's
		 * transient filename/size line; the Review screen's own Workbook
		 * section still reports size_format() of the archived copy on disk.
		 */
		var formatBytes = function (bytes) {
			if (typeof bytes !== 'number' || isNaN(bytes)) {
				return '';
			}

			var units = ['B', 'KB', 'MB', 'GB'];
			var value = bytes;
			var i = 0;

			while (value >= 1024 && i < units.length - 1) {
				value = value / 1024;
				i += 1;
			}

			return (i === 0 ? String(value) : value.toFixed(1)) + ' ' + units[i];
		};

		/**
		 * The submit button's real name/value still travels with a plain
		 * requestSubmit(), for the network-unavailable / no-JS-config edge
		 * case below. requestSubmit(), not submit(): submit() bypasses
		 * validation and does not carry the clicked submit button's
		 * name/value, and the server keys the upload branch on
		 * `cadco_import_upload` being present.
		 */
		var submitFormDirectly = function () {
			if (!form) {
				return;
			}

			var trigger = form.querySelector('#cadco_import_upload');

			if (form.requestSubmit && trigger) {
				form.requestSubmit(trigger);
			} else if (trigger) {
				trigger.click();
			} else {
				form.submit();
			}
		};

		// ---- The "Checking the workbook" stage (task 11) ----
		//
		// Three requests, one slice of CADCO_Import_Admin::run_pipeline() each,
		// the same shape as the batched apply loop's step() below: the browser
		// drives, the server reports a real number per finished phase, and
		// nothing here is drawn until that number has actually arrived.

		var checklistRows = checkingPanel
			? checkingPanel.querySelectorAll('.cadco-import-checklist-row')
			: [];
		var percentOut = document.getElementById('cadco-import-checking-percent');
		var progressOut = document.getElementById('cadco-import-checking-progress');
		var fileInfoOut = document.getElementById('cadco-import-checking-file');
		var totalStages = checklistRows.length;
		var stagesDone = 0;

		// The stage-bar subtitles as the server rendered them, captured before
		// anything overwrites them so a failed check can put them back. Without
		// this the operator is returned to the upload form under a bar still
		// reading "validating rows…" for a check that is no longer running.
		var serverSubtitles = {};

		var stageRow = function (name) {
			return checkingPanel
				? checkingPanel.querySelector('.cadco-import-checklist-row[data-stage="' + name + '"]')
				: null;
		};

		/**
		 * Return the checking panel to the state a fresh workbook deserves.
		 *
		 * Called when a NEW check begins, not when a failed one is dismissed:
		 * the failed state must stay readable until the operator has chosen
		 * another file, or the reason for the failure vanishes with it.
		 *
		 * Without this the panel is re-shown exactly as the failed attempt left
		 * it. A retry after a stage-2 failure would open with "Reading sheets —
		 * 4 of 4" and "Validating rows — 236 checked" already green, carrying
		 * the PREVIOUS workbook's numbers before a byte of the new one had been
		 * posted — which is precisely what this panel exists not to do — and
		 * `stagesDone` would keep accumulating across attempts, printing 133%
		 * and 167% while the <progress> element silently clamped at 100.
		 */
		var resetCheckingPanel = function () {
			stagesDone = 0;

			Array.prototype.forEach.call(checklistRows, function (row) {
				row.classList.remove('is-done', 'is-active', 'is-error');

				var figure = row.querySelector('.cadco-import-checklist-figure');

				if (figure) {
					figure.textContent = '';
				}
			});

			updateCheckingProgress();

			var existingNotice = document.querySelector('.cadco-import-checking-notice');

			if (existingNotice && existingNotice.parentNode) {
				existingNotice.parentNode.removeChild(existingNotice);
			}
		};

		var updateCheckingProgress = function () {
			var percent = totalStages ? Math.round((stagesDone / totalStages) * 100) : 0;

			if (percentOut) {
				percentOut.textContent = percent + '%';
			}

			if (progressOut) {
				progressOut.value = percent;
			}
		};

		/**
		 * Keep the wizard's own stage bar honest while the check runs.
		 *
		 * That bar was rendered by the server before this workbook existed, so
		 * it still reads "no workbook yet" / "waiting for a workbook" on screen
		 * while one is visibly being read. These two subtitles are the only
		 * parts of it that can go stale during the check.
		 *
		 * Both values are facts, not forecasts: the filename comes from the
		 * File the operator just chose, and the phase label is read back out of
		 * the checklist row that is actually running — so it cannot drift from
		 * what the list says. textContent, never innerHTML: a filename is
		 * attacker-supplied.
		 */
		var setWizardSubtitle = function (stageNumber, text) {
			var stage = document.querySelector('.cadco-import-stage[data-stage="' + stageNumber + '"]');
			var subtitle = stage ? stage.querySelector('.cadco-import-stage-subtitle') : null;

			if (!subtitle) {
				return;
			}

			// Remember what the server rendered the first time this stage is
			// overwritten, so restoreWizardSubtitles() can put it back.
			if (!Object.prototype.hasOwnProperty.call(serverSubtitles, stageNumber)) {
				serverSubtitles[stageNumber] = subtitle.textContent;
			}

			subtitle.textContent = text;
			// The subtitle is ellipsised (white-space: nowrap), and a filename
			// is exactly the kind of value whose distinguishing part is at the
			// end — two workbooks differing only in a trailing v2/v3 render
			// identically without this.
			subtitle.setAttribute('title', text);
		};

		/** Undo setWizardSubtitle(), so a bar that survives a failed check stops describing it. */
		var restoreWizardSubtitles = function () {
			Object.keys(serverSubtitles).forEach(function (stageNumber) {
				var stage = document.querySelector('.cadco-import-stage[data-stage="' + stageNumber + '"]');
				var subtitle = stage ? stage.querySelector('.cadco-import-stage-subtitle') : null;

				if (subtitle) {
					subtitle.textContent = serverSubtitles[stageNumber];
					subtitle.removeAttribute('title');
				}
			});

			serverSubtitles = {};
		};

		var setStagePending = function (name) {
			var row = stageRow(name);

			if (row) {
				row.classList.add('is-active');

				var label = row.querySelector('.cadco-import-checklist-name');

				if (label) {
					setWizardSubtitle(2, label.textContent.trim().toLowerCase() + '…');
				}
			}
		};

		/** A stage is only ever marked done here, with the real figure the server just returned. */
		var setStageDone = function (name, figureText) {
			var row = stageRow(name);

			if (!row) {
				return;
			}

			row.classList.remove('is-active');
			row.classList.add('is-done');

			var figure = row.querySelector('.cadco-import-checklist-figure');

			if (figure) {
				figure.textContent = figureText;
			}

			stagesDone += 1;
			updateCheckingProgress();
		};

		var setStageError = function (name) {
			var row = stageRow(name);

			if (row) {
				row.classList.remove('is-active');
				row.classList.add('is-error');
			}
		};

		/**
		 * Mirrors CADCO_Import_View::notice()'s own markup exactly
		 * (`<div class="cadco-import-message is-error"><p>…</p></div>`) so an
		 * error surfaced by the staged check reads identically to one the
		 * synchronous, no-JS path renders server-side — same component, same
		 * selector.
		 *
		 * Deliberately NOT a wp-admin `.notice`: core's common.js hoists
		 * anything carrying that class to just under the page heading, which
		 * would tear this message away from the stage bar it is rendered
		 * beside. See CADCO_Import_View::notice().
		 *
		 * textContent throughout: the message may echo server-supplied text
		 * derived from the uploaded filename.
		 */
		var showCheckError = function (message) {
			var wrap = document.querySelector('.cadco-import');

			if (!wrap) {
				return;
			}

			var existing = wrap.querySelector('.cadco-import-checking-notice');

			if (existing) {
				existing.parentNode.removeChild(existing);
			}

			var notice = document.createElement('div');
			notice.className = 'cadco-import-message is-error cadco-import-checking-notice';

			var p = document.createElement('p');
			p.textContent = message;
			notice.appendChild(p);

			var stagebar = wrap.querySelector('.cadco-import-stagebar');

			if (stagebar && stagebar.parentNode) {
				stagebar.parentNode.insertBefore(notice, stagebar);
			} else {
				wrap.insertBefore(notice, wrap.firstChild);
			}
		};

		/** Let the operator try again after a failed stage. */
		var resetToUpload = function () {
			submitted = false;

			// The bar must stop describing a check that is no longer running;
			// the checklist itself is deliberately NOT cleared here, so the
			// failed state stays readable until another file is chosen (see
			// resetCheckingPanel()).
			restoreWizardSubtitles();

			if (checkingPanel) {
				checkingPanel.hidden = true;
			}

			if (uploadGrid) {
				uploadGrid.hidden = false;
			}
		};

		var totalWrites = function (planCounts) {
			planCounts = planCounts || {};

			return ['create', 'update', 'rename', 'trash', 'untrash'].reduce(function (sum, key) {
				return sum + (Number(planCounts[key]) || 0);
			}, 0);
		};

		var postCheckStage = function (action, extra) {
			var body = new FormData();
			body.append('action', action);
			body.append('_wpnonce', checkingConfig.nonce);

			if (extra) {
				Object.keys(extra).forEach(function (key) {
					body.append(key, extra[key]);
				});
			}

			return window.fetch(checkingConfig.ajaxUrl, {
				method: 'POST',
				body: body,
				credentials: 'same-origin'
			}).then(function (response) { return response.json(); });
		};

		var navigateToChecked = function (runId) {
			window.location.href = window.location.pathname
				+ '?post_type=product&page=cadco-import&checked_run=' + encodeURIComponent(runId);
		};

		var errorMessage = function (result) {
			return (result && result.data && result.data.message) || checkingConfig.i18n.checkFailed;
		};

		/**
		 * Stage 3: build the plan. Only ever reached once stage 2 has already
		 * reported a passing workbook — an invalid one stops at stage 2 and
		 * never calls this.
		 */
		var runPlanStage = function (runId) {
			setStagePending('plan');

			return postCheckStage('cadco_import_check_plan', { run_id: runId }).then(function (result) {
				if (!result.success) {
					setStageError('plan');
					showCheckError(errorMessage(result));
					resetToUpload();
					return;
				}

				setStageDone('plan', format(checkingConfig.i18n.planChanges, { count: totalWrites(result.data.counts) }));
				navigateToChecked(runId);
			});
		};

		/** Stage 2: validate the rows stage 1 already read. */
		var runValidateStage = function (runId) {
			setStagePending('validate');

			return postCheckStage('cadco_import_check_validate', { run_id: runId }).then(function (result) {
				if (!result.success) {
					setStageError('validate');
					showCheckError(errorMessage(result));
					resetToUpload();
					return;
				}

				setStageDone('validate', format(checkingConfig.i18n.rowsChecked, { count: result.data.checked }));

				// An invalid workbook has nothing left to plan — stop here and
				// let the browser land on the issue report, exactly as the
				// task brief requires ("stops at the validate stage").
				if (result.data.issues > 0) {
					navigateToChecked(runId);
					return;
				}

				return runPlanStage(runId);
			});
		};

		/** Stage 1: file-type check, archive the run, read the workbook. */
		var runCheck = function (file) {
			setStagePending('read');

			var body = new FormData();
			body.append('action', 'cadco_import_check_read');
			body.append('_wpnonce', checkingConfig.nonce);
			body.append('workbook', file);

			window.fetch(checkingConfig.ajaxUrl, {
				method: 'POST',
				body: body,
				credentials: 'same-origin'
			})
				.then(function (response) { return response.json(); })
				.then(function (result) {
					if (!result.success) {
						setStageError('read');
						showCheckError(errorMessage(result));
						resetToUpload();
						return;
					}

					var sheetsRead = result.data.sheets ? result.data.sheets.length : 0;

					setStageDone('read', format(checkingConfig.i18n.sheetsChecked, { read: sheetsRead, total: sheetsRead }));

					return runValidateStage(result.data.run_id);
				})
				.catch(function () {
					setStageError('read');
					showCheckError(checkingConfig.i18n.network);
					resetToUpload();
				});
		};

		/**
		 * Reveal the checking panel and start the staged flow — the
		 * replacement for the old straight-to-submit auto-submit. Guarded
		 * against firing twice the same way the auto-submit was: a
		 * double-change (picker then drop) must not start a second run while
		 * the first is still in flight.
		 */
		var beginCheck = function () {
			if (submitted || !fileInput.files || !fileInput.files.length) {
				return;
			}

			var file = fileInput.files[0];

			// No localized config means admin_enqueue_scripts never ran for
			// this hook (the one real gap the "enqueues its script" E2E test
			// exists to catch) — fall back to the plain synchronous submit
			// rather than calling fetch() against undefined config.
			if (!checkingConfig || !checkingPanel || !uploadGrid) {
				submitted = true;
				submitFormDirectly();
				return;
			}

			submitted = true;

			if (fileInfoOut) {
				// textContent: file.name is attacker-supplied.
				fileInfoOut.textContent = file.name + ' · ' + formatBytes(file.size);
			}

			// A new workbook gets a clean panel — never the figures the last
			// attempt left behind.
			resetCheckingPanel();

			// Stage 01's subtitle was rendered as "no workbook yet"; there is
			// one now, and its name is a fact the browser already holds.
			setWizardSubtitle(1, file.name);

			uploadGrid.hidden = true;
			checkingPanel.hidden = false;

			runCheck(file);
		};

		fileInput.addEventListener('change', function () {
			showFilename();
			beginCheck();
		});

		// dragover must be cancelled too, or the browser's default handler
		// navigates the tab to the dropped file instead of firing `drop`.
		['dragenter', 'dragover'].forEach(function (name) {
			dropzone.addEventListener(name, function (event) {
				event.preventDefault();
				dropzone.classList.add('is-dragover');
			});
		});

		['dragleave', 'drop'].forEach(function (name) {
			dropzone.addEventListener(name, function (event) {
				event.preventDefault();

				// dragleave also fires when the pointer crosses onto a child
				// element; ignore those so the highlight does not flicker.
				if (name === 'dragleave' && dropzone.contains(event.relatedTarget)) {
					return;
				}

				dropzone.classList.remove('is-dragover');
			});
		});

		dropzone.addEventListener('drop', function (event) {
			var dropped = event.dataTransfer && event.dataTransfer.files;

			if (!dropped || !dropped.length) {
				return;
			}

			// Assigning a DataTransfer's FileList straight across is what keeps
			// this a real upload rather than a second, parallel code path.
			fileInput.files = dropped;
			showFilename();
			beginCheck();
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
		// Binds this request to the run the screen was rendered for. The
		// server refuses any mismatch (CADCO_Import_Admin::ajax_batch()), which
		// is what stops a Review left open in one tab from applying a workbook
		// checked later in another. Read from the button rather than held in a
		// closure so it cannot drift from what was rendered.
		body.append('run_id', button.getAttribute('data-run-id') || '');
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
