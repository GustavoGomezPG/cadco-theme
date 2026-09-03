/**
 * Cadco image carousel — the diagonal scroll-driven band.
 *
 * The markup ships as a plain scrollable row (see template.php). This upgrades
 * it to the design's diagonal band, advancing endlessly as the section crosses
 * the viewport. It is a strict enhancement: if GSAP or ScrollTrigger is
 * missing, or the visitor has asked for reduced motion, this returns before
 * touching anything and the row stays exactly as rendered.
 *
 * Unpinned by design. A pinned carousel converts page scroll into carousel
 * progress and traps the reader until it has finished; this one simply advances
 * while the section passes, so scrolling never stops meaning "scroll".
 *
 * WHY THE POSITIONS ARE COMPUTED RATHER THAN KEYFRAMED
 * The design specifies three states -- centre, one step out, two steps out.
 * A keyframed loop would only be correct at those three positions, and every
 * frame between them is where a scrubbing carousel actually lives. So each
 * item's transform is a continuous function of its signed distance from the
 * centre, which passes through the design's values at d = 0, 1 and 2.
 *
 * Loaded as `proto-blocks-cadco-image-carousel`; functions.php appends
 * proto-gsap and proto-scroll-trigger to its dependencies.
 */
(function () {
	'use strict';

	var REDUCED = '(prefers-reduced-motion: reduce)';

	/* The design's states, two steps out from centre. */
	var FAR_SCALE = 0.655;
	var FAR_OPACITY = 0.15;
	var FAR_BLUR = 6.05;

	/* Each step to the right sits this much higher, at the design's 443px card.
	   Scaled with the card below so the diagonal keeps its proportions when the
	   cards shrink -- held at a flat 54px it would be a gentle rise on desktop
	   and a staircase on a phone, and the band would clip at the height that
	   fits the smaller cards. */
	var RISE_AT_DESIGN_WIDTH = 54;
	var DESIGN_CARD_W = 443;

	/* Beyond this many steps the tilt stops growing. Without the cap an item
	   two-and-a-half steps out would be turned so far it reads as an edge-on
	   sliver rather than a photograph receding. */
	var TILT_SPAN = 2;

	/* Beyond one step the band tucks inward and drops away faster, which is
	   what gives the row its sense of receding rather than merely shrinking. */
	var FAR_PULL_IN = 0.82;
	var FAR_RISE_GAIN = 1.6;

	/* Items further out than this are not painted at all. Blur is the most
	   expensive thing here, and there is no reason to run it on items that are
	   off screen -- with twelve images that is most of them. */
	var VISIBLE_SPAN = 2.6;

	function prefersReducedMotion() {
		return window.matchMedia && window.matchMedia(REDUCED).matches;
	}

	function lerp(a, b, t) {
		return a + (b - a) * t;
	}

	function clamp01(v) {
		return v < 0 ? 0 : v > 1 ? 1 : v;
	}

	/**
	 * Wrap a value into [-half, half).
	 *
	 * Was gsap.utils.wrap. Written out because the band's LAYOUT must not
	 * depend on GSAP: the block editor's canvas loads this script but not the
	 * theme's animation libraries, so anything that needs GSAP to position the
	 * cards cannot run there -- which is what left the editor showing a flat
	 * row while the front end showed the diagonal, and made the spacing
	 * controls impossible to judge.
	 */
	function wrapSigned(value, half) {
		var span = half * 2;
		var v = (value + half) % span;

		if (v < 0) {
			v += span;
		}

		return v - half;
	}

	/**
	 * Write one card's placement.
	 *
	 * Was gsap.quickSetter. Composing the transform string by hand costs a
	 * little more per frame than quickSetter's cached path, but it removes
	 * GSAP from the layout entirely -- and with at most a handful of cards
	 * visible, the difference is not measurable next to the blur.
	 */
	function place(el, x, y, scale, rotationY, opacity, blur) {
		el.style.transform =
			'translate3d(' + x + 'px, ' + y + 'px, 0) ' +
			'rotateY(' + rotationY + 'deg) ' +
			'scale(' + scale + ')';
		el.style.opacity = opacity;
		el.style.filter = blur > 0 ? 'blur(' + blur + 'px)' : 'none';
	}

	function setUp(track) {
		var section = track.closest('.cadco-image-carousel');
		var items = Array.prototype.slice.call(
			track.querySelectorAll('[data-carousel-item]')
		);

		if (!section || items.length < 2) {
			return;
		}

		var count = items.length;
		var half = count / 2;

		var travel = parseFloat(track.getAttribute('data-carousel-travel')) || 3;
		var tilt = parseFloat(track.getAttribute('data-carousel-tilt'));

		if (isNaN(tilt)) {
			tilt = 14;
		}
		var smoothing =
			(parseFloat(track.getAttribute('data-carousel-smoothing')) || 0) / 10;

		/* Step is read from the rendered item rather than hard-coded, so the
		   responsive widths in style.css stay the single source of truth. */
		var step = 0;
		var rise = RISE_AT_DESIGN_WIDTH;

		function measure() {
			var styles = window.getComputedStyle(track);
			var gap = parseFloat(styles.columnGap || styles.gap) || 13;
			var width = items[0].getBoundingClientRect().width;

			step = width + gap;
			rise = RISE_AT_DESIGN_WIDTH * (width / DESIGN_CARD_W);
		}

		var progress = { p: 0 };

		function render() {
			for (var i = 0; i < count; i++) {
				/* Signed distance from centre, in item widths, wrapped so the
				   band has no ends -- an item leaving the right edge is the
				   same item arriving at the left. */
				var item = items[i];
				var d = wrapSigned(i - progress.p, half);
				var ad = Math.abs(d);

				if (ad > VISIBLE_SPAN) {
					item.style.visibility = 'hidden';
					continue;
				}

				item.style.visibility = 'visible';

				/* 0 across the three crisp slots, ramping to 1 by two steps
				   out -- the design's recession, as a continuous value. */
				var t = clamp01(ad - 1);

				var turn = d < -TILT_SPAN ? -TILT_SPAN : d > TILT_SPAN ? TILT_SPAN : d;
				var blur = Math.round(lerp(0, FAR_BLUR, t) * 10) / 10;

				place(
					item,
					d * step * lerp(1, FAR_PULL_IN, t),
					-d * rise * lerp(1, FAR_RISE_GAIN, t),
					lerp(1, FAR_SCALE, t),
					-turn * tilt,
					lerp(1, FAR_OPACITY, t),
					blur
				);

			}
		}

		/* The editor is already done. template.php renders the band's first
		   frame into the markup and puts the section into `is-enhanced` itself,
		   so the canvas shows the real geometry whether or not this script ever
		   runs there -- which matters, because the canvas is an iframe that
		   mounts blocks asynchronously and does not load the theme's libraries.
		   Re-deriving the same frame here would buy nothing and risk drift. */
		if (!track.hasAttribute('data-carousel-live')) {
			return;
		}

		/* From here on this is a live page.

		   `is-enhanced` is the promise that JavaScript has taken over the
		   layout: style.css switches from the fallback row to the absolute band
		   on it, and strips the server-rendered transforms back off when it is
		   absent. Adding it here rather than server-side is what makes the
		   no-JS page correct -- the row stays a row when nothing arrives to
		   drive it. `is-live` additionally makes the band inert to the pointer,
		   which the editor must not be. */
		measure();
		section.classList.add('is-enhanced');
		section.classList.add('is-live');

		/* The row no longer scrolls once the band is driven, so a focusable
		   track would be a tab stop that does nothing. The images keep their
		   alt text, which is what actually carries the content. */
		track.removeAttribute('tabindex');

		/* Re-measure after the class lands: `is-enhanced` changes the item box,
		   so a step measured before it would be the row's, not the band's. */
		measure();
		render();

		/* Without the animation libraries the band simply holds this frame --
		   which is the same frame PHP rendered, so nothing jumps. */
		if (!window.gsap || !window.ScrollTrigger) {
			return;
		}

		/* A tween on a proxy rather than a raw onUpdate: `scrub` only smooths
		   something it is driving, and this is what makes the band glide on
		   after the page has stopped instead of snapping to the scrollbar. */
		window.gsap.registerPlugin(window.ScrollTrigger);

		window.gsap.to(progress, {
			p: travel,
			ease: 'none',
			onUpdate: render,
			scrollTrigger: {
				trigger: section,
				start: 'top bottom',
				end: 'bottom top',
				scrub: smoothing > 0 ? smoothing : true,
				invalidateOnRefresh: true
			}
		});

		window.addEventListener('resize', function () {
			measure();
			render();
		});
	}

	function init() {
		/* Reduced motion stops this script entirely. Everything below it only
		   ever runs on a live page now, so there is nothing left to enhance:
		   the section keeps the fallback row, and the editor keeps the still
		   band template.php rendered -- a still diagonal is not motion, and an
		   author who prefers reduced motion still has to see what they are
		   laying out. */
		if (prefersReducedMotion()) {
			return;
		}

		var tracks = document.querySelectorAll('[data-carousel-track]');

		Array.prototype.forEach.call(tracks, setUp);
	}

	if (document.readyState !== 'loading') {
		init();
	} else {
		document.addEventListener('DOMContentLoaded', init, { once: true });
	}
})();
