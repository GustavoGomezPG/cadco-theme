/**
 * Proto-theme page transitions (Taxi.js).
 *
 * Boots a single Taxi Core against the [data-taxi] wrapper the templates
 * provide, registers a GSAP fade as the default transition, and exposes
 *   window.protoTaxi = { core, Transition, addTransition }
 *
 * Loaded as `proto-taxi-init` with `proto-taxi` and `proto-init` as deps, so
 * window.taxi and window.protoLenis are guaranteed to exist by the time this
 * file runs.
 */
(function () {
  'use strict';

  var taxi = window.taxi;
  if (!taxi || !taxi.Core) {
    return;
  }

  if (!document.querySelector('[data-taxi]')) {
    console.warn('[proto-taxi] No [data-taxi] wrapper found — page transitions are disabled. See the theme README.');
    return;
  }

  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');

  var LINKS = [
    'a[href]',
    ':not([target])',
    ':not([href^="#"])',
    ':not([data-taxi-ignore])',
    ':not([download])',
    ':not([href*="/wp-admin"])',
    ':not([href*="wp-login"])',
    ':not([href^="mailto:"])',
    ':not([href^="tel:"])',
    ':not(#wpadminbar a)',
    ':not(.add_to_cart_button)',
    ':not(.wc-block-components-product-button a)'
  ].join('');

  /**
   * The element the fade animates. The view wrapper is a grid cell used for
   * stacking, so animate the <main> inside it when there is one.
   */
  function animTarget(el) {
    if (!el) return null;
    return el.querySelector('main') || el;
  }

  /**
   * Reset the scroll position for the incoming view.
   *
   * Called from the fade Transition's onEnter, at the point where the old
   * view has already been removed and the new one is appended (so document
   * height — and therefore Lenis's scroll limit — is correct) but still at
   * opacity: 0, making the jump invisible.
   *
   * `force: true` is required: Lenis's own scrollTo() silently no-ops
   * while the instance is stopped or locked
   * (`if (!this.isStopped && !this.isLocked || force)` in lenis.min.js), and
   * block code is documented (proto-init.js) to call `protoLenis.stop()` /
   * `.start()` as a scroll-lock API — an overlay or mobile nav left open
   * across a navigation must not leave the incoming page stuck mid-scroll.
   */
  function resetScroll() {
    if (window.protoLenis) {
      window.protoLenis.scrollTo(0, { immediate: true, force: true });
    } else {
      window.scrollTo(0, 0);
    }
  }

  var ProtoFade = class extends taxi.Transition {
    onLeave(props) {
      var el = animTarget(props.from);
      if (reduced.matches || !window.gsap || !el) {
        props.done();
        return;
      }
      window.gsap.to(el, {
        opacity: 0,
        duration: 0.4,
        ease: 'power2.inOut',
        onComplete: props.done
      });
    }

    onEnter(props) {
      safe('resetScroll', resetScroll);

      var el = animTarget(props.to);
      if (reduced.matches || !window.gsap || !el) {
        props.done();
        return;
      }
      window.gsap.fromTo(
        el,
        { opacity: 0 },
        { opacity: 1, duration: 0.5, ease: 'power2.out', onComplete: props.done }
      );
    }
  };

  var core = new taxi.Core({
    links: LINKS,
    transitions: { default: ProtoFade }
    // reloadJsFilter is left at its default: only [data-taxi-reload] scripts
    // are re-run. inc/proto-taxi.php decides which tags carry it.
  });

  window.protoTaxi = {
    core: core,
    Transition: taxi.Transition,

    /**
     * Register a transition usable via <a data-transition="name">.
     *
     * @param {string} name
     * @param {Function} TransitionClass  extends window.taxi.Transition
     * @return {object} this, for chaining
     */
    addTransition: function (name, TransitionClass) {
      core.transitions[name] = TransitionClass;
      return this;
    }
  };

  /* ----------------------------------------------------------------------
     Everything below is what Taxi does NOT do. Taxi sets document.title
     (Renderer.js:38) and nothing else outside the swapped view.
     ------------------------------------------------------------------- */

  var E = window.E;

  /**
   * The live region that announces navigations to screen readers. Created
   * here, at boot, before any navigation can occur: many screen readers
   * only pick up a live region if it already exists in the accessibility
   * tree before its content first changes, so creating it lazily inside
   * announce() (on the first NAVIGATE_END) would silently miss announcing
   * that first client-side navigation.
   */
  var announcer = document.createElement('div');
  announcer.className = 'proto-taxi-announcer';
  announcer.setAttribute('aria-live', 'polite');
  announcer.setAttribute('aria-atomic', 'true');
  document.body.appendChild(announcer);

  function liveView() {
    return document.querySelector('[data-taxi-view]');
  }

  function dispatch(name, detail) {
    document.dispatchEvent(new CustomEvent(name, { detail: detail }));
  }

  /** Copy <body class> from the fetched document. */
  function syncBodyClass(page) {
    if (page && page.body) {
      document.body.className = page.body.className;
    }
  }

  /**
   * Replace the head tags that describe the page. Yoast and friends emit
   * these per-URL, so a stale set would report the wrong page to crawlers
   * and share sheets.
   */
  var HEAD_SELECTORS = [
    'meta[name="description"]',
    'link[rel="canonical"]',
    'meta[property^="og:"]',
    'meta[name^="twitter:"]'
  ].join(',');

  function syncHead(page) {
    if (!page || !page.head) return;

    var current = document.head.querySelectorAll(HEAD_SELECTORS);
    for (var i = 0; i < current.length; i++) {
      current[i].remove();
    }

    var incoming = page.head.querySelectorAll(HEAD_SELECTORS);
    for (var n = 0; n < incoming.length; n++) {
      document.head.appendChild(incoming[n].cloneNode(true));
    }
  }

  /**
   * The header template part never re-renders, so WordPress's
   * current-menu-item classes would stay pinned to the first page loaded.
   *
   * Compares origin as well as pathname: an external link (e.g. a footer
   * link to a sister site) whose path happens to match the current page's
   * path must not be marked current. `link.href` is a browser-resolved
   * absolute URL for a plain <a>. An SVG <a> element's `.href` is an
   * SVGAnimatedString rather than a string, but `new URL()` still accepts
   * it — it gets coerced to a string and resolves fine against this page's
   * https: origin (it only throws "Invalid URL" against an opaque base
   * like about:blank, which this function never uses) — so that is not a
   * throw risk here. This function is still wrapped in `safe()` at its
   * caller (see the NAVIGATE_IN handler below) as defense-in-depth, same
   * as every other sync step in this file — not because of a known bug in
   * this particular function. (For a real, verified example of the kind of
   * failure `safe()` guards against, see killScrollTriggersIn() below: a
   * ScrollTrigger whose trigger selector matched nothing leaves a bare
   * string where an Element is expected, and container.contains() on it
   * throws a TypeError.)
   */
  function syncNavState(url) {
    var target = new URL(url, window.location.href);
    var hereOrigin = target.origin;
    var here = target.pathname.replace(/\/+$/, '');
    var links = document.querySelectorAll('.wp-block-navigation a[href]');

    for (var i = 0; i < links.length; i++) {
      var link = links[i];
      var parent = link.parentElement;
      var there = new URL(link.href, window.location.href);
      var isCurrent = there.origin === hereOrigin &&
        there.pathname.replace(/\/+$/, '') === here;

      link.classList.toggle('current-menu-item', isCurrent);
      if (isCurrent) {
        link.setAttribute('aria-current', 'page');
      } else {
        link.removeAttribute('aria-current');
      }

      if (parent && parent.classList.contains('wp-block-navigation-item')) {
        parent.classList.toggle('current-menu-item', isCurrent);
        parent.classList.toggle('current_page_item', isCurrent);
      }
    }
  }

  /** Keep the admin bar's "Edit" link pointing at the page being viewed. */
  function syncAdminBar(page) {
    var current = document.getElementById('wp-admin-bar-edit');
    if (!current || !page) return;

    var incoming = page.getElementById
      ? page.getElementById('wp-admin-bar-edit')
      : page.querySelector('#wp-admin-bar-edit');

    if (incoming) {
      current.replaceWith(incoming.cloneNode(true));
    } else {
      current.remove();
    }
  }

  /**
   * Announce the new page and move focus into it, so a swap is not silent
   * for screen-reader and keyboard users. (announcer itself is created at
   * boot — see above.)
   */
  function announce(title) {
    announcer.textContent = title;
  }

  function focusView(container) {
    if (!container) return;
    container.setAttribute('tabindex', '-1');
    container.focus({ preventScroll: true });
  }

  /**
   * Kill only the ScrollTriggers whose trigger element lived inside the view
   * being removed. Triggers created by the persistent header/footer survive.
   *
   * kill(false) is required: ScrollTrigger's kill(revert, ...) forwards an
   * undefined `revert` by default, and its internal disable() treats
   * `undefined !== false` as truthy, so a bare kill() reverts — stripping
   * any tween-set inline style (e.g. a pre-reveal opacity: 0) from the
   * outgoing elements, popping them visible right as the fade-out starts.
   */
  function killScrollTriggersIn(container) {
    if (!window.ScrollTrigger || !container) return;

    var all = window.ScrollTrigger.getAll();
    for (var i = 0; i < all.length; i++) {
      var trigger = all[i].trigger || all[i].vars.trigger;
      if (trigger && container.contains(trigger)) {
        all[i].kill(false);
      }
    }
  }

  /**
   * @unseenco/e's bus (E.on/E.emit) runs every listener in a plain forEach
   * with no try/catch. An exception thrown here propagates out of E.emit
   * inside Taxi's afterFetch Promise executor, which rejects that promise —
   * so loadScripts(), renderer.enter() and NAVIGATE_END never run, and
   * isTransitioning is never reset. Every later link click then dies
   * silently. Guard each sync step individually so one bad block/link
   * cannot take the rest of navigation down with it.
   */
  function safe(label, fn) {
    try {
      fn();
    } catch (err) {
      console.error('[proto-taxi]', label, err);
    }
  }

  E.on('NAVIGATE_OUT', function (payload) {
    var container = payload && payload.from && payload.from.renderer
      ? payload.from.renderer.content
      : liveView();

    safe('killScrollTriggersIn', function () {
      killScrollTriggersIn(container);
    });

    safe('proto:page-leave dispatch', function () {
      dispatch('proto:page-leave', { container: container });
    });
  });

  E.on('NAVIGATE_IN', function (payload) {
    if (!payload || !payload.to) return;

    var page = payload.to.page;
    var url = payload.to.finalUrl || window.location.href;

    safe('syncBodyClass', function () { syncBodyClass(page); });
    safe('syncHead', function () { syncHead(page); });
    safe('syncAdminBar', function () { syncAdminBar(page); });
    safe('syncNavState', function () { syncNavState(url); });
  });

  E.on('NAVIGATE_END', function (payload) {
    var container = payload && payload.to && payload.to.renderer
      ? payload.to.renderer.content
      : liveView();

    safe('focusView', function () { focusView(container); });
    safe('announce', function () { announce(document.title); });

    safe('resize', function () {
      /* The new content changed the document height; both libraries cache it. */
      if (window.protoLenis && typeof window.protoLenis.resize === 'function') {
        window.protoLenis.resize();
      }
      if (window.ScrollTrigger) {
        window.ScrollTrigger.refresh();
      }
    });

    safe('proto:page-ready dispatch', function () {
      dispatch('proto:page-ready', {
        container: container,
        url: window.location.href
      });
    });
  });

  /* The initial page load gets the same event, so block code has exactly one
     contract to write against. */
  function readyOnLoad() {
    dispatch('proto:page-ready', {
      container: liveView(),
      url: window.location.href
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', readyOnLoad);
  } else {
    readyOnLoad();
  }
})();
