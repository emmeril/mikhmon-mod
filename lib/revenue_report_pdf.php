<?php

function mikhmonRevenuePdfText($value) {
  $value = (string) $value;
  if (function_exists('iconv')) {
    $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
    if ($converted !== false) $value = $converted;
  }
  $value = preg_replace('/[^\x20-\x7E\x80-\xFF]/', '?', $value);
  return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $value);
}

function mikhmonRevenuePdfMoney($amount, $currency = 'Rp') {
  $indo = in_array(strtolower(rtrim(trim((string) $currency), '.')), array('rp', 'idr'), true);
  return trim((string) $currency) . ' ' . number_format((float) $amount, $indo ? 0 : 2, $indo ? ',' : '.', $indo ? '.' : ',');
}

function mikhmonRevenuePdfTextWidth($text, $fontSize) {
  $text = mikhmonRevenuePdfText($text);
  $units = 0;
  for ($index = 0, $length = strlen($text); $index < $length; $index++) {
    $character = $text[$index];
    if ($character === ' ') $units += 0.278;
    elseif (strpos('ilI.,:;!|\'`', $character) !== false) $units += 0.278;
    elseif (strpos('mwMW@%&', $character) !== false) $units += 0.889;
    elseif (ctype_upper($character)) $units += 0.667;
    else $units += 0.556;
  }
  return $units * (float) $fontSize;
}

function mikhmonRevenuePdfFitText($text, $width, $fontSize) {
  $text = trim((string) $text);
  if (mikhmonRevenuePdfTextWidth($text, $fontSize) <= $width) return $text;
  while ($text !== '' && mikhmonRevenuePdfTextWidth($text . '...', $fontSize) > $width) $text = substr($text, 0, -1);
  return rtrim($text) . '...';
}

function mikhmonRevenuePdfDrawText(&$commands, $x, $top, $size, $text, $bold = false, $color = '0 0 0', $width = 0, $align = 'left') {
  $pageHeight = 595;
  $text = mikhmonRevenuePdfFitText($text, $width > 0 ? $width : 2000, $size);
  $textWidth = mikhmonRevenuePdfTextWidth($text, $size);
  if ($width > 0 && $align === 'right') $x += max(0, $width - $textWidth);
  elseif ($width > 0 && $align === 'center') $x += max(0, ($width - $textWidth) / 2);
  $commands[] = 'BT';
  $commands[] = '/' . ($bold ? 'F2' : 'F1') . ' ' . (float) $size . ' Tf';
  $commands[] = $color . ' rg';
  $commands[] = '1 0 0 1 ' . round((float) $x, 2) . ' ' . round($pageHeight - (float) $top, 2) . ' Tm';
  $commands[] = '(' . mikhmonRevenuePdfText($text) . ') Tj';
  $commands[] = 'ET';
}

function mikhmonRevenuePdfDrawRect(&$commands, $x, $top, $width, $height, $color, $fill = true) {
  $commands[] = $color . ($fill ? ' rg' : ' RG');
  $commands[] = round((float) $x, 2) . ' ' . round(595 - (float) $top - (float) $height, 2) . ' ' . round((float) $width, 2) . ' ' . round((float) $height, 2) . ' re ' . ($fill ? 'f' : 'S');
}

function mikhmonRevenuePdfDrawLine(&$commands, $x1, $top1, $x2, $top2, $color = '0.72 0.76 0.8') {
  $commands[] = $color . ' RG';
  $commands[] = '0.55 w';
  $commands[] = round((float) $x1, 2) . ' ' . round(595 - (float) $top1, 2) . ' m ' . round((float) $x2, 2) . ' ' . round(595 - (float) $top2, 2) . ' l S';
}

