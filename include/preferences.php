<?php

require_once __DIR__ . '/access.php';
require_once dirname(__DIR__) . '/lib/i18n.php';

function mikhmonThemeColors() {
  return array('dark' => '#3a4149', 'light' => '#008BC9', 'blue' => '#008BC9', 'green' => '#4dbd74', 'pink' => '#e83e8c');
}

function mikhmonPreferenceOwner() {
  // Login screens use the built-in administrator preference as their theme.
  if (empty($_SESSION['mikhmon'])) return 'builtin-admin';
  // Customer portal accounts do not have configurable application preferences.
  if (mikhmonIsCustomer()) return '';
  if (!in_array(mikhmonRole(), array('admin', 'mitra', 'biller'), true)) return '';
  if (mikhmonUserId() !== '') return 'user:' . mikhmonUserId();
  return mikhmonIsAdmin() ? 'builtin-admin' : '';
}

function mikhmonPreferencePath() {
  return mikhmonBackupPath() . '.preferences.json';
}

function mikhmonUserPreferences() {
  $owner = mikhmonPreferenceOwner();
  if ($owner === '') return array();
  $path = mikhmonPreferencePath();
  $records = is_file($path) ? json_decode((string) file_get_contents($path), true) : array();
  $record = $records[$owner] ?? array();
  $preferences = array();
  if (in_array($record['language'] ?? null, mikhmonI18nLanguages(), true)) $preferences['language'] = $record['language'];
  if (is_string($record['theme'] ?? null) && isset(mikhmonThemeColors()[$record['theme']])) $preferences['theme'] = $record['theme'];
  return $preferences;
}

function mikhmonSaveUserPreference($name, $value) {
  $owner = mikhmonPreferenceOwner();
  if ($owner === '' || !is_string($value)) return false;
  if ($name === 'language') {
    if (!in_array($value, mikhmonI18nLanguages(), true)) return false;
  } elseif ($name === 'theme') {
    if (!isset(mikhmonThemeColors()[$value])) return false;
  } else return false;
  if (mikhmonIsCustomer()) return false;
  if (!mikhmonRefreshStaffSession()) return false;

  // A dedicated lock and atomic replacement keep simultaneous account updates intact.
  $path = mikhmonPreferencePath();
  $lock = @fopen($path . '.lock', 'c');
  if (!$lock) return false;
  if (!flock($lock, LOCK_EX)) { fclose($lock); return false; }
  try {
    $records = is_file($path) ? json_decode((string) file_get_contents($path), true) : array();
    if (!is_array($records)) return false;
    $records[$owner][$name] = $value;
    $temporary = tempnam(dirname($path), '.preferences-');
    if ($temporary === false) return false;
    $written = file_put_contents($temporary, json_encode($records, JSON_PRETTY_PRINT));
    $saved = $written !== false && rename($temporary, $path);
    if (!$saved) @unlink($temporary);
    return $saved;
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}
