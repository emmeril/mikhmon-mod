<?php

function mikhmonI18nLanguages() {
  return array('en', 'id', 'es', 'tl', 'tr');
}

function mikhmonI18nCatalog($language) {
  static $catalogs = array();
  if (isset($catalogs[$language])) return $catalogs[$language];
  $file = dirname(__DIR__) . '/lang/' . $language . '.php';
  if (!is_file($file)) return array();
  $catalogs[$language] = (static function ($file) {
    include $file;
    $result = array();
    foreach (get_defined_vars() as $key => $value) {
      if (strpos($key, '_') === 0 && is_string($value)) $result[$key] = trim($value);
    }
    return $result;
  })($file);
  return $catalogs[$language];
}

function mikhmonI18nCommonCatalog() {
  return array(
    array('en' => 'Brand Setting', 'id' => 'Pengaturan Merek', 'es' => 'Configuracion de marca', 'tl' => 'Mga Setting ng Brand', 'tr' => 'Marka Ayarlari'),
    array('en' => 'Database Backup', 'id' => 'Cadangan Database', 'es' => 'Copia de seguridad', 'tl' => 'Backup ng Database', 'tr' => 'Veritabani Yedegi'),
    array('en' => 'WhatsApp Gateway', 'id' => 'Gateway WhatsApp', 'es' => 'Pasarela de WhatsApp', 'tl' => 'WhatsApp Gateway', 'tr' => 'WhatsApp Ag Gecidi'),
    array('en' => 'Payment Gateway', 'id' => 'Gateway Pembayaran', 'es' => 'Pasarela de pago', 'tl' => 'Gateway ng Pagbabayad', 'tr' => 'Odeme Ag Gecidi'),
    array('en' => 'User Management', 'id' => 'Manajemen Pengguna', 'es' => 'Gestion de usuarios', 'tl' => 'Pamamahala ng Gumagamit', 'tr' => 'Kullanici Yonetimi'),
    array('en' => 'Customer', 'id' => 'Pelanggan', 'es' => 'Cliente', 'tl' => 'Customer', 'tr' => 'Musteri'),
    array('en' => 'Customers', 'id' => 'Pelanggan', 'es' => 'Clientes', 'tl' => 'Mga Customer', 'tr' => 'Musteriler'),
    array('en' => 'Add Identity', 'id' => 'Tambah Identitas', 'es' => 'Agregar identidad', 'tl' => 'Magdagdag ng Pagkakakilanlan', 'tr' => 'Kimlik Ekle'),
    array('en' => 'Identity List', 'id' => 'Daftar Identitas', 'es' => 'Lista de identidades', 'tl' => 'Listahan ng Pagkakakilanlan', 'tr' => 'Kimlik Listesi'),
    array('en' => 'Edit Identity', 'id' => 'Edit Identitas', 'es' => 'Editar identidad', 'tl' => 'I-edit ang Pagkakakilanlan', 'tr' => 'Kimligi Duzenle'),
    array('en' => 'Add Service', 'id' => 'Tambah Layanan', 'es' => 'Agregar servicio', 'tl' => 'Magdagdag ng Serbisyo', 'tr' => 'Hizmet Ekle'),
    array('en' => 'Customer List', 'id' => 'Daftar Pelanggan', 'es' => 'Lista de clientes', 'tl' => 'Listahan ng Customer', 'tr' => 'Musteri Listesi'),
    array('en' => 'Voucher List', 'id' => 'Daftar Voucher', 'es' => 'Lista de vales', 'tl' => 'Listahan ng Voucher', 'tr' => 'Kupon Listesi'),
    array('en' => 'Number of Vouchers', 'id' => 'Jumlah Voucher', 'es' => 'Cantidad de vales', 'tl' => 'Bilang ng Voucher', 'tr' => 'Kupon Sayisi'),
    array('en' => 'Print Center', 'id' => 'Pusat Cetak', 'es' => 'Centro de impresion', 'tl' => 'Sentro ng Pag-print', 'tr' => 'Yazdirma Merkezi'),
    array('en' => 'System Logs', 'id' => 'Log Sistem', 'es' => 'Registros del sistema', 'tl' => 'Mga Log ng System', 'tr' => 'Sistem Gunlukleri'),
    array('en' => 'Resume Report', 'id' => 'Ringkasan Laporan', 'es' => 'Resumen del informe', 'tl' => 'Buod ng Ulat', 'tr' => 'Rapor Ozeti'),
    array('en' => 'Expired Mode', 'id' => 'Mode Kedaluwarsa', 'es' => 'Modo de vencimiento', 'tl' => 'Paraan ng Pag-expire', 'tr' => 'Sona Erme Modu'),
    array('en' => 'None', 'id' => 'Tidak Ada', 'es' => 'Ninguno', 'tl' => 'Wala', 'tr' => 'Yok'),
    array('en' => 'Remove', 'id' => 'Hapus', 'es' => 'Eliminar', 'tl' => 'Alisin', 'tr' => 'Sil'),
    array('en' => 'Notice', 'id' => 'Notifikasi', 'es' => 'Notificar', 'tl' => 'Abiso', 'tr' => 'Bildirim'),
    array('en' => 'Remove & Record', 'id' => 'Hapus & Catat', 'es' => 'Eliminar y registrar', 'tl' => 'Alisin at Itala', 'tr' => 'Sil ve Kaydet'),
    array('en' => 'Notice & Record', 'id' => 'Notifikasi & Catat', 'es' => 'Notificar y registrar', 'tl' => 'Abisuhan at Itala', 'tr' => 'Bildir ve Kaydet'),
    array('en' => 'Remove user', 'id' => 'Hapus pengguna', 'es' => 'Eliminar usuario', 'tl' => 'Alisin ang gumagamit', 'tr' => 'Kullaniciyi sil'),
    array('en' => 'Disable user', 'id' => 'Nonaktifkan pengguna', 'es' => 'Desactivar usuario', 'tl' => 'I-disable ang gumagamit', 'tr' => 'Kullaniciyi devre disi birak'),
    array('en' => 'Active', 'id' => 'Aktif', 'es' => 'Activo', 'tl' => 'Aktibo', 'tr' => 'Aktif'),
    array('en' => 'Inactive', 'id' => 'Tidak Aktif', 'es' => 'Inactivo', 'tl' => 'Hindi Aktibo', 'tr' => 'Pasif'),
    array('en' => 'Enabled', 'id' => 'Diaktifkan', 'es' => 'Habilitado', 'tl' => 'Naka-enable', 'tr' => 'Etkin'),
    array('en' => 'Disabled', 'id' => 'Dinonaktifkan', 'es' => 'Deshabilitado', 'tl' => 'Naka-disable', 'tr' => 'Devre Disi'),
    array('en' => 'Enable', 'id' => 'Aktifkan', 'es' => 'Habilitar', 'tl' => 'I-enable', 'tr' => 'Etkinlestir'),
    array('en' => 'Disable', 'id' => 'Nonaktifkan', 'es' => 'Deshabilitar', 'tl' => 'I-disable', 'tr' => 'Devre Disi Birak'),
    array('en' => 'Paid', 'id' => 'Lunas', 'es' => 'Pagado', 'tl' => 'Bayad', 'tr' => 'Odendi'),
    array('en' => 'Unpaid', 'id' => 'Belum Dibayar', 'es' => 'No pagado', 'tl' => 'Hindi Pa Bayad', 'tr' => 'Odenmedi'),
    array('en' => 'Reset Filter', 'id' => 'Atur Ulang Filter', 'es' => 'Restablecer filtro', 'tl' => 'I-reset ang Filter', 'tr' => 'Filtreyi Sifirla'),
    array('en' => 'Customer Name', 'id' => 'Nama Pelanggan', 'es' => 'Nombre del cliente', 'tl' => 'Pangalan ng Customer', 'tr' => 'Musteri Adi'),
    array('en' => 'Phone Number', 'id' => 'Nomor HP', 'es' => 'Numero de telefono', 'tl' => 'Numero ng Telepono', 'tr' => 'Telefon Numarasi'),
    array('en' => 'Address', 'id' => 'Alamat', 'es' => 'Direccion', 'tl' => 'Address', 'tr' => 'Adres'),
    array('en' => 'Number of Services', 'id' => 'Jumlah Layanan', 'es' => 'Cantidad de servicios', 'tl' => 'Bilang ng Serbisyo', 'tr' => 'Hizmet Sayisi'),
    array('en' => 'Service', 'id' => 'Layanan', 'es' => 'Servicio', 'tl' => 'Serbisyo', 'tr' => 'Hizmet'),
    array('en' => 'User Status', 'id' => 'Status Pengguna', 'es' => 'Estado del usuario', 'tl' => 'Status ng Gumagamit', 'tr' => 'Kullanici Durumu'),
    array('en' => 'Due Date', 'id' => 'Jatuh Tempo', 'es' => 'Fecha de vencimiento', 'tl' => 'Takdang Petsa', 'tr' => 'Son Odeme Tarihi'),
    array('en' => 'Invoice Status', 'id' => 'Status Invoice', 'es' => 'Estado de factura', 'tl' => 'Status ng Invoice', 'tr' => 'Fatura Durumu'),
    array('en' => 'Next Invoice', 'id' => 'Invoice Berikutnya', 'es' => 'Proxima factura', 'tl' => 'Susunod na Invoice', 'tr' => 'Sonraki Fatura'),
    array('en' => 'Total Bill', 'id' => 'Total Tagihan', 'es' => 'Total de factura', 'tl' => 'Kabuuang Singil', 'tr' => 'Toplam Fatura'),
    array('en' => 'Processed By', 'id' => 'Diproses Oleh', 'es' => 'Procesado por', 'tl' => 'Pinroseso ni', 'tr' => 'Isleyen'),
    array('en' => 'Action', 'id' => 'Aksi', 'es' => 'Accion', 'tl' => 'Aksyon', 'tr' => 'Islem'),
    array('en' => 'Create Invoice', 'id' => 'Buat Invoice', 'es' => 'Crear factura', 'tl' => 'Gumawa ng Invoice', 'tr' => 'Fatura Olustur'),
    array('en' => 'New Invoice', 'id' => 'Invoice Baru', 'es' => 'Nueva factura', 'tl' => 'Bagong Invoice', 'tr' => 'Yeni Fatura'),
    array('en' => 'Next bill', 'id' => 'Tagihan berikutnya', 'es' => 'Siguiente factura', 'tl' => 'Susunod na singil', 'tr' => 'Sonraki fatura'),
    array('en' => 'Send Bill', 'id' => 'Kirim Tagihan', 'es' => 'Enviar factura', 'tl' => 'Ipadala ang Singil', 'tr' => 'Faturayi Gonder'),
    array('en' => 'Send', 'id' => 'Kirim', 'es' => 'Enviar', 'tl' => 'Ipadala', 'tr' => 'Gonder'),
    array('en' => 'Create Payment Link', 'id' => 'Buat Tautan Pembayaran', 'es' => 'Crear enlace de pago', 'tl' => 'Gumawa ng Link sa Pagbabayad', 'tr' => 'Odeme Baglantisi Olustur'),
    array('en' => 'Mark as Paid', 'id' => 'Tandai Lunas', 'es' => 'Marcar como pagado', 'tl' => 'Markahan bilang Bayad', 'tr' => 'Odendi Olarak Isaretle'),
    array('en' => 'Save Settings', 'id' => 'Simpan Pengaturan', 'es' => 'Guardar configuracion', 'tl' => 'I-save ang mga Setting', 'tr' => 'Ayarlari Kaydet'),
    array('en' => 'Gateway Status', 'id' => 'Status Gateway', 'es' => 'Estado de la pasarela', 'tl' => 'Status ng Gateway', 'tr' => 'Ag Gecidi Durumu'),
    array('en' => 'Billing Automation', 'id' => 'Otomatisasi Billing', 'es' => 'Automatizacion de facturacion', 'tl' => 'Billing Automation', 'tr' => 'Faturalama Otomasyonu'),
    array('en' => 'All Routers', 'id' => 'Semua Router', 'es' => 'Todos los routers', 'tl' => 'Lahat ng Router', 'tr' => 'Tum Yonlendiriciler'),
    array('en' => 'Choose profile', 'id' => 'Pilih profil', 'es' => 'Seleccionar perfil', 'tl' => 'Pumili ng profile', 'tr' => 'Profil sec'),
    array('en' => 'Download', 'id' => 'Unduh', 'es' => 'Descargar', 'tl' => 'I-download', 'tr' => 'Indir'),
    array('en' => 'Reload data', 'id' => 'Muat ulang data', 'es' => 'Recargar datos', 'tl' => 'I-reload ang data', 'tr' => 'Verileri yenile'),
    array('en' => 'Expired Users', 'id' => 'Pengguna Kedaluwarsa', 'es' => 'Usuarios vencidos', 'tl' => 'Mga Expired na Gumagamit', 'tr' => 'Suresi Dolan Kullanicilar')
  );
}

