<?php
/**
 * Build a small, dependency-free PDF for an invoice.
 * The standard Helvetica font keeps the generated file portable.
 */
function mikhmonInvoicePdfText($value) {
  $value = (string) $value;
  if (function_exists('iconv')) {
    $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
    if ($converted !== false) $value = $converted;
  }
  $value = preg_replace('/[^\x20-\x7E\x80-\xFF]/', '?', $value);
  return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $value);
}

function mikhmonInvoicePdfMoney($amount, $currency) {
  $indo = in_array($currency, array('RP', 'Rp', 'rp', 'IDR', 'idr', 'RP.', 'Rp.', 'rp.', 'IDR.', 'idr.'), true);
  return $currency . ' ' . number_format((float) $amount, $indo ? 0 : 2, $indo ? ',' : '.', $indo ? '.' : ',');
}

function mikhmonInvoicePdfServices($invoice, $customer) {
  if (!empty($invoice['services']) && is_array($invoice['services'])) return $invoice['services'];
  if (!empty($invoice['username'])) return array(array(
    'service' => $invoice['service'] ?? 'hotspot',
    'username' => $invoice['username'],
    'profile' => $invoice['profile'] ?? '',
    'amount' => (float) ($invoice['amount'] ?? 0),
  ));
  if (function_exists('mikhmonCustomerServices')) return mikhmonCustomerServices($customer);
  return array();
}

function mikhmonInvoicePdfDrawText(&$commands, $x, $y, $size, $text, $color = '0 0 0') {
  $commands[] = 'BT';
  $commands[] = '/F1 ' . (float) $size . ' Tf';
  $commands[] = $color . ' rg';
  $commands[] = '1 0 0 1 ' . (float) $x . ' ' . (float) $y . ' Tm';
  $commands[] = '(' . mikhmonInvoicePdfText($text) . ') Tj';
  $commands[] = 'ET';
}

function mikhmonInvoicePdfDrawRect(&$commands, $x, $y, $width, $height, $color, $fill = true) {
  $commands[] = $color . ($fill ? ' rg' : ' RG');
  $commands[] = (float) $x . ' ' . (float) $y . ' ' . (float) $width . ' ' . (float) $height . ' re ' . ($fill ? 'f' : 'S');
}

