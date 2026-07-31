/**
 * Proto-theme intro screen (once-per-session preloader).
 *
 * Markup is injected by PHP via wp_body_open. This script:
 *
 *   1. Reads sessionStorage('protoIntroShown'). If true, removes the overlay
 *      and exits -- no animation on repeat navigations within the session.
 *   2. Otherwise locks scroll (Lenis stop + body overflow lock), plays the
 *      Lottie animation pointed at by data-lottie-url, then on the
 *      animation's 'complete' event sets sessionStorage, fades the
 *      overlay out, removes it, and resumes scroll.
 *
 * Safety net: an 8 s hard timeout calls finish() regardless, so a missing
 * or broken Lottie file never traps the user.
 *
 * Loaded as `proto-intro` with `proto-init` (Lenis) and
 * `proto-lottie` as deps.
 */
(function () {
  'use strict';

  var intro = document.querySelector('.proto-intro');
  if (!intro) return;

  var html = document.documentElement;

  // Already seen this session (the inline <head> guard added the class) --
  // remove the overlay immediately, no fade.
  if (html.classList.contains('proto-intro-skip')) {
    intro.parentNode && intro.parentNode.removeChild(intro);
    return;
  }

  var finished = false;
  function finish() {
    if (finished) return;
    finished = true;

    try { sessionStorage.setItem('protoIntroShown', 'true'); } catch (e) {}

    intro.classList.add('is-hidden');

    // Notify the rest of the site the moment the intro
    // hands control back. Fired exactly once per fresh session.
    document.dispatchEvent(new CustomEvent('proto:intro-complete', { bubbles: true }));

    window.setTimeout(function () {
      intro.parentNode && intro.parentNode.removeChild(intro);
      html.classList.remove('proto-intro-active');
      if (window.protoLenis && typeof window.protoLenis.start === 'function') {
        window.protoLenis.start();
      }
    }, 600); // matches the CSS opacity transition
  }

  // Lock scroll
  html.classList.add('proto-intro-active');
  if (window.protoLenis && typeof window.protoLenis.stop === 'function') {
    window.protoLenis.stop();
  }

  // Safety net: never block more than 8 s, even if Lottie never reports complete.
  window.setTimeout(finish, 8000);

  var lottieUrl = intro.getAttribute('data-lottie-url');
  var container = intro.querySelector('.proto-intro__lottie');

  if (!lottieUrl || !window.lottie || !container) {
    // No Lottie wired up -- hold for a beat so a hard refresh doesn't
    // feel jarring, then fade.
    window.setTimeout(finish, 500);
    return;
  }

  try {
    var anim = window.lottie.loadAnimation({
      container: container,
      renderer: 'svg',
      loop: false,
      autoplay: true,
      path: lottieUrl,
    });
    anim.addEventListener('complete', finish);
    anim.addEventListener('data_failed', finish);
  } catch (e) {
    finish();
  }
})();
