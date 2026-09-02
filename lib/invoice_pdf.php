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

function mikhmonInvoicePdf($invoice, $customer, $currency, $brand) {
  $paymentReceived = ($invoice['status'] ?? '') === 'paid' || !empty($invoice['gateway_payment_received']);
  $lines = array(
    (string) $brand,
    'INVOICE',
    '',
    'No. Invoice: ' . ($invoice['number'] ?? '-'),
    'Nama Pelanggan: ' . ($customer['name'] ?? ($invoice['customer_name'] ?? '-')),
    'Telepon: ' . ($customer['phone'] ?? '-'),
    'Alamat: ' . ($customer['address'] ?? '-'),
    'Status: ' . ($paymentReceived ? 'LUNAS' : 'BELUM DIBAYAR'),
    'Tanggal Invoice: ' . (!empty($invoice['created_at']) ? date('Y-m-d H:i:s', (int) $invoice['created_at']) : '-'),
    'Jatuh Tempo: ' . ($invoice['due_date'] ?? '-'),
    '',
    'DETAIL LAYANAN',
  );
  foreach (mikhmonInvoicePdfServices($invoice, $customer) as $service) {
    $lines[] = strtoupper((string) ($service['service'] ?? 'hotspot')) . ' / ' . (string) ($service['username'] ?? '-') . ' / ' . (string) ($service['profile'] ?? '-');
    $lines[] = '  Nominal: ' . mikhmonInvoicePdfMoney($service['amount'] ?? 0, $currency);
  }
  $lines[] = '';
  $lines[] = 'TOTAL TAGIHAN: ' . mikhmonInvoicePdfMoney($invoice['amount'] ?? 0, $currency);
  $paidAt = (int) ($invoice['paid_at'] ?? $invoice['gateway_paid_at'] ?? 0);
  if ($paidAt > 0) $lines[] = 'Tanggal Bayar: ' . date('Y-m-d H:i:s', $paidAt);
  if (!empty($invoice['next_due_date'])) $lines[] = 'Jatuh Tempo Berikutnya: ' . $invoice['next_due_date'];

  $commands = array('BT', '/F1 20 Tf', '50 790 Td', '(' . mikhmonInvoicePdfText($lines[0]) . ') Tj', '/F1 14 Tf', '0 -30 Td', '(' . mikhmonInvoicePdfText($lines[1]) . ') Tj', '/F1 10 Tf');
  for ($index = 2, $count = count($lines); $index < $count; $index++) {
    $commands[] = '0 -18 Td';
    $commands[] = '(' . mikhmonInvoicePdfText($lines[$index]) . ') Tj';
  }
  $commands[] = 'ET';
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