function mikhmonInvoicePdfTextWidth($text, $fontSize) {
  $text = mikhmonInvoicePdfText($text);
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

function mikhmonInvoicePdfWrapText($text, $maxWidth, $fontSize, $maxLines = 2) {
  $words = preg_split('/\s+/', trim((string) $text));
  $lines = array();
  $line = '';
  foreach ($words as $word) {
    if ($word === '') continue;
    $candidate = $line === '' ? $word : $line . ' ' . $word;
    if (mikhmonInvoicePdfTextWidth($candidate, $fontSize) <= $maxWidth) {
      $line = $candidate;
      continue;
    }
    if ($line !== '') $lines[] = $line;
    $line = $word;
    while (mikhmonInvoicePdfTextWidth($line, $fontSize) > $maxWidth) {
      $part = '';
      for ($index = 0, $length = strlen($line); $index < $length; $index++) {
        if (mikhmonInvoicePdfTextWidth($part . $line[$index], $fontSize) > $maxWidth) break;
        $part .= $line[$index];
      }
      if ($part === '') break;
      $lines[] = $part;
      $line = substr($line, strlen($part));
    }
  }
  if ($line !== '') $lines[] = $line;
  if (count($lines) <= $maxLines) return $lines ?: array('-');
  $lines = array_slice($lines, 0, $maxLines);
  $last = rtrim($lines[$maxLines - 1]);
  while ($last !== '' && mikhmonInvoicePdfTextWidth($last . '...', $fontSize) > $maxWidth) $last = substr($last, 0, -1);
  $lines[$maxLines - 1] = rtrim($last) . '...';
  return $lines;
}

function mikhmonInvoicePdf($invoice, $customer, $currency, $brand) {
  $paymentReceived = ($invoice['status'] ?? '') === 'paid' || !empty($invoice['gateway_payment_received']);
  $number = (string) ($invoice['number'] ?? '-');
  $name = (string) ($customer['name'] ?? ($invoice['customer_name'] ?? '-'));
  $createdAt = !empty($invoice['created_at']) ? date('Y-m-d H:i:s', (int) $invoice['created_at']) : '-';
  $dueDate = (string) ($invoice['due_date'] ?? '-');
  $status = $paymentReceived ? 'LUNAS' : 'BELUM DIBAYAR';
  $total = mikhmonInvoicePdfMoney($invoice['amount'] ?? 0, $currency);
  $paidAt = (int) ($invoice['paid_at'] ?? $invoice['gateway_paid_at'] ?? 0);
  $commands = array();

  // Header and invoice title.
  mikhmonInvoicePdfDrawRect($commands, 40, 760, 515, 70, '0.08 0.22 0.38');
  mikhmonInvoicePdfDrawText($commands, 55, 800, 19, $brand, '1 1 1');
  mikhmonInvoicePdfDrawText($commands, 55, 778, 9, 'Tagihan layanan internet', '0.82 0.9 0.96');
  mikhmonInvoicePdfDrawText($commands, 430, 804, 19, 'INVOICE', '1 1 1');
  mikhmonInvoicePdfDrawText($commands, 430, 781, 9, $number, '0.82 0.9 0.96');

  // Customer and invoice metadata cards.
  mikhmonInvoicePdfDrawRect($commands, 40, 645, 250, 92, '0.95 0.97 0.99');
  mikhmonInvoicePdfDrawRect($commands, 305, 645, 250, 92, '0.95 0.97 0.99');
  mikhmonInvoicePdfDrawText($commands, 52, 720, 9, 'TAGIHAN UNTUK', '0.08 0.22 0.38');
  mikhmonInvoicePdfDrawText($commands, 52, 702, 11, $name);
  mikhmonInvoicePdfDrawText($commands, 52, 686, 8, 'Telepon: ' . ($customer['phone'] ?? '-'));
  $addressLines = mikhmonInvoicePdfWrapText('Alamat: ' . ($customer['address'] ?? '-'), 226, 8, 2);
  foreach ($addressLines as $addressIndex => $addressLine) mikhmonInvoicePdfDrawText($commands, 52, 671 - ($addressIndex * 13), 8, $addressLine);
  mikhmonInvoicePdfDrawText($commands, 317, 720, 9, 'INFORMASI INVOICE', '0.08 0.22 0.38');
  mikhmonInvoicePdfDrawText($commands, 317, 702, 9, 'No. Invoice: ' . $number);
  mikhmonInvoicePdfDrawText($commands, 317, 686, 9, 'Tanggal: ' . $createdAt);
  mikhmonInvoicePdfDrawText($commands, 317, 670, 9, 'Jatuh tempo: ' . $dueDate);

  // Service detail table.
  $tableLeft = 40; $tableTop = 615; $headerHeight = 26; $rowHeight = 25;
  $columns = array(40, 70, 165, 295, 455, 555);
  mikhmonInvoicePdfDrawRect($commands, $tableLeft, $tableTop - $headerHeight, 515, $headerHeight, '0.08 0.22 0.38');
  mikhmonInvoicePdfDrawText($commands, 50, $tableTop - 18, 9, 'NO', '1 1 1');
  mikhmonInvoicePdfDrawText($commands, 80, $tableTop - 18, 9, 'LAYANAN', '1 1 1');
  mikhmonInvoicePdfDrawText($commands, 175, $tableTop - 18, 9, 'USERNAME', '1 1 1');
  mikhmonInvoicePdfDrawText($commands, 305, $tableTop - 18, 9, 'PROFILE', '1 1 1');
  mikhmonInvoicePdfDrawText($commands, 470, $tableTop - 18, 9, 'NOMINAL', '1 1 1');
  $services = mikhmonInvoicePdfServices($invoice, $customer);
  $maxRows = 16;
  foreach ($services as $index => $service) {
    if ($index >= $maxRows) break;
    $rowY = $tableTop - $headerHeight - (($index + 1) * $rowHeight);
    if ($index % 2 === 0) mikhmonInvoicePdfDrawRect($commands, $tableLeft, $rowY, 515, $rowHeight, '0.97 0.98 1');
    mikhmonInvoicePdfDrawText($commands, 52, $rowY + 8, 9, (string) ($index + 1));
    mikhmonInvoicePdfDrawText($commands, 80, $rowY + 8, 9, strtoupper((string) ($service['service'] ?? 'hotspot')));
    mikhmonInvoicePdfDrawText($commands, 175, $rowY + 8, 9, (string) ($service['username'] ?? '-'));
    mikhmonInvoicePdfDrawText($commands, 305, $rowY + 8, 9, (string) ($service['profile'] ?? '-'));
    mikhmonInvoicePdfDrawText($commands, 470, $rowY + 8, 9, mikhmonInvoicePdfMoney($service['amount'] ?? 0, $currency));
  }
  $shownRows = min(count($services), $maxRows);
  if (count($services) > $maxRows) {
    $rowY = $tableTop - $headerHeight - (($maxRows + 1) * $rowHeight);
    mikhmonInvoicePdfDrawText($commands, 80, $rowY + 8, 9, 'Layanan lainnya tidak ditampilkan.');
  }
  $tableRows = $shownRows + (count($services) > $maxRows ? 1 : 0);
  $tableBottom = $tableTop - $headerHeight - ($tableRows * $rowHeight);
  $commands[] = '0.75 0.8 0.86 RG';
  $commands[] = '0.6 w';
  $commands[] = $tableLeft . ' ' . $tableBottom . ' 515 ' . ($tableTop - $tableBottom) . ' re S';
  foreach ($columns as $columnX) $commands[] = $columnX . ' ' . $tableBottom . ' m ' . $columnX . ' ' . $tableTop . ' l S';
  for ($row = 0; $row <= $tableRows; $row++) {
    $lineY = $tableTop - $headerHeight - ($row * $rowHeight);
    $commands[] = $tableLeft . ' ' . $lineY . ' m 555 ' . $lineY . ' l S';
  }
  $summaryY = $tableBottom - 34;
  mikhmonInvoicePdfDrawRect($commands, 350, $summaryY - 60, 205, 60, '0.95 0.97 0.99');
  mikhmonInvoicePdfDrawText($commands, 365, $summaryY - 22, 10, 'TOTAL TAGIHAN: ' . $total, '0.08 0.22 0.38');
  mikhmonInvoicePdfDrawText($commands, 365, $summaryY - 42, 10, 'Status: ' . $status, $paymentReceived ? '0.05 0.45 0.25' : '0.75 0.35 0.05');
  if ($paidAt > 0) mikhmonInvoicePdfDrawText($commands, 52, $summaryY - 20, 9, 'Tanggal Bayar: ' . date('Y-m-d H:i:s', $paidAt));
  if (!empty($invoice['next_due_date'])) mikhmonInvoicePdfDrawText($commands, 52, $summaryY - 38, 9, 'Jatuh Tempo Berikutnya: ' . $invoice['next_due_date']);
  mikhmonInvoicePdfDrawText($commands, 40, 55, 8, 'Terima kasih telah menggunakan layanan kami.', '0.4 0.4 0.4');

  $stream = implode("\n", $commands) . "\n";
  $objects = array(
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
  );
  $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
  $offsets = array(0);
  foreach ($objects as $objectNumber => $object) {
    $offsets[] = strlen($pdf);
    $pdf .= ($objectNumber + 1) . " 0 obj\n" . $object . "\nendobj\n";
  }
  $xref = strlen($pdf);
  $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
  for ($index = 1; $index <= count($objects); $index++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
  $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
  return $pdf;
}