function mikhmonI18nMap($target) {
  static $maps = array();
  if (isset($maps[$target])) return $maps[$target];
  if (!in_array($target, mikhmonI18nLanguages(), true)) $target = 'en';
  $map = array();
  foreach (mikhmonI18nLanguages() as $source) {
    $sourceCatalog = mikhmonI18nCatalog($source);
    $targetCatalog = mikhmonI18nCatalog($target);
    foreach ($sourceCatalog as $key => $sourceText) {
      if (!isset($targetCatalog[$key]) || $sourceText === '' || strlen($sourceText) < 3 || strlen($sourceText) > 100) continue;
      if (strpos($sourceText, '<') !== false || preg_match('/[\r\n]/', $sourceText)) continue;
      $map[$sourceText] = $targetCatalog[$key];
    }
  }
  foreach (mikhmonI18nCommonCatalog() as $entry) {
    foreach (mikhmonI18nLanguages() as $source) $map[$entry[$source]] = $entry[$target];
  }
  foreach ($map as $sourceText => $targetText) {
    if (strpos($sourceText, '&') !== false) {
      $map[htmlspecialchars($sourceText, ENT_QUOTES, 'UTF-8')] = htmlspecialchars($targetText, ENT_QUOTES, 'UTF-8');
    }
  }
  uksort($map, static function ($a, $b) { return strlen($b) - strlen($a); });
  return $maps[$target] = $map;
}

