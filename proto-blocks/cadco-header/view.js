/**
 * Cadco header menus.
 *
 * Opens the mega / mini panels on hover for pointer users and on click for
 * everyone, keeps aria-expanded honest, and closes on Escape or an outside
 * click.
 *
 * Idempotency matters here. This file is enqueued as `proto-blocks-cadco-header`,
 * and the theme's Taxi integration marks every `proto-blocks-*` script with
 * data-taxi-reload — so it is re-executed on each client-side navigation. The
 * header itself lives outside [data-taxi] and is never swapped, so the same DOM
 * nodes survive. Without a guard, every navigation would stack another set of
 * listeners on them. Each root is therefore flagged once and skipped thereafter.
 */
(function () {
  'use strict';

  var HOVER_CLOSE_DELAY = 120; // ms of grace when travelling trigger -> panel

  function initHeader(root) {
    if (!root || root.dataset.cadcoReady === 'true') {
      return;
    }
    root.dataset.cadcoReady = 'true';

    var triggers = root.querySelectorAll('[data-cadco-trigger]');
    if (!triggers.length) {
      return;
    }

    var openPanel = null;
    var closeTimer = null;

    function panelFor(trigger) {
      var id = trigger.getAttribute('data-cadco-trigger');
      return id ? root.querySelector('#' + CSS.escape(id)) : null;
    }

    function close(panel) {
      if (!panel) return;
      panel.classList.add('hidden');
      var owner = root.querySelector('[data-cadco-trigger="' + panel.id + '"]');
      if (owner) owner.setAttribute('aria-expanded', 'false');
      if (openPanel === panel) openPanel = null;
    }

    function closeAll() {
      root.querySelectorAll('[data-cadco-panel]').forEach(close);
    }

    function open(trigger) {
      var panel = panelFor(trigger);
      if (!panel) return;

      if (openPanel && openPanel !== panel) {
        close(openPanel);
      }

      panel.classList.remove('hidden');
      trigger.setAttribute('aria-expanded', 'true');
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

    triggers.forEach(function (trigger) {
      var panel = panelFor(trigger);
      if (!panel) return;

      // Pointer: open on hover, with a short grace period so the cursor can
      // travel from the trigger down into the panel without it closing.
      trigger.addEventListener('mouseenter', function () {
        cancelClose();
        open(trigger);
      });
      trigger.parentElement.addEventListener('mouseleave', scheduleClose);
      panel.addEventListener('mouseenter', cancelClose);
      panel.addEventListener('mouseleave', scheduleClose);

      // Click toggles, which also covers touch and keyboard activation. The
      // trigger is a real link, so a modified click (new tab) is left alone.
      trigger.addEventListener('click', function (event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
          return;
        }
        event.preventDefault();
        cancelClose();
        if (openPanel === panel) {
          close(panel);
        } else {
          open(trigger);
        }
      });

      // Keyboard: focusing out of the whole item closes it.
      trigger.addEventListener('focus', function () {
        cancelClose();
        open(trigger);
      });
    });

    // ---- Mobile drawer -------------------------------------------------
    var menuToggle = root.querySelector('[data-cadco-menu-toggle]');

    function closeMenu() {
      root.classList.remove('is-menu-open');
      if (menuToggle) menuToggle.setAttribute('aria-expanded', 'false');
    }

    if (menuToggle) {
      menuToggle.addEventListener('click', function () {
        var isOpen = root.classList.toggle('is-menu-open');
        menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });
    }

    // ---- Search drawer -------------------------------------------------
    var searchToggle = root.querySelector('[data-cadco-search-toggle]');
    var searchPanel = root.querySelector('[data-cadco-search]');

    function searchIsOpen() {
      return !!searchPanel && searchPanel.classList.contains('is-open');
    }

    function closeSearch() {
      if (!searchPanel) return;
      searchPanel.classList.remove('is-open');
      if (searchToggle) searchToggle.setAttribute('aria-expanded', 'false');
    }

    function openSearch() {
      if (!searchPanel) return;
      closeAll(); // a menu panel and the search drawer would otherwise overlap

      searchPanel.classList.add('is-open');
      if (searchToggle) searchToggle.setAttribute('aria-expanded', 'true');

      var field = searchPanel.querySelector('input[type="search"]');
      if (field) {
        // Wait a frame: the drawer is visibility:hidden until the class lands,
        // and focusing a hidden field is a no-op.
        window.requestAnimationFrame(function () { field.focus(); });
      }
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

    // Opening a menu panel should dismiss the search drawer.
    triggers.forEach(function (trigger) {
      trigger.addEventListener('mouseenter', closeSearch);
      trigger.addEventListener('click', closeSearch);
    });

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
      }
    });

    document.addEventListener('click', function (event) {
      if (root.contains(event.target)) return;
      if (openPanel) closeAll();
      if (searchIsOpen()) closeSearch();
      closeMenu();
    });

    // Below md the panels are inline in the drawer, so hover-open would fight
    // the tap-to-expand behaviour. Only wire hover when a fine pointer exists.
    var finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
    if (!finePointer.matches) {
      triggers.forEach(function (trigger) {
        trigger.addEventListener('mouseenter', function (e) { e.stopPropagation(); }, true);
      });
    }
  }

  function initAll() {
    document.querySelectorAll('[data-cadco-header]').forEach(initHeader);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  // The theme dispatches this on first load and after every Taxi navigation.
  // Harmless here because initHeader() no-ops on an already-initialised root,
  // but it means a header rendered inside swapped content would still bind.
  document.addEventListener('proto:page-ready', initAll);
})();
