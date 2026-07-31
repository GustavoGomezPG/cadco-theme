/**
 * Proto-theme site-wide init.
 *
 * Boots a single shared Lenis instance and exposes it on the window as
 *   window.protoLenis
 * so any block-level script (or theme code) can do `protoLenis.stop()`
 * / `protoLenis.start()` without re-instantiating its own Lenis.
 *
 * Loaded as `proto-init` with `proto-lenis` as a dep, so by
 * the time this file runs window.Lenis is guaranteed to exist.
 */
(function () {
  'use strict';

  if (!window.Lenis) {
    return;
  }

  var lenis = new window.Lenis({
    duration: 1.2,
    easing: function (t) { return Math.min(1, 1.001 - Math.pow(2, -10 * t)); },
    smoothWheel: true,
    smoothTouch: false,
  });

  function raf(time) {
    lenis.raf(time);
    requestAnimationFrame(raf);
  }
  requestAnimationFrame(raf);

  window.protoLenis = lenis;

  /**
   * Bridge Lenis to GSAP ScrollTrigger.
   *
   * Lenis hijacks the native scroll, so ScrollTrigger's default scrollTop
   * reads would lag behind reality. Forwarding every Lenis `scroll` event
   * into ScrollTrigger.update() keeps trigger positions accurate frame-
   * by-frame.
   *
   * ScrollTrigger may load after this file (Proto-Blocks block view.js
   * scripts have no declared deps), so we retry once on DOMContentLoaded
   * if it's not ready yet.
   *
   * Because this script is enqueued in the footer, DOMContentLoaded may
   * have already fired by the time we reach this code. Guard on
   * document.readyState: if the document is still 'loading' we register
   * the listener normally; otherwise we call bridgeScrollTrigger() once
   * more immediately (best-effort).
   */
  var stBridged = false;

  function bridgeScrollTrigger() {
    if (stBridged) return true;
    if (!window.gsap || !window.ScrollTrigger) return false;

    window.gsap.registerPlugin(window.ScrollTrigger);
    lenis.on('scroll', window.ScrollTrigger.update);
    stBridged = true;
    return true;
  }

  if (!bridgeScrollTrigger()) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', bridgeScrollTrigger);
    } else {
      bridgeScrollTrigger();
    }
  }

  /**
   * Delegated anchor-link handler.
   *
   * When Lenis is driving the page scroll, the browser's native "jump to
   * #id" on anchor clicks doesn't behave -- the URL hash updates but the
   * viewport doesn't move because Lenis owns the scroll position. Intercept
   * those clicks and route them through `lenis.scrollTo()`.
   *
   * Bail-outs (let the browser handle these natively):
   *   - modified clicks (cmd / ctrl / shift / alt / middle-button)
   *   - target="_blank" or other non-_self frames
   *   - download attribute set
   *   - cross-origin or different-path URLs (full navigation)
   *   - hash that doesn't resolve to an element on this page
   *   - href="#" (no-op anchors)
   */
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented) return;
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    if (typeof e.button === 'number' && e.button !== 0) return;

    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    if (a.target && a.target !== '_self') return;
    if (a.hasAttribute('download')) return;

    var href = a.getAttribute('href');
    if (!href || href === '#' || href.length === 0) return;

    // Resolve the hash for THIS page (skip if it's a different page).
    var hash = null;
    if (href.charAt(0) === '#') {
      hash = href;
    } else {
      try {
        var url = new URL(a.href, window.location.href);
        if (url.origin !== window.location.origin) return;
        if (url.pathname !== window.location.pathname) return;
        if (!url.hash) return;
        hash = url.hash;
      } catch (err) {
        return;
      }
    }

    var target;
    try {
      target = document.querySelector(hash);
    } catch (err) {
      return; // invalid CSS selector in the hash
    }
    if (!target) return;

    e.preventDefault();
    lenis.scrollTo(target);

    // Keep the URL in sync without re-triggering the browser's native jump.
    if (window.history && typeof window.history.pushState === 'function') {
      window.history.pushState(
        null,
        '',
        window.location.pathname + window.location.search + hash
      );
    }
  });
})();