function mikhmonTranslateText($output, $target = null) {
  global $langid;
  $target = $target ?: $langid;
  $map = mikhmonI18nMap($target);
  if (!$map || $output === '') return $output;
  $escapedKeys = array_map(static function ($key) {
    return preg_quote($key, '~');
  }, array_keys($map));
  $pattern = '~(?<![\\p{L}\\p{N}_])(?:' . implode('|', $escapedKeys) . ')(?![\\p{L}\\p{N}_])~u';
  // Translate visible text nodes only; changing class names or JavaScript identifiers
  // would break the application.
  $parts = preg_split('~(<script\\b.*?</script\\s*>|<style\\b.*?</style\\s*>|<[^>]+>)~is', $output, -1, PREG_SPLIT_DELIM_CAPTURE);
  if ($parts === false) return $output;
  foreach ($parts as $index => $part) {
    if ($part === '' || $part[0] === '<') continue;
    $parts[$index] = preg_replace_callback($pattern, static function ($match) use ($map) {
      return $map[$match[0]] ?? $match[0];
    }, $part);
  }
  return implode('', $parts);
}

function mikhmonTranslateOutput($output) {
  global $langid;
  return mikhmonTranslateText($output, $langid);
}

function mikhmonStartTranslationBuffer() {
  static $started = false;
  if (!$started) {
    $started = true;
    ob_start('mikhmonTranslateOutput');
  }
}
