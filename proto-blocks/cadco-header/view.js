/**
 * Cadco header menus.
 *
 * Animation is GSAP, not CSS transitions. The theme already ships GSAP, and
 * tweening from JS gives the menus eases, timelines and stagger that a CSS
 * transition cannot express. CSS only declares the closed resting state; every
 * animated property is an inline style written by GSAP, so nothing competes for
 * the same property mid-tween.
 *
 * Idempotency matters here. This file is enqueued as `proto-blocks-cadco-header`,
 * and the theme's Taxi integration marks every `proto-blocks-*` script with
 * data-taxi-reload — so it is re-executed on each client-side navigation. The
 * header itself lives outside [data-taxi] and is never swapped, so the same DOM
 * nodes survive. Without a guard, every navigation would stack another set of
 * listeners on them. Each root is flagged once and skipped thereafter.
 */
(function () {
  'use strict';

  var HOVER_CLOSE_DELAY = 120; // ms of grace when travelling trigger -> panel
  var MOBILE = '(max-width: 1023px)';

  /** GSAP is a theme-level global and may not have loaded; degrade to instant. */
  function gsapOrNull() {
    return window.gsap || null;
  }

  function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function initHeader(root) {
    if (!root || root.dataset.cadcoReady === 'true') {
      return;
    }
    root.dataset.cadcoReady = 'true';

    var triggers = root.querySelectorAll('[data-cadco-trigger]');
    var isMobile = window.matchMedia(MOBILE);
    var openPanel = null;
    var closeTimer = null;

    // ---- Panels (mega / mini) -------------------------------------------

    function panelFor(trigger) {
      var id = trigger.getAttribute('data-cadco-trigger');
      return id ? root.querySelector('#' + CSS.escape(id)) : null;
    }

    function setExpanded(panel, expanded) {
      var owner = root.querySelector('[data-cadco-trigger="' + panel.id + '"]');
      if (owner) owner.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    function showPanel(panel) {
      var gsap = gsapOrNull();
      panel.classList.add('is-open');
      setExpanded(panel, true);

      if (!gsap) {
        panel.style.display = 'block';
        return;
      }

      gsap.killTweensOf(panel);
      gsap.set(panel, { display: 'block' });

      if (prefersReducedMotion()) {
        gsap.set(panel, { opacity: 1, y: 0 });
        return;
      }

      gsap.fromTo(
        panel,
        { opacity: 0, y: -10 },
        { opacity: 1, y: 0, duration: 0.28, ease: 'power2.out' }
      );

      // Stagger the columns/cards in behind the panel itself. Per-child timing
      // like this is the reason for moving off CSS transitions — a single
      // transition on the container cannot express it.
      var items = panel.querySelectorAll('[data-proto-repeater-item]');
      if (items.length) {
        gsap.fromTo(
          items,
          { opacity: 0, y: 8 },
          { opacity: 1, y: 0, duration: 0.3, ease: 'power2.out', stagger: 0.045, delay: 0.05 }
        );
      }
    }

    function hidePanel(panel) {
      var gsap = gsapOrNull();
      panel.classList.remove('is-open');
      setExpanded(panel, false);

      if (!gsap) {
        panel.style.display = 'none';
        return;
      }

      gsap.killTweensOf(panel);

      if (prefersReducedMotion()) {
        gsap.set(panel, { display: 'none', opacity: 0 });
        return;
      }

      gsap.to(panel, {
        opacity: 0,
        y: -10,
        duration: 0.18,
        ease: 'power2.in',
        onComplete: function () {
          gsap.set(panel, { display: 'none' });
        },
      });
    }

    function close(panel) {
      if (!panel) return;
      hidePanel(panel);
      if (openPanel === panel) openPanel = null;
    }

    function closeAll() {
      root.querySelectorAll('[data-cadco-panel]').forEach(function (panel) {
        if (panel.classList.contains('is-open')) close(panel);
      });
      openPanel = null;
    }

    function open(trigger) {
      var panel = panelFor(trigger);
      if (!panel) return;
      if (openPanel && openPanel !== panel) close(openPanel);
      showPanel(panel);
      openPanel = panel;
    }

    function cancelClose() {
      if (closeTimer) {
        window.clearTimeout(closeTimer);
        closeTimer = null;
      }
    }

    function scheduleClose() {
      cancelClose();
      closeTimer = window.setTimeout(closeAll, HOVER_CLOSE_DELAY);
    }

    // ---- Search drawer ---------------------------------------------------

    var searchToggle = root.querySelector('[data-cadco-search-toggle]');
    var searchPanel = root.querySelector('[data-cadco-search]');

    function searchIsOpen() {
      return !!searchPanel && searchPanel.classList.contains('is-open');
    }

    function closeSearch() {
      if (!searchPanel || !searchIsOpen()) return;
      var gsap = gsapOrNull();

      searchPanel.classList.remove('is-open');
      if (searchToggle) searchToggle.setAttribute('aria-expanded', 'false');

      if (!gsap) {
        searchPanel.style.height = '0px';
        return;
      }

      gsap.killTweensOf(searchPanel);
      gsap.to(searchPanel, {
        height: 0,
        duration: prefersReducedMotion() ? 0 : 0.24,
        ease: 'power2.inOut',
      });
    }

    function openSearch() {
      if (!searchPanel) return;
      var gsap = gsapOrNull();

      closeAll(); // a panel and the drawer would otherwise overlap
      searchPanel.classList.add('is-open');
      if (searchToggle) searchToggle.setAttribute('aria-expanded', 'true');

      if (!gsap) {
        searchPanel.style.height = 'auto';
      } else {
        gsap.killTweensOf(searchPanel);
        // height:'auto' lets GSAP measure the natural height and tween to it,
        // so nothing is clipped and no pixel value has to be guessed.
        gsap.to(searchPanel, {
          height: 'auto',
          duration: prefersReducedMotion() ? 0 : 0.24,
          ease: 'power2.out',
        });
      }

      var field = searchPanel.querySelector('input[type="search"]');
      if (field) window.requestAnimationFrame(function () { field.focus(); });
    }

    if (searchToggle && searchPanel) {
      searchToggle.addEventListener('click', function () {
        if (searchIsOpen()) {
          closeSearch();
        } else {
          openSearch();
        }
      });
    }

    // ---- Trigger wiring --------------------------------------------------

    triggers.forEach(function (trigger) {
      var panel = panelFor(trigger);
      if (!panel) return;

      // Hover is a desktop affordance only. Below the breakpoint the panels sit
      // inline in the drawer, where hover-open would fight tap-to-expand.
      trigger.addEventListener('mouseenter', function () {
        if (isMobile.matches) return;
        cancelClose();
        closeSearch();
        open(trigger);
      });
      trigger.parentElement.addEventListener('mouseleave', function () {
        if (isMobile.matches) return;
        scheduleClose();
      });
      panel.addEventListener('mouseenter', function () {
        if (isMobile.matches) return;
        cancelClose();
      });
      panel.addEventListener('mouseleave', function () {
        if (isMobile.matches) return;
        scheduleClose();
      });

      // Click toggles, covering touch and keyboard activation. A modified click
      // (open in new tab) is left to the browser.
      trigger.addEventListener('click', function (event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        cancelClose();
        closeSearch();
        if (openPanel === panel) {
          close(panel);
        } else {
          open(trigger);
        }
      });

      trigger.addEventListener('focus', function () {
        if (isMobile.matches) return;
        cancelClose();
        open(trigger);
      });
    });

    // ---- Mobile drawer ---------------------------------------------------

    var menuToggle = root.querySelector('[data-cadco-menu-toggle]');
    var nav = root.querySelector('[data-cadco-nav]');
    var cta = root.querySelector('[data-proto-field="cta"]');
    var ctaHome = cta ? cta.parentElement : null;

    function closeMenu() {
      if (!root.classList.contains('is-menu-open')) return;
      root.classList.remove('is-menu-open');
      if (menuToggle) menuToggle.setAttribute('aria-expanded', 'false');
    }

    /**
     * Move the call to action into the drawer on mobile and back to the bar on
     * desktop. The same node is relocated rather than a second one rendered:
     * two elements carrying data-proto-field="cta" would give the editor two
     * bindings for a single field.
     */
    function placeCta() {
      if (!cta || !nav || !ctaHome) return;

      if (isMobile.matches) {
        if (cta.parentElement !== nav) {
          nav.appendChild(cta);
          cta.classList.add('cadco-cta--in-drawer');
        }
      } else if (cta.parentElement !== ctaHome) {
        ctaHome.appendChild(cta);
        cta.classList.remove('cadco-cta--in-drawer');
      }
    }

    placeCta();
    isMobile.addEventListener('change', function () {
      placeCta();
      closeMenu();
      closeAll();
      closeSearch();
    });

    if (menuToggle && nav) {
      menuToggle.addEventListener('click', function () {
        var willOpen = !root.classList.contains('is-menu-open');
        root.classList.toggle('is-menu-open', willOpen);
        menuToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

        if (!willOpen) {
          closeAll();
          return;
        }

        var gsap = gsapOrNull();
        if (!gsap || prefersReducedMotion()) return;

        gsap.fromTo(nav, { opacity: 0, y: -8 }, { opacity: 1, y: 0, duration: 0.24, ease: 'power2.out' });
        gsap.fromTo(
          nav.querySelectorAll('li'),
          { opacity: 0, x: -12 },
          { opacity: 1, x: 0, duration: 0.28, ease: 'power2.out', stagger: 0.05, delay: 0.04 }
        );
      });
    }

    // ---- Dismissal -------------------------------------------------------

    root.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;

      if (searchIsOpen()) {
        closeSearch();
        if (searchToggle) searchToggle.focus();
        return;
      }

      if (openPanel) {
        var owner = root.querySelector('[data-cadco-trigger="' + openPanel.id + '"]');
        closeAll();
        if (owner) owner.focus();
        return;
      }

      closeMenu();
    });

    document.addEventListener('click', function (event) {
      if (root.contains(event.target)) return;
      closeAll();
      closeSearch();
      closeMenu();
    });
  }

  function initAll() {
    document.querySelectorAll('[data-cadco-header]').forEach(initHeader);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  // Dispatched by the theme on first load and after every Taxi navigation.
  document.addEventListener('proto:page-ready', initAll);
})();