function mikhmonRevenuePdfHeader(&$commands, $report, $title, $pageNumber) {
  $brand = strtoupper(trim((string) ($report['brand'] ?? 'MIKHMON')));
  $period = (string) ($report['period_label'] ?? '-');
  $owner = (string) ($report['owner_name'] ?? 'Semua Mitra');
  mikhmonRevenuePdfDrawRect($commands, 0, 0, 842, 8, '0.08 0.22 0.38');
  mikhmonRevenuePdfDrawText($commands, 28, 31, 15, $brand, true, '0.08 0.22 0.38');
  mikhmonRevenuePdfDrawText($commands, 28, 50, 8, 'Router: ' . (string) ($report['session_label'] ?? '-'), false, '0.3 0.34 0.38');
  mikhmonRevenuePdfDrawText($commands, 410, 31, 14, $title, true, '0.08 0.22 0.38', 404, 'right');
  mikhmonRevenuePdfDrawText($commands, 410, 50, 8, 'Periode: ' . $period . ' | Mitra: ' . $owner, false, '0.3 0.34 0.38', 404, 'right');
  mikhmonRevenuePdfDrawLine($commands, 28, 64, 814, 64, '0.08 0.22 0.38');
  mikhmonRevenuePdfDrawText($commands, 28, 579, 7, 'Dicetak: ' . (string) ($report['printed_at'] ?? date('d/m/Y H:i:s')), false, '0.36 0.4 0.44');
  mikhmonRevenuePdfDrawText($commands, 700, 579, 7, 'Halaman ' . (int) $pageNumber, false, '0.36 0.4 0.44', 114, 'right');
}

function mikhmonRevenuePdfTableHeader(&$commands, $top, $columns) {
  $x = 28;
  $height = 24;
  foreach ($columns as $column) {
    mikhmonRevenuePdfDrawRect($commands, $x, $top, $column['width'], $height, '0.08 0.22 0.38');
    mikhmonRevenuePdfDrawText($commands, $x + 5, $top + 16, $column['size'] ?? 7.5, $column['label'], true, '1 1 1', $column['width'] - 10, $column['align'] ?? 'left');
    $x += $column['width'];
  }
  return $top + $height;
}

function mikhmonRevenuePdfTableRow(&$commands, $top, $columns, $values, $shade = false, $bold = false) {
  $x = 28;
  $height = 20;
  if ($shade) mikhmonRevenuePdfDrawRect($commands, 28, $top, 786, $height, '0.95 0.97 0.99');
  foreach ($columns as $index => $column) {
    $value = isset($values[$index]) ? $values[$index] : '';
    mikhmonRevenuePdfDrawText($commands, $x + 5, $top + 14, $column['size'] ?? 7.5, $value, $bold, '0.08 0.1 0.12', $column['width'] - 10, $column['align'] ?? 'left');
    mikhmonRevenuePdfDrawLine($commands, $x, $top + $height, $x + $column['width'], $top + $height);
    $x += $column['width'];
  }
  return $top + $height;
}

