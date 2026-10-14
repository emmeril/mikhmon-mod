<?php

date_default_timezone_set('Asia/Jakarta');
require dirname(__DIR__) . '/include/database.php';
require dirname(__DIR__) . '/lib/revenue_report.php';
require dirname(__DIR__) . '/lib/revenue_report_pdf.php';

function revenueReportPdfTestAssert($condition, $message) {
  if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

$transactions = array(
  array(
    'date'=>strtotime('2026-08-02 10:00:00'), 'source'=>'voucher', 'owner_user_id'=>'mitra-elis',
    'profile'=>'BULANAN', 'revenue'=>70000, 'cost'=>60000, 'voucher_commission'=>10000,
  ),
  array(
    'date'=>strtotime('2026-08-02 11:00:00'), 'source'=>'voucher', 'owner_user_id'=>'mitra-elis',
    'profile'=>'BULANAN', 'revenue'=>70000, 'cost'=>60000, 'voucher_commission'=>10000,
  ),
  array(
    'date'=>strtotime('2026-08-20 09:00:00'), 'source'=>'customer', 'owner_user_id'=>'mitra-elis',
    'revenue'=>100000, 'gross'=>102500, 'discount'=>0, 'tax'=>0, 'admin_fee'=>0,
    'collection_fee'=>2500, 'unique_code'=>0, 'commission'=>2500, 'partner_commission'=>2500, 'cost'=>60000,
  ),
);
$owners = array('mitra-elis'=>'ELIS');
$voucherRows = mikhmonRevenueVoucherReportRows($transactions, $owners);
$invoiceRows = mikhmonRevenuePaidInvoiceReportRows($transactions, $owners);

revenueReportPdfTestAssert(count($voucherRows) === 1, 'same-day voucher sales are grouped by profile and partner');
revenueReportPdfTestAssert($voucherRows[0]['quantity'] === 2 && $voucherRows[0]['cost'] === 120000.0, 'voucher quantity and HPP are totaled');
revenueReportPdfTestAssert($voucherRows[0]['commission'] === 20000.0 && $voucherRows[0]['revenue'] === 140000.0, 'voucher commission and selling price match the reference formula');
revenueReportPdfTestAssert(count($invoiceRows) === 1 && $invoiceRows[0]['month'] === 'AGUSTUS', 'paid invoices are grouped by Indonesian month');
revenueReportPdfTestAssert($invoiceRows[0]['subtotal'] === 100000.0 && $invoiceRows[0]['admin'] === 2500.0, 'invoice subtotal and collection administration are separated');
revenueReportPdfTestAssert($invoiceRows[0]['total'] === 102500.0 && $invoiceRows[0]['commission'] === 2500.0, 'invoice total and commission remain explicit');
revenueReportPdfTestAssert($invoiceRows[0]['cost'] === 60000.0 && $invoiceRows[0]['biller_commission'] === 2500.0 && $invoiceRows[0]['net_income'] === 37500.0, 'invoice recap separates HPP, partner income, biller income, and ISP net income');

$pdf = mikhmonRevenueReportPdf(array(
  'brand'=>'Emmeril Hotspot', 'session_label'=>'Router A', 'currency'=>'Rp',
  'owner_user_id'=>'mitra-elis', 'owner_name'=>'ELIS', 'period_label'=>'01/08/2026 - 31/08/2026',
  'printed_at'=>'04/09/2026 00:04:45', 'voucher_rows'=>$voucherRows, 'invoice_rows'=>$invoiceRows,
));
revenueReportPdfTestAssert(substr($pdf, 0, 8) === '%PDF-1.4', 'report output has a PDF header');
revenueReportPdfTestAssert(strpos($pdf, 'LAPORAN PENDAPATAN') !== false, 'PDF contains the report summary');
revenueReportPdfTestAssert(strpos($pdf, 'KOMISI MITRA') !== false && strpos($pdf, 'KOMISI BILLER') !== false && strpos($pdf, 'BERSIH ISP') !== false, 'PDF labels partner, biller, and ISP earnings explicitly');
revenueReportPdfTestAssert(strpos($pdf, 'REKAP PENJUALAN VOUCHER') !== false, 'PDF contains the voucher recap');
revenueReportPdfTestAssert(strpos($pdf, 'REKAP BULANAN TAGIHAN LUNAS') !== false, 'PDF contains the paid invoice recap');
revenueReportPdfTestAssert(strpos($pdf, '/Count 3') !== false, 'summary, voucher, and invoice sections are separate pages');

echo 'revenue-report-pdf-tests: OK' . PHP_EOL;
