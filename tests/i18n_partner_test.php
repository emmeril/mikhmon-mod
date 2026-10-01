<?php

session_save_path('/tmp');
session_start();
require dirname(__DIR__) . '/include/access.php';
require_once dirname(__DIR__) . '/lib/i18n.php';

function i18nPartnerTestAssert($condition, $message) {
  if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

$expectations = array(
  'en' => array('Partner List', 'Add Partner', 'Search by name, phone, email, or address'),
  'id' => array('Daftar Mitra', 'Tambah Mitra', 'Cari nama, telepon, email, atau alamat'),
  'es' => array('Lista de socios', 'Agregar socio', 'Buscar por nombre, telefono, correo o direccion'),
  'tl' => array('Listahan ng Partner', 'Magdagdag ng Partner', 'Maghanap ayon sa pangalan, telepono, email, o address'),
  'tr' => array('Ortak Listesi', 'Ortak Ekle', 'Ad, telefon, e-posta veya adrese gore ara'),
);

foreach ($expectations as $language => $expected) {
  i18nPartnerTestAssert(mikhmonTranslateText('Partner List', $language) === $expected[0], $language . ' translates the Partner page title');
  i18nPartnerTestAssert(mikhmonTranslateText('Add Partner', $language) === $expected[1], $language . ' translates the Add Partner action');
  i18nPartnerTestAssert(mikhmonTranslateText('Search by name, phone, email, or address', $language) === $expected[2], $language . ' translates the Partner search label');
}

i18nPartnerTestAssert(mikhmonTranslateText('Partners', 'id') === 'Mitra', 'sidebar Partner label translates to Indonesian');
i18nPartnerTestAssert(mikhmonTranslateText('Partners', 'es') === 'Socios', 'sidebar Partner label translates to Spanish');

echo 'i18n-partner-tests: OK' . PHP_EOL;