function mikhmonRevenuePdfAddSummaryPage(&$pages, $report, $voucherTotals, $invoiceTotals) {
  $commands = array();
  mikhmonRevenuePdfHeader($commands, $report, 'LAPORAN PENDAPATAN', count($pages) + 1);
  mikhmonRevenuePdfDrawText($commands, 28, 94, 12, 'RINGKASAN PENDAPATAN', true, '0.08 0.22 0.38');
  mikhmonRevenuePdfDrawText($commands, 28, 114, 8, 'Laporan ini hanya memuat transaksi voucher terjual dan tagihan yang sudah lunas.', false, '0.3 0.34 0.38');
  $currency = (string) ($report['currency'] ?? 'Rp');
  $columns = array(
    array('label'=>'SUMBER', 'width'=>190), array('label'=>'JUMLAH', 'width'=>80, 'align'=>'right'),
    array('label'=>'NILAI DASAR', 'width'=>160, 'align'=>'right'), array('label'=>'KOMISI', 'width'=>160, 'align'=>'right'),
    array('label'=>'TOTAL DITERIMA', 'width'=>196, 'align'=>'right'),
  );
  $top = mikhmonRevenuePdfTableHeader($commands, 138, $columns);
  $top = mikhmonRevenuePdfTableRow($commands, $top, $columns, array(
    'Voucher', number_format((int) ($voucherTotals['quantity'] ?? 0), 0, ',', '.'),
    mikhmonRevenuePdfMoney($voucherTotals['cost'] ?? 0, $currency), mikhmonRevenuePdfMoney($voucherTotals['commission'] ?? 0, $currency),
    mikhmonRevenuePdfMoney($voucherTotals['revenue'] ?? 0, $currency),
  ));
  $top = mikhmonRevenuePdfTableRow($commands, $top, $columns, array(
    'Tagihan lunas', number_format((int) ($invoiceTotals['quantity'] ?? 0), 0, ',', '.'),
    mikhmonRevenuePdfMoney($invoiceTotals['subtotal'] ?? 0, $currency), mikhmonRevenuePdfMoney($invoiceTotals['commission'] ?? 0, $currency),
    mikhmonRevenuePdfMoney($invoiceTotals['total'] ?? 0, $currency),
  ), true);
  $top = mikhmonRevenuePdfTableRow($commands, $top, $columns, array(
    'TOTAL PENDAPATAN', number_format((int) (($voucherTotals['quantity'] ?? 0) + ($invoiceTotals['quantity'] ?? 0)), 0, ',', '.'),
    mikhmonRevenuePdfMoney(($voucherTotals['cost'] ?? 0) + ($invoiceTotals['subtotal'] ?? 0), $currency),
    mikhmonRevenuePdfMoney(($voucherTotals['commission'] ?? 0) + ($invoiceTotals['commission'] ?? 0), $currency),
    mikhmonRevenuePdfMoney(($voucherTotals['revenue'] ?? 0) + ($invoiceTotals['total'] ?? 0), $currency),
  ), false, true);
  mikhmonRevenuePdfDrawText($commands, 28, $top + 34, 9, 'Keterangan perhitungan', true, '0.08 0.22 0.38');
  mikhmonRevenuePdfDrawText($commands, 28, $top + 53, 8, 'Voucher: Nilai dasar = HPP, Komisi = Harga jual dikurangi HPP.', false, '0.2 0.24 0.28');
  mikhmonRevenuePdfDrawText($commands, 28, $top + 69, 8, 'Tagihan lunas: Total diterima = subtotal ditambah biaya yang tersimpan pada invoice.', false, '0.2 0.24 0.28');
  $pages[] = $commands;
}

function mikhmonRevenuePdfAddVoucherPages(&$pages, $report, $rows, $totals) {
  $showOwner = empty($report['owner_user_id']);
  $currency = (string) ($report['currency'] ?? 'Rp');
  $columns = $showOwner ? array(
    array('label'=>'TANGGAL', 'width'=>72), array('label'=>'MITRA', 'width'=>145), array('label'=>'PROFIL', 'width'=>129),
    array('label'=>'JUMLAH', 'width'=>62, 'align'=>'right'), array('label'=>'HPP', 'width'=>126, 'align'=>'right'),
    array('label'=>'KOMISI', 'width'=>126, 'align'=>'right'), array('label'=>'HARGA', 'width'=>126, 'align'=>'right'),
  ) : array(
    array('label'=>'TANGGAL', 'width'=>90), array('label'=>'PROFIL', 'width'=>192), array('label'=>'JUMLAH', 'width'=>74, 'align'=>'right'),
    array('label'=>'HPP', 'width'=>143, 'align'=>'right'), array('label'=>'KOMISI', 'width'=>143, 'align'=>'right'),
    array('label'=>'HARGA', 'width'=>144, 'align'=>'right'),
  );
  $chunks = $rows ? array_chunk($rows, 20) : array(array());
  foreach ($chunks as $chunkIndex => $chunk) {
    $commands = array();
    mikhmonRevenuePdfHeader($commands, $report, 'REKAP PENJUALAN VOUCHER', count($pages) + 1);
    $top = mikhmonRevenuePdfTableHeader($commands, 86, $columns);
    if (!$chunk) {
      mikhmonRevenuePdfDrawText($commands, 28, $top + 24, 9, 'Tidak ada penjualan voucher pada periode ini.', false, '0.3 0.34 0.38');
    } else foreach ($chunk as $index => $row) {
      $values = $showOwner ? array(
        date('d/m/Y', (int) $row['timestamp']), $row['owner_name'], strtoupper($row['profile']), number_format((int) $row['quantity'], 0, ',', '.'),
        mikhmonRevenuePdfMoney($row['cost'], $currency), mikhmonRevenuePdfMoney($row['commission'], $currency), mikhmonRevenuePdfMoney($row['revenue'], $currency),
      ) : array(
        date('d/m/Y', (int) $row['timestamp']), strtoupper($row['profile']), number_format((int) $row['quantity'], 0, ',', '.'),
        mikhmonRevenuePdfMoney($row['cost'], $currency), mikhmonRevenuePdfMoney($row['commission'], $currency), mikhmonRevenuePdfMoney($row['revenue'], $currency),
      );
      $top = mikhmonRevenuePdfTableRow($commands, $top, $columns, $values, $index % 2 === 1);
    }
    if ($chunkIndex === count($chunks) - 1 && $rows) {
      $values = $showOwner
        ? array('', '', 'TOTAL', number_format((int) $totals['quantity'], 0, ',', '.'), mikhmonRevenuePdfMoney($totals['cost'], $currency), mikhmonRevenuePdfMoney($totals['commission'], $currency), mikhmonRevenuePdfMoney($totals['revenue'], $currency))
        : array('', 'TOTAL', number_format((int) $totals['quantity'], 0, ',', '.'), mikhmonRevenuePdfMoney($totals['cost'], $currency), mikhmonRevenuePdfMoney($totals['commission'], $currency), mikhmonRevenuePdfMoney($totals['revenue'], $currency));
      mikhmonRevenuePdfTableRow($commands, $top, $columns, $values, false, true);
    }
    $pages[] = $commands;
  }
}

