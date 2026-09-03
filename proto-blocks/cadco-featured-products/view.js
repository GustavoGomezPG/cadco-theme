/**
 * Cadco featured products — active card tracking and autoplay.
 *
 * The carousel itself is CSS scroll-snap, so it scrolls, snaps, drags and
 * responds to the keyboard with no JavaScript at all. This file adds two
 * things on top: which card currently sits in the middle (so the stylesheet
 * can make that one opaque), and optional automatic advancing.
 *
 * A visitor with JS off still gets a working carousel — the first card is
 * marked active server-side in template.php and simply stays that way.
 *
 * Loaded as `proto-blocks-cadco-featured-products`. No library dependencies:
 * the theme vendors GSAP, SplitText, ScrollTrigger and Lenis, but none is
 * needed here and Draggable — which would be — is not vendored.
 */
(function () {
	'use strict';

	var ACTIVE = 'data-featured-active';
	var REDUCED = '(prefers-reduced-motion: reduce)';

	/**
	 * Whether this visitor has asked for reduced motion.
	 *
	 * Read at call time rather than cached: the setting can change mid-session
	 * (a laptop switching to low-power, or someone toggling it in the OS), and
	 * an autoplaying carousel is exactly the kind of movement the preference
	 * exists to stop.
	 */
	function prefersReducedMotion() {
		return window.matchMedia && window.matchMedia(REDUCED).matches;
	}

	function setUp(track) {
		var originals = Array.prototype.slice.call(
			track.querySelectorAll('.cadco-featured-products__card')
		);

		if (originals.length < 2) {
			return;
		}

		/* ---------------------------------------------------------------
		   Infinite loop
		   ---------------------------------------------------------------

		   A copy of the set is placed either side of the real one, and the
		   scroll position is teleported back by one set-width whenever it
		   drifts into a copy. Because the three sets are identical, the
		   teleport is invisible: the pixels under the viewport before and
		   after the jump are the same.

		   Cloning happens here rather than in template.php on purpose. The
		   markup should carry each product exactly ONCE -- duplicated cards
		   in the HTML would be duplicated content for search engines and
		   would make a screen reader read every product three times. The
		   copies are decorative, so they are hidden from assistive tech and
		   taken out of the tab order below.

		   Without JavaScript there are no copies and the row simply ends,
		   which is a working carousel, just a finite one. */

		var setWidth = 0;
		var baseLeft = 0;

		function decorativeCopy(card) {
			var copy = card.cloneNode(true);

			copy.setAttribute('aria-hidden', 'true');
			copy.setAttribute('data-featured-clone', '');
			copy.removeAttribute('data-featured-active');

			// A copy must never be a tab stop or a second announcement of a
			// product the reader has already met.
			Array.prototype.forEach.call(copy.querySelectorAll('a'), function (a) {
				a.setAttribute('tabindex', '-1');
			});

			return copy;
		}

		var leading = document.createDocumentFragment();
		var trailing = document.createDocumentFragment();

		originals.forEach(function (card) {
			leading.appendChild(decorativeCopy(card));
			trailing.appendChild(decorativeCopy(card));
		});

		var firstOriginal = originals[0];

		track.insertBefore(leading, firstOriginal);
		track.appendChild(trailing);

		// Every card now on the track, copies included -- the active-card
		// maths has to consider them or the centred card would stop being
		// marked the moment the loop crossed into a copy.
		var cards = Array.prototype.slice.call(
			track.querySelectorAll('.cadco-featured-products__card')
		);

		/* ---------------------------------------------------------------
		   Which card is centred
		   --------------------------------------------------------------- */

		var ticking = false;

		/**
		 * The index of the card whose centre is nearest the track's centre.
		 *
		 * Measured with getBoundingClientRect rather than offsetLeft: a card's
		 * offsetParent is the positioned section, not the track, so offsetLeft
		 * would ignore how far the track has been scrolled.
		 */
		function centredIndex() {
			var bounds = track.getBoundingClientRect();
			var middle = bounds.left + bounds.width / 2;
			var winner = 0;
			var shortest = Infinity;

			cards.forEach(function (card, i) {
				var rect = card.getBoundingClientRect();
				var distance = Math.abs(rect.left + rect.width / 2 - middle);

				if (distance < shortest) {
					shortest = distance;
					winner = i;
				}
			});

			return winner;
		}

		function paint() {
			ticking = false;

			var winner = centredIndex();

			cards.forEach(function (card, i) {
				if (i === winner) {
					card.setAttribute(ACTIVE, '');
				} else {
					card.removeAttribute(ACTIVE);
				}
			});
		}

		function schedule() {
			if (!ticking) {
				ticking = true;
				window.requestAnimationFrame(paint);
			}
		}

		/**
		 * Measure one set and park the view on the real (middle) one.
		 *
		 * Taken from the gap between a card and its copy rather than from
		 * scrollWidth/3: the track's padding-inline is half a viewport at each
		 * end, so a third of the scrollable width is not a set.
		 */
		function measureLoop() {
			var firstCopyAfter = cards[originals.length * 2];

			// A set is the distance from a card to its own copy. Sibling
			// offsetLefts share one coordinate space, so their difference is
			// safe even though the absolute values are not comparable to
			// scrollLeft.
			setWidth = firstCopyAfter.offsetLeft - originals[0].offsetLeft;

			// Where the real set sits, in scrollLeft terms.
			baseLeft = scrollLeftToCentre(originals[0]);
		}

		/**
		 * Teleport back onto the real set, one set-width at a time.
		 *
		 * Only ever called once the scroll has settled. Moving scrollLeft while
		 * a smooth scroll is in flight does not just move the view -- the
		 * browser keeps animating toward the target it was already given, so
		 * the carousel would land a set away from where it meant to.
		 */
		function recentre() {
			if (!setWidth) {
				return;
			}

			var offset = track.scrollLeft - baseLeft;

			if (offset < -setWidth / 2) {
				track.scrollLeft += setWidth;
			} else if (offset > setWidth / 2) {
				track.scrollLeft -= setWidth;
			}
		}

		var settleTimer = null;

		function onScroll() {
			schedule();

			// `scrollend` is not in every browser this has to run in, so the
			// settle is detected by absence of scrolling instead.
			window.clearTimeout(settleTimer);
			settleTimer = window.setTimeout(recentre, 160);
		}

		track.addEventListener('scroll', onScroll, { passive: true });
		window.addEventListener('resize', function () {
			measureLoop();
			schedule();
		});

		measureLoop();

		// Start on the real set rather than the leading copy.
		track.scrollLeft = baseLeft;

		// The server marked the first card active; re-derive once in case the
		// browser restored a scroll position on reload.
		paint();

		/* ---------------------------------------------------------------
		   Autoplay
		   --------------------------------------------------------------- */

		var delay = parseInt(track.getAttribute('data-featured-autoplay'), 10);

		if (!delay || prefersReducedMotion()) {
			return;
		}

		var timer = null;
		var abandoned = false;
		var onScreen = false;
		var hovered = false;
		var focused = false;

		/**
		 * Scroll the track itself rather than calling scrollIntoView.
		 *
		 * scrollIntoView would also scroll the PAGE when the carousel is only
		 * partly in view — so an autoplay tick could yank the document out from
		 * under someone reading the section above. Setting scrollLeft can only
		 * ever move the track.
		 */
		/**
		 * The scrollLeft that would put this card in the middle of the track.
		 *
		 * Measured from live rects rather than offsetLeft: a card's
		 * offsetParent is the positioned section, not the track, so offsetLeft
		 * is in the wrong coordinate space to compare against scrollLeft.
		 */
		function scrollLeftToCentre(card) {
			var bounds = track.getBoundingClientRect();
			var rect = card.getBoundingClientRect();

			return (
				track.scrollLeft +
				(rect.left - bounds.left) -
				(bounds.width - rect.width) / 2
			);
		}

		function centreOn(index) {
			track.scrollTo({ left: scrollLeftToCentre(cards[index]), behavior: 'smooth' });
		}

		function advance() {
			centreOn((centredIndex() + 1) % cards.length);
		}

		function running() {
			return !abandoned && onScreen && !hovered && !focused && !document.hidden;
		}

		function tick() {
			if (running()) {
				advance();
			}
		}

		function play() {
			if (timer === null && !abandoned) {
				timer = window.setInterval(tick, delay);
			}
		}

		function pause() {
			if (timer !== null) {
				window.clearInterval(timer);
				timer = null;
			}
		}

		/**
		 * Stop for good the moment the visitor takes over.
		 *
		 * Pausing would not be enough: someone who has deliberately scrolled to
		 * a card is reading it, and having the carousel resume and slide it away
		 * a few seconds later is the single most complained-about behaviour a
		 * carousel has. Once they drive, we stay out of the way.
		 *
		 * Listens for input events, never for `scroll` — our own smooth
		 * scrolling raises `scroll` too, and would abandon autoplay on its
		 * first tick.
		 */
		function abandon() {
			abandoned = true;
			pause();
		}

		['pointerdown', 'touchstart', 'keydown'].forEach(function (type) {
			track.addEventListener(type, abandon, { passive: true, once: true });
		});

		/**
		 * A wheel counts as taking over only if it is HORIZONTAL.
		 *
		 * Listening for `wheel` outright was wrong: scrolling the page with the
		 * pointer happening to rest over the carousel fired it, so autoplay was
		 * abandoned for good by someone who had done nothing but scroll past.
		 * Comparing the deltas separates the two gestures -- sideways over the
		 * track is deliberate carousel input, downward is the page.
		 */
		track.addEventListener(
			'wheel',
			function (event) {
				if (Math.abs(event.deltaX) > Math.abs(event.deltaY)) {
					abandon();
				}
			},
			{ passive: true }
		);

		track.addEventListener('mouseenter', function () {
			hovered = true;
			pause();
		});

		track.addEventListener('mouseleave', function () {
			hovered = false;
			play();
		});

		// focusin/focusout rather than focus/blur: those do not bubble, and the
		// thing being focused is a card's link, not the track.
		track.addEventListener('focusin', function () {
			focused = true;
			pause();
		});

		track.addEventListener('focusout', function () {
			focused = false;
			play();
		});

		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				pause();
			} else {
				play();
			}
		});

		/**
		 * Only run while the carousel is actually on screen.
		 *
		 * Without this the timer keeps firing for a section nobody is looking
		 * at, and a visitor who scrolls back up finds it several cards along
		 * from where they left it.
		 */
		if (typeof window.IntersectionObserver === 'function') {
			new window.IntersectionObserver(
				function (entries) {
					onScreen = entries[0].isIntersecting;

					if (onScreen) {
						play();
					} else {
						pause();
					}
				},
				{ threshold: 0.25 }
			).observe(track);
		} else {
			onScreen = true;
			play();
		}
	}

	function init() {
		var tracks = document.querySelectorAll('[data-featured-track]');

		Array.prototype.forEach.call(tracks, setUp);
	}

	if (document.readyState !== 'loading') {
		init();
	} else {
		document.addEventListener('DOMContentLoaded', init, { once: true });
	}
})();
