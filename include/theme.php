<?php
$theme = 'dark';
require_once __DIR__ . '/preferences.php';
$theme = mikhmonUserPreferences()['theme'] ?? $theme;
$themecolor = mikhmonThemeColors()[$theme];
