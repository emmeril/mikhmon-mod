<?php
$langid = 'en';
$activeLanguageFile = __DIR__ . '/lang-active.php';
if (is_file($activeLanguageFile)) include $activeLanguageFile;
if (!in_array($langid, array('en', 'id', 'es', 'tl', 'tr'), true)) $langid = 'en';
require_once __DIR__ . '/preferences.php';
$userPreferences = mikhmonUserPreferences();
$langid = $userPreferences['language'] ?? $langid;
require_once dirname(__DIR__) . '/lib/i18n.php';
mikhmonStartTranslationBuffer();
