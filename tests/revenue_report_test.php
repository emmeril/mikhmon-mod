<?php

date_default_timezone_set('Asia/Jakarta');
$databasePath = tempnam(sys_get_temp_dir(), 'mikhmon-revenue-report-');
putenv('MIKHMON_DATABASE_PATH=' . $databasePath);
require dirname(__DIR__) . '/include/database.php';
require dirname(__DIR__) . '/lib/revenue_report.php';

function revenueReportTestAssert($condition, $message) {
  if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

$session = 'router-a';
$mitraOne = mikhmonSaveUser('', 'Mitra Satu', 'mitra-satu', 'mitra', $session, 'secret');
$mitraTwo = mikhmonSaveUser('', 'Mitra Dua', 'mitra-dua', 'mitra', $session, 'secret');
$biller = mikhmonSaveUser('', 'Biller Satu', 'biller-satu', 'biller', $session, 'secret');
$customerOne = mikhmonSaveCustomer($session, 'customer-one', 'Pelanggan Satu', '', '', 'hotspot', 'bulanan-1', 'bulanan', $mitraOne);
$customerTwo = mikhmonSaveCustomer($session, 'customer-two', 'Pelanggan Dua', '', '', 'hotspot', 'bulanan-2', 'bulanan', $mitraTwo);
$paidAt = strtotime('2026-10-08 10:00:00');

mikhmonSaveInvoice($session, array(
  'id'=>'monthly-one','number'=>'INV-ONE','kind'=>'monthly','customer_id'=>$customerOne,'customer_name'=>'Pelanggan Satu',
  'status'=>'paid','paid_at'=>$paidAt,'subtotal'=>100000,'collection_fee'=>2500,'amount'=>102500,
  'paid_by_user_id'=>$biller,'biller_commission'=>2500,'mitra_user_id'=>$mitraOne,'mitra_commission'=>10000,
  'services'=>array(array('service'=>'hotspot','profile'=>'bulanan-1','amount'=>100000,'hpp'=>60000,'partner_commission'=>10000)),
));
mikhmonSaveInvoice($session, array(
  'id'=>'voucher-portal','number'=>'VCR-ONE','kind'=>'voucher','customer_id'=>$customerOne,'customer_name'=>'Pelanggan Satu',
  'status'=>'paid','paid_at'=>$paidAt,'amount'=>20000,'voucher_profile'=>'voucher','voucher_username'=>'PORTAL-1','payment_gateway'=>'midtrans',
));
mikhmonSaveInvoice($session, array(
  'id'=>'monthly-two','number'=>'INV-TWO','kind'=>'monthly','customer_id'=>$customerTwo,'customer_name'=>'Pelanggan Dua',
  'status'=>'paid','paid_at'=>$paidAt,'amount'=>300000,'payment_gateway'=>'midtrans',
));
mikhmonSaveReportRecords($session, array(
  array('name'=>'oct/08/2026-|-11:00:00-|-MANUAL-1-|-25000-|-10.0.0.1-|-AA-|-1d-|-voucher-|-vc-batch [mitra:'.$mitraOne.']-|-hotspot-|-10000'),
  array('name'=>'oct/08/2026-|-11:05:00-|-PORTAL-1-|-20000-|-10.0.0.2-|-BB-|-1d-|-voucher-|-vc-portal [mitra:'.$mitraOne.']-|-hotspot-|-10000'),
));
$database = mikhmonReadDatabase();
$duplicateInvoice = $database['invoices'][$session][0];
$database['invoices'][$session][] = $duplicateInvoice;
$duplicateInvoice['id'] = 'monthly-one-copy';
$database['invoices'][$session][] = $duplicateInvoice;
$database['report_records'][$session][] = $database['report_records'][$session][0];
revenueReportTestAssert(mikhmonWriteDatabase($database), 'legacy duplicate fixtures are stored');
$profiles = array(array('name'=>'voucher','on-login'=>':put (",remc,10000,1d,25000,,Disable,")'));
$transactions = mikhmonRevenueTransactions($session, $profiles);
revenueReportTestAssert(count($transactions) === 4, 'duplicate invoices, report records, and portal voucher rows are counted once');
revenueReportTestAssert(count(array_unique(array_column($transactions, 'id'))) === count($transactions), 'revenue transaction ids remain unique');

$mitraRows = mikhmonRevenueFilterTransactions($transactions, array('owner_user_id'=>$mitraOne));
$totals = mikhmonRevenueTotals($mitraRows);
revenueReportTestAssert($totals['customer'] === 100000.0 && $totals['voucher'] === 45000.0 && $totals['revenue'] === 145000.0, 'partner revenue combines paid customers and vouchers');
revenueReportTestAssert($totals['direct'] === 20000.0 && $totals['manual'] === 127500.0, 'direct gateway money is separated from cash held by collectors');
revenueReportTestAssert($totals['cost'] === 80000.0 && $totals['partner_income'] === 35000.0 && $totals['biller_income'] === 2500.0 && $totals['net'] === 30000.0, 'revenue totals separate HPP, partner income, biller income, and ISP net income');
$voucherReportRows = mikhmonRevenueVoucherReportRows($mitraRows, array($mitraOne=>'Mitra Satu'));
revenueReportTestAssert(count($voucherReportRows) === 1 && $voucherReportRows[0]['quantity'] === 2, 'portal and manually sold vouchers share one daily profile recap');
revenueReportTestAssert($voucherReportRows[0]['cost'] === 20000.0 && $voucherReportRows[0]['commission'] === 25000.0 && $voucherReportRows[0]['revenue'] === 45000.0, 'voucher report keeps HPP, commission, and selling total separate');

$depositEntry = array('collector_user_id'=>$biller,'amount'=>50000,'date'=>'2026-10-08','method'=>'transfer','reference'=>'TRX-001','request_id'=>'request-001','created_by'=>'Admin');
$depositId = mikhmonSaveRevenueDeposit($session, $depositEntry);
revenueReportTestAssert($depositId !== false, 'admin can record a valid collector deposit');
revenueReportTestAssert(mikhmonSaveRevenueDeposit($session, $depositEntry) === $depositId, 'repeated deposit request returns the original record');
$database = mikhmonReadDatabase();
$database['revenue_deposits'][$session][] = $database['revenue_deposits'][$session][0];
revenueReportTestAssert(mikhmonWriteDatabase($database), 'legacy duplicate deposit fixture is stored');
revenueReportTestAssert(count(mikhmonGetRevenueDeposits($session, $biller)) === 1, 'duplicate deposits are shown and totaled once');
$collector = mikhmonRevenueCollectorSummary($transactions, mikhmonGetRevenueDeposits($session), $biller);
revenueReportTestAssert($collector['manual'] === 102500.0 && $collector['deposited'] === 50000.0 && $collector['outstanding'] === 52500.0, 'biller outstanding balance deducts recorded deposits from gross cash');
revenueReportTestAssert($collector['commission'] === 2500.0 && $collector['commission_open'] === 2500.0, 'biller commission remains separate from deposits');

$summaries = mikhmonRevenuePartnerSummaries($transactions, array($mitraOne=>'Mitra Satu',$mitraTwo=>'Mitra Dua'));
revenueReportTestAssert($summaries[0]['name'] === 'Mitra Dua' && $summaries[0]['total'] === 300000.0, 'admin partner summary orders combined revenue by total');
revenueReportTestAssert(mikhmonSaveRevenueDeposit($session, array('collector_user_id'=>'missing','amount'=>1000,'date'=>'2026-10-08')) === false, 'deposit rejects an unknown collector');

mikhmonSaveInvoice($session, array(
  'id'=>'monthly-legacy','number'=>'INV-LEGACY','kind'=>'monthly','customer_id'=>$customerTwo,'customer_name'=>'Pelanggan Dua',
  'status'=>'paid','paid_at'=>$paidAt,'amount'=>52500,'admin_fee'=>2500,'paid_by_user_id'=>$biller,
));
$legacyRows = mikhmonRevenuePaidInvoiceReportRows(mikhmonRevenueTransactions($session, $profiles), array($mitraOne=>'Mitra Satu',$mitraTwo=>'Mitra Dua'));
$legacyAdmin = 0.0;
foreach ($legacyRows as $row) if ($row['owner_user_id'] === $mitraTwo) $legacyAdmin = $row['admin'];
revenueReportTestAssert($legacyAdmin === 2500.0, 'legacy admin fee is treated once as collection administration');

@unlink($databasePath);
echo 'revenue-report-tests: OK' . PHP_EOL;
