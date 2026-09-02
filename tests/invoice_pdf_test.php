<?php

require dirname(__DIR__) . '/lib/invoice_pdf.php';

function invoicePdfTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$invoice = array(
  'number' => 'INV-20260902-TEST',
  'status' => 'paid',
  'amount' => 3000,
  'created_at' => strtotime('2026-09-01 20:00:00'),
  'paid_at' => strtotime('2026-09-01 20:24:41'),
  'due_date' => '2026-09-05 00:00:00',
  'services' => array(array('service' => 'hotspot', 'username' => 'apri', 'profile' => 'Paket 3K', 'amount' => 3000)),
);
$pdf = mikhmonInvoicePdf($invoice, array('name' => 'Apri', 'phone' => '08123456789'), 'Rp', 'Emmeril Hotspot');

invoicePdfTestAssert(substr($pdf, 0, 8) === '%PDF-1.4', 'output has a PDF header');
invoicePdfTestAssert(substr($pdf, -6) === "%%EOF\n", 'output has a PDF trailer');
invoicePdfTestAssert(strpos($pdf, 'INV-20260902-TEST') !== false, 'invoice number is rendered');
invoicePdfTestAssert(strpos($pdf, 'TOTAL TAGIHAN: Rp 3.000') !== false, 'formatted total is rendered');
invoicePdfTestAssert(strpos($pdf, 'Status: LUNAS') !== false, 'paid status is rendered');
invoicePdfTestAssert(preg_match('/xref\n0 6\n(?:\d{10} \d{5} [fn] \n){6}/', $pdf) === 1, 'cross-reference table is valid');

echo 'invoice-pdf-tests: OK' . PHP_EOL;
