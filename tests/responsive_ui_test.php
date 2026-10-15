<?php

function responsiveUiAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$root = dirname(__DIR__);
$head = file_get_contents($root . '/include/headhtml.php');
$menu = file_get_contents($root . '/include/menu.php');
$responsiveCss = file_get_contents($root . '/css/mikhmon-responsive.css');
$responsiveJs = file_get_contents($root . '/js/mikhmon-responsive.js');
$status = file_get_contents($root . '/status/index.php');
$customerPortal = file_get_contents($root . '/pelanggan.php');

responsiveUiAssert(strpos($head, 'mikhmon-responsive.css') !== false, 'shared responsive stylesheet is loaded after the theme');
responsiveUiAssert(strpos($head, 'mikhmon-responsive.js') !== false, 'responsive navigation behavior is loaded');
responsiveUiAssert(strpos($head, 'theme-<?=') !== false, 'the active theme is exposed as a body class');
responsiveUiAssert(substr_count($menu, 'aria-controls="sidenav"') === 4, 'both navigation variants expose labelled open and close controls');
responsiveUiAssert(strpos($menu, 'nav-toggle-label') !== false, 'the mobile menu control has a visible text label');
responsiveUiAssert(strpos($responsiveCss, '@media screen and (max-width: 1024px)') !== false, 'tablet and small-laptop layout is defined');
responsiveUiAssert(strpos($responsiveCss, '@media screen and (max-width: 620px)') !== false, 'phone layout is defined');
responsiveUiAssert(strpos($responsiveCss, '--ui-touch-height: 44px') !== false, 'mobile controls use a 44px touch target');
responsiveUiAssert(strpos($responsiveCss, 'overflow-wrap: anywhere') !== false, 'long content cannot escape its container');
responsiveUiAssert(strpos($responsiveCss, 'prefers-reduced-motion: reduce') !== false, 'reduced-motion preferences are respected');
responsiveUiAssert(strpos($responsiveJs, "event.key === 'Escape'") !== false, 'the mobile drawer closes with Escape');
responsiveUiAssert(strpos($responsiveJs, "event.key !== 'Tab'") !== false, 'keyboard focus stays inside the open mobile drawer');
responsiveUiAssert(strpos($responsiveJs, 'restoreNavigationItems') !== false, 'opening the drawer clears stale legacy visibility styles');
responsiveUiAssert(strpos($responsiveJs, "querySelectorAll('.menu, .dropdown-btn')") !== false, 'all permitted top-level navigation items are restored');
responsiveUiAssert(strpos($responsiveCss, 'body.nav-open #sidenav > .menu') !== false, 'open drawer items remain visible despite legacy inline styles');
responsiveUiAssert(strpos($responsiveCss, '#sidenav .dropdown-container > a > i:first-child') !== false, 'submenu icons use one shared alignment column');
responsiveUiAssert(strpos($menu, '&nbsp;&nbsp;&nbsp;') === false, 'submenu indentation does not depend on manual spaces');
responsiveUiAssert(strpos($menu, 'class="dropdown-btn nested-dropdown-btn') !== false, 'the user-profile submenu exposes its nested hierarchy');
responsiveUiAssert(strpos($responsiveCss, 'grid-template-columns: 36px minmax(0, 1fr) 20px') !== false, 'nested submenu icon, label, and caret stay on one row');
responsiveUiAssert(strpos($menu, 'dropdown-container nested-dropdown-container') !== false, 'the user-profile child menu has an explicit second-level container');
responsiveUiAssert(strpos($responsiveCss, '#sidenav .nested-dropdown-container > a') !== false, 'the user-profile child menu uses a consistent second-level indent');
responsiveUiAssert(substr_count($menu, 'aria-controls="mobileToolsPanel"') === 2, 'both navigation variants expose the mobile settings toggle');
responsiveUiAssert(substr_count($menu, 'id="mobileToolsPanel"') === 2, 'both navigation variants group toolbar controls in a mobile settings panel');
responsiveUiAssert(strpos($responsiveCss, '.mobile-tools-panel.is-open') !== false, 'mobile toolbar controls stay hidden until requested');
responsiveUiAssert(strpos($responsiveJs, 'setToolsExpanded') !== false, 'the mobile settings panel has working open and close behavior');
responsiveUiAssert(strpos($status, 'maximum-scale') === false, 'the voucher status page allows browser zoom');
responsiveUiAssert(strpos($status, '../css/mikhmon-responsive.css') !== false, 'the standalone voucher status page uses shared responsive rules');
responsiveUiAssert(strpos($customerPortal, 'css/mikhmon-responsive.css') !== false, 'the customer portal uses shared responsive rules');
responsiveUiAssert(strpos($customerPortal, '.warning{background:#b07a00;color:#0b0c0c}') !== false, 'the customer portal warning action has accessible text contrast');

echo 'responsive-ui-tests: OK' . PHP_EOL;
