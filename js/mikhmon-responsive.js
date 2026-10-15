(function () {
  'use strict';

  var compactBreakpoint = 1024;

  function ready(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
    } else {
      callback();
    }
  }

  ready(function () {
    var body = document.body;
    var navbar = document.getElementById('navbar');
    var sidenav = document.getElementById('sidenav');
    var main = document.getElementById('main');
    var openButton = document.getElementById('openNav');
    var closeButton = document.getElementById('closeNav');
    var tools = null;
    if (navbar) {
      for (var childIndex = 0; childIndex < navbar.children.length; childIndex += 1) {
        if (navbar.children[childIndex].classList.contains('navbar-right')) {
          tools = navbar.children[childIndex];
          break;
        }
      }
    }
    var toolsToggle = tools ? tools.querySelector('.mobile-tools-toggle') : null;
    var toolsPanel = tools ? tools.querySelector('.mobile-tools-panel') : null;

    if (!body || !navbar || !sidenav || !main || !openButton || !closeButton) return;

    body.onresize = null;

    var toolsMarker = document.createComment('navbar tools');
    if (tools) tools.parentNode.insertBefore(toolsMarker, tools);

    var backdrop = document.createElement('button');
    backdrop.type = 'button';
    backdrop.className = 'nav-backdrop';
    backdrop.setAttribute('aria-label', 'Tutup menu');
    main.parentNode.insertBefore(backdrop, main);

    var wasCompact = null;

    function isCompact() {
      return window.innerWidth <= compactBreakpoint;
    }

    function syncControls() {
      var expanded = body.classList.contains('nav-open');
      openButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      closeButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      sidenav.setAttribute('aria-hidden', isCompact() && !expanded ? 'true' : 'false');
    }

    function setToolsExpanded(expanded) {
      if (!toolsToggle || !toolsPanel) return;
      toolsToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      toolsPanel.classList.toggle('is-open', expanded);
    }

    function moveTools(compact) {
      if (!tools) return;
      setToolsExpanded(false);
      if (compact) {
        tools.classList.add('nav-drawer-tools');
        sidenav.insertBefore(tools, sidenav.firstChild);
      } else {
        tools.classList.remove('nav-drawer-tools');
        toolsMarker.parentNode.insertBefore(tools, toolsMarker.nextSibling);
      }
    }

    function restoreNavigationItems() {
      var navigationItems = sidenav.querySelectorAll('.menu, .dropdown-btn');
      var activeSubmenus = sidenav.querySelectorAll('.dropdown-container.menu-open');
      var itemIndex;

      for (itemIndex = 0; itemIndex < navigationItems.length; itemIndex += 1) {
        navigationItems[itemIndex].style.removeProperty('display');
      }

      for (itemIndex = 0; itemIndex < activeSubmenus.length; itemIndex += 1) {
        activeSubmenus[itemIndex].style.removeProperty('display');
      }
    }

    function openMenu(focusDrawer) {
      restoreNavigationItems();
      body.classList.add('nav-open');
      syncControls();
      if (focusDrawer && isCompact()) {
        var firstControl = sidenav.querySelector('a[href], button:not([disabled]), select:not([disabled])');
        if (firstControl) firstControl.focus();
      }
    }

    function closeMenu(returnFocus) {
      setToolsExpanded(false);
      body.classList.remove('nav-open');
      syncControls();
      if (returnFocus) openButton.focus();
    }

    function applyLayout(force) {
      var compact = isCompact();
      if (!force && compact === wasCompact) return;
      wasCompact = compact;
      moveTools(compact);
      if (compact) closeMenu(false);
      else openMenu(false);
    }

    function handleToggle(event, shouldOpen) {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (shouldOpen) openMenu(false);
      else closeMenu(true);
    }

    openButton.addEventListener('click', function (event) {
      handleToggle(event, true);
    }, true);

    closeButton.addEventListener('click', function (event) {
      handleToggle(event, false);
    }, true);

    if (toolsToggle) {
      toolsToggle.addEventListener('click', function () {
        setToolsExpanded(toolsToggle.getAttribute('aria-expanded') !== 'true');
      });
    }

    backdrop.addEventListener('click', function () {
      closeMenu(true);
    });

    sidenav.addEventListener('click', function (event) {
      if (isCompact() && event.target.closest('a[href]')) closeMenu(false);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && isCompact() && toolsPanel && toolsPanel.classList.contains('is-open')) {
        event.preventDefault();
        setToolsExpanded(false);
        toolsToggle.focus();
        return;
      }

      if (event.key === 'Escape' && isCompact() && body.classList.contains('nav-open')) {
        event.preventDefault();
        closeMenu(true);
        return;
      }

      if (event.key !== 'Tab' || !isCompact() || !body.classList.contains('nav-open')) return;
      var focusable = Array.prototype.filter.call(
        sidenav.querySelectorAll('a[href], button:not([disabled]), select:not([disabled]), input:not([disabled]), [tabindex="0"]'),
        function (element) { return element.offsetParent !== null; }
      );
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    var resizeTimer;
    window.addEventListener('resize', function () {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(function () { applyLayout(false); }, 80);
    });

    applyLayout(true);
  });
}());
