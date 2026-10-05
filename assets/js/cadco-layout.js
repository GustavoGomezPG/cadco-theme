/**
 * Keep the scroll runtime in step when a block changes the page's height.
 *
 * Lenis measures the document once and caches it; ScrollTrigger caches every
 * trigger's start and end against that measurement. Neither notices a block
 * changing its own height -- a tab panel swapping for one of a different size,
 * an accordion opening. When that happens the page's scrollable length is wrong
 * until the next window resize: the last stretch of the page cannot be reached,
 * or the page scrolls past its end.
 *
 * A block that changes its own height calls window.cadcoLayoutChanged().
 *
 * Two approaches were tried and rejected before this one, both worth recording
 * because each looked obviously right:
 *
 *   A timed retry loop, re-applying while Lenis and the document disagreed.
 *   ScrollTrigger.refresh() can bring lazy images into view; their load events
 *   queued another refresh, and the two fed each other until the renderer
 *   stopped responding.
 *
 *   A ResizeObserver on document.body, so no block had to announce anything.
 *   Pinning changes the body's height by design -- that is what a pin spacer
 *   is -- so the observer fired throughout a pinned section and the refreshes
 *   it triggered reset the scrub. The about page's timeline stopped moving
 *   altogether.
 *
 * So: explicit calls only, and at most one follow-up check per call.
 */
(function () {
	'use strict';

	var queued = false;

	function realMax() {
		return Math.max(0, document.documentElement.scrollHeight - window.innerHeight);
	}

	function resizeLenis() {
		if (window.protoLenis && typeof window.protoLenis.resize === 'function') {
			window.protoLenis.resize();
		}
	}

	/**
	 * ScrollTrigger first, Lenis last.
	 *
	 * Refreshing re-creates pin spacers, so the document's height is in flux
	 * while it runs; a Lenis measurement taken before or during that reads a
	 * height the page is about to leave. Measured here: switching to the
	 * shorter table left Lenis allowing 5796px against a document of 4759 when
	 * Lenis went first, and agreeing exactly when it went last.
	 *
	 * The final measurement is a frame later so the browser has laid the
	 * refreshed page out before it is read.
	 */
	function apply() {
		if (window.ScrollTrigger && typeof window.ScrollTrigger.refresh === 'function') {
			window.ScrollTrigger.refresh();
		}

		requestAnimationFrame(function () {
			resizeLenis();
			window.dispatchEvent(new CustomEvent('cadco:layout-changed'));
		});
	}

	function run() {
		queued = false;

		apply();

		/*
		 * Two checks, at fixed moments, then nothing. A single pass can read a
		 * layout that has not finished settling and leave Lenis a thousand
		 * pixels out, and how long it takes varies with what changed. These are
		 * scheduled once per call and never reschedule themselves, so this
		 * cannot become the retry loop that once locked the renderer.
		 */
		[300, 900].forEach(function (delay) {
		window.setTimeout(function () {
			if (window.protoLenis && Math.abs(window.protoLenis.limit - realMax()) > 2) {
				/*
				 * A bare re-measure, not another apply(). Refreshing
				 * ScrollTrigger re-creates its spacers and puts the height back
				 * in flux, so going through apply() again measured the same
				 * wrong figure; measured here, the shorter table left Lenis on
				 * 5796 against a 4759 document however many times apply() ran,
				 * and agreed immediately on a resize alone.
				 */
				resizeLenis();
			}
		}, delay);
		});
	}

	/**
	 * Coalesce into the frame after next: a block usually changes several things
	 * at once, and a refresh re-measures every trigger on the page. Two frames
	 * rather than one because the first lets the browser lay the new height out
	 * and the second measures it.
	 */
	window.cadcoLayoutChanged = function () {
		if (queued) { return; }

		queued = true;

		requestAnimationFrame(function () {
			requestAnimationFrame(run);
		});
	};

	/**
	 * Re-measure whenever ScrollTrigger refreshes.
	 *
	 * A pin changes the document's height by design: ScrollTrigger inserts a
	 * spacer the size of the pinned distance. That happens after Lenis has
	 * measured, and Lenis is never told, so on a page with a pinned section its
	 * idea of the scrollable length is short by the whole pinned distance and
	 * the end of the page cannot be reached at all. Measured on the about page:
	 * Lenis allowed 3728px where the document could scroll 6379, which put the
	 * footer out of reach entirely.
	 *
	 * This is the standard pairing of the two libraries and belongs here rather
	 * than in any one block: resize on every refresh, including the first,
	 * whoever caused it. Lenis's resize does not itself refresh ScrollTrigger,
	 * so this cannot feed back.
	 */
	function bindRefresh() {
		if (!window.ScrollTrigger || typeof window.ScrollTrigger.addEventListener !== 'function') {
			return false;
		}

		window.ScrollTrigger.addEventListener('refresh', function () {
			resizeLenis();
		});

		return true;
	}

	/* ScrollTrigger may load after this file: block view scripts declare no
	   dependencies, so it can arrive later. Try now, then once more when the
	   document is ready. */
	if (!bindRefresh()) {
		document.addEventListener('DOMContentLoaded', bindRefresh, { once: true });
	}

	/*
	 * A hidden tab does not run requestAnimationFrame, so a height change made
	 * while the tab is in the background is measured only when it comes back.
	 * The queued frame does fire on return, which covers it, but a block may
	 * also have resized without having asked for anything -- so re-measure on
	 * the way back in. Cheap, and it costs nothing while the tab is away.
	 */
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden) { resizeLenis(); }
	});

	/* And one sync once everything has settled, for the pins created on load. */
	window.addEventListener('load', function () {
		window.setTimeout(resizeLenis, 300);
	});
})();