function mikhmonRevenuePdfAddInvoicePages(&$pages, $report, $rows, $totals) {
  $showOwner = empty($report['owner_user_id']);
  $currency = (string) ($report['currency'] ?? 'Rp');
  $columns = $showOwner ? array(
    array('label'=>'MITRA', 'width'=>115), array('label'=>'BULAN', 'width'=>65), array('label'=>'TAHUN', 'width'=>42, 'align'=>'right'),
    array('label'=>'JUMLAH', 'width'=>46, 'align'=>'right'), array('label'=>'SUBTOTAL', 'width'=>90, 'align'=>'right'),
    array('label'=>'DISKON', 'width'=>67, 'align'=>'right'), array('label'=>'PPN', 'width'=>57, 'align'=>'right'),
    array('label'=>'ADM', 'width'=>72, 'align'=>'right'), array('label'=>'KODE', 'width'=>62, 'align'=>'right'),
    array('label'=>'TOTAL', 'width'=>90, 'align'=>'right'), array('label'=>'KOMISI', 'width'=>80, 'align'=>'right'),
  ) : array(
    array('label'=>'BULAN', 'width'=>92), array('label'=>'TAHUN', 'width'=>54, 'align'=>'right'), array('label'=>'JUMLAH', 'width'=>55, 'align'=>'right'),
    array('label'=>'SUBTOTAL', 'width'=>100, 'align'=>'right'), array('label'=>'DISKON', 'width'=>78, 'align'=>'right'),
    array('label'=>'PPN', 'width'=>68, 'align'=>'right'), array('label'=>'ADM', 'width'=>84, 'align'=>'right'),
    array('label'=>'KODE UNIK', 'width'=>76, 'align'=>'right'), array('label'=>'TOTAL', 'width'=>96, 'align'=>'right'),
    array('label'=>'KOMISI', 'width'=>83, 'align'=>'right'),
  );
  $chunks = $rows ? array_chunk($rows, 20) : array(array());
  foreach ($chunks as $chunkIndex => $chunk) {
    $commands = array();
    mikhmonRevenuePdfHeader($commands, $report, 'REKAP BULANAN TAGIHAN LUNAS', count($pages) + 1);
    $top = mikhmonRevenuePdfTableHeader($commands, 86, $columns);
    if (!$chunk) {
      mikhmonRevenuePdfDrawText($commands, 28, $top + 24, 9, 'Tidak ada tagihan lunas pada periode ini.', false, '0.3 0.34 0.38');
    } else foreach ($chunk as $index => $row) {
      $financials = array(
        number_format((int) $row['quantity'], 0, ',', '.'), mikhmonRevenuePdfMoney($row['subtotal'], $currency),
        mikhmonRevenuePdfMoney($row['discount'], $currency), mikhmonRevenuePdfMoney($row['tax'], $currency),
        mikhmonRevenuePdfMoney($row['admin'], $currency), mikhmonRevenuePdfMoney($row['unique_code'], $currency),
        mikhmonRevenuePdfMoney($row['total'], $currency), mikhmonRevenuePdfMoney($row['commission'], $currency),
      );
      $values = $showOwner
        ? array_merge(array($row['owner_name'], $row['month'], (string) $row['year']), $financials)
        : array_merge(array($row['month'], (string) $row['year']), $financials);
      $top = mikhmonRevenuePdfTableRow($commands, $top, $columns, $values, $index % 2 === 1);
    }
    if ($chunkIndex === count($chunks) - 1 && $rows) {
      $financials = array(
        number_format((int) $totals['quantity'], 0, ',', '.'), mikhmonRevenuePdfMoney($totals['subtotal'], $currency),
        mikhmonRevenuePdfMoney($totals['discount'], $currency), mikhmonRevenuePdfMoney($totals['tax'], $currency),
        mikhmonRevenuePdfMoney($totals['admin'], $currency), mikhmonRevenuePdfMoney($totals['unique_code'], $currency),
        mikhmonRevenuePdfMoney($totals['total'], $currency), mikhmonRevenuePdfMoney($totals['commission'], $currency),
      );
      $values = $showOwner ? array_merge(array('TOTAL', '', ''), $financials) : array_merge(array('TOTAL', ''), $financials);
      mikhmonRevenuePdfTableRow($commands, $top, $columns, $values, false, true);
    }
    $pages[] = $commands;
  }
}

