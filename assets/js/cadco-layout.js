/**
 * Tell the scroll runtime that the page got taller or shorter.
 *
 * Lenis measures the document once and caches it, and ScrollTrigger caches
 * every trigger's start and end against that measurement. Neither watches for
 * a block changing height on its own: switching a tab, opening an accordion or
 * an image arriving late all move everything below them without either knowing,
 * so pinned sections release at the wrong point and the scrollable length is
 * wrong until the next resize.
 *
 * Any block that changes its own height calls window.cadcoLayoutChanged().
 *
 * Calls are coalesced into the next frame, because a block usually changes
 * several things at once and ScrollTrigger.refresh() re-measures every trigger
 * on the page -- doing that three times in one tick is wasted work and can be
 * seen.
 */
(function () {
	'use strict';

	var queued = false;

	function run() {
		queued = false;

		/* Lenis first: ScrollTrigger reads the scroll length Lenis reports, so
		   refreshing in the other order measures against the stale figure. */
		if (window.protoLenis && typeof window.protoLenis.resize === 'function') {
			window.protoLenis.resize();
		}

		if (window.ScrollTrigger && typeof window.ScrollTrigger.refresh === 'function') {
			window.ScrollTrigger.refresh();
		}
	}

	window.cadcoLayoutChanged = function () {
		if (queued) { return; }

		queued = true;

		/* Two frames: one for the browser to lay the new height out, one to
		   measure it. A single frame can still read the old height when the
		   change came from a class or an attribute. */
		requestAnimationFrame(function () {
			requestAnimationFrame(run);
		});
	};

	/**
	 * A late-loading image is the other common cause, and no block can predict
	 * it, so that one is handled here for everybody.
	 */
	document.addEventListener('load', function (e) {
		if (e.target && e.target.tagName === 'IMG') {
			window.cadcoLayoutChanged();
		}
	}, true);
})();
