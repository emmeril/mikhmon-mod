<?php

session_save_path('/tmp');
session_start();
require dirname(__DIR__) . '/include/access.php';
require_once dirname(__DIR__) . '/lib/i18n.php';

function i18nPartnerTestAssert($condition, $message) {
  if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

$_SESSION['mikhmon'] = 'admin';
$_SESSION['mikhmon_role'] = 'admin';
$_SESSION['mikhmon_user_id'] = '';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array();
$session = 'router-a';
$currency = 'Rp';
$expectations = array(
  'en' => array('Partner List', 'Add Partner', 'Search by name, phone, email, or address'),
  'id' => array('Daftar Mitra', 'Tambah Mitra', 'Cari nama, telepon, email, atau alamat'),
  'es' => array('Lista de socios', 'Agregar socio', 'Buscar por nombre, telefono, correo o direccion'),
  'tl' => array('Listahan ng Partner', 'Magdagdag ng Partner', 'Maghanap ayon sa pangalan, telepono, email, o address'),
  'tr' => array('Ortak Listesi', 'Ortak Ekle', 'Ad, telefon, e-posta veya adrese gore ara'),
);

foreach ($expectations as $language => $expected) {
  $langid = $language;
  ob_start();
  include dirname(__DIR__) . '/settings/mitras.php';
  $html = mikhmonTranslateText(ob_get_clean(), $language);
  i18nPartnerTestAssert(strpos($html, $expected[0]) !== false, $language . ' translates the Partner page title');
  i18nPartnerTestAssert(strpos($html, $expected[1]) !== false, $language . ' translates the Add Partner action and modal title');
  i18nPartnerTestAssert(strpos($html, 'placeholder="' . htmlspecialchars($expected[2], ENT_QUOTES) . '"') !== false, $language . ' translates the search placeholder attribute');
}

i18nPartnerTestAssert(mikhmonTranslateText('Partners', 'id') === 'Mitra', 'sidebar Partner label translates to Indonesian');
i18nPartnerTestAssert(mikhmonTranslateText('Partners', 'es') === 'Socios', 'sidebar Partner label translates to Spanish');

echo 'i18n-partner-tests: OK' . PHP_EOL;