function mikhmonRevenuePdfBuildDocument($pages) {
  $pageCount = count($pages);
  $pageObjectNumbers = array();
  for ($index = 0; $index < $pageCount; $index++) $pageObjectNumbers[] = 5 + ($index * 2);
  $objects = array(
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [' . implode(' ', array_map(function ($number) { return $number . ' 0 R'; }, $pageObjectNumbers)) . '] /Count ' . $pageCount . ' >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
  );
  foreach ($pages as $index => $commands) {
    $pageNumber = 5 + ($index * 2);
    $contentNumber = $pageNumber + 1;
    $stream = implode("\n", $commands) . "\n";
    $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentNumber . ' 0 R >>';
    $objects[] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . 'endstream';
  }
  $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
  $offsets = array(0);
  foreach ($objects as $objectIndex => $object) {
    $offsets[] = strlen($pdf);
    $pdf .= ($objectIndex + 1) . " 0 obj\n" . $object . "\nendobj\n";
  }
  $xref = strlen($pdf);
  $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
  for ($index = 1; $index <= count($objects); $index++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
  $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
  return $pdf;
}

function mikhmonRevenueReportPdf($report) {
  $voucherRows = (array) ($report['voucher_rows'] ?? array());
  $invoiceRows = (array) ($report['invoice_rows'] ?? array());
  $voucherTotals = mikhmonRevenueReportSectionTotals($voucherRows, array('cost', 'commission', 'revenue'));
  $invoiceTotals = mikhmonRevenueReportSectionTotals($invoiceRows, array('subtotal', 'discount', 'tax', 'admin', 'unique_code', 'total', 'commission'));
  $pages = array();
  mikhmonRevenuePdfAddSummaryPage($pages, $report, $voucherTotals, $invoiceTotals);
  mikhmonRevenuePdfAddVoucherPages($pages, $report, $voucherRows, $voucherTotals);
  mikhmonRevenuePdfAddInvoicePages($pages, $report, $invoiceRows, $invoiceTotals);
  return mikhmonRevenuePdfBuildDocument($pages);
}
