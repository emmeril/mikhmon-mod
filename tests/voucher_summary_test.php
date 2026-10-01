<?php

date_default_timezone_set('Asia/Jakarta');
require dirname(__DIR__) . '/lib/voucher_summary.php';

function voucherSummaryTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$profiles = array(
  array('name' => 'remove-record', 'on-login' => ':put (",remc,1000,1d,1500,,Disable,")'),
  array('name' => 'notice', 'on-login' => ':put (",ntf,1000,1d,1500,,Disable,")'),
  array('name' => 'remove-only', 'on-login' => ':put (",rem,1000,1d,1500,,Disable,")'),
  array('name' => 'billing', 'on-login' => ':put (",,1000,30d,1500,noexp,Disable,")'),
);
$users = array(
  array('name' => 'stock-a', 'profile' => 'remove-record', 'comment' => 'vc-batch'),
  array('name' => 'active-a', 'profile' => 'remove-record', 'comment' => 'sep/02/2026 10:00:00'),
  array('name' => 'notice-expired', 'profile' => 'notice', 'comment' => 'sep/01/2026', 'limit-uptime' => '1s'),
  array('name' => 'remove-stock', 'profile' => 'remove-only', 'comment' => 'up-batch'),
);
$records = array(
  array('name' => 'aug/31/2026-|-10:00:00-|-active-a-|-1500-|-10.0.0.1-|-AA-|-1d-|-remove-record-|-vc-batch-|-hotspot-|-1000'),
  array('name' => 'aug/28/2026-|-10:00:00-|-expired-a-|-1500-|-10.0.0.2-|-BB-|-1d-|-remove-record-|-vc-batch-|-hotspot-|-1000'),
);
$summary = mikhmonVoucherSummary($profiles, $users, $records, strtotime('2026-08-31 12:00:00'));

voucherSummaryTestAssert($summary['remove-record']['total'] === 3, 'remove and record combines live users with history');
voucherSummaryTestAssert($summary['remove-record']['unused'] === 1, 'unredeemed remove and record voucher remains available');
voucherSummaryTestAssert($summary['remove-record']['used'] === 1, 'record with a future due date is currently used');
voucherSummaryTestAssert($summary['remove-record']['expired'] === 1, 'expired removed voucher is recovered from record history');
voucherSummaryTestAssert($summary['notice']['expired'] === 1, 'notice mode reads expired state from the live user');
voucherSummaryTestAssert($summary['remove-only']['expired_known'] === false, 'remove without record reports unavailable expiry history');
voucherSummaryTestAssert(!isset($summary['billing']), 'billing profiles are excluded from voucher counts');

$ownerSummary = mikhmonVoucherOwnerSummary($profiles, array(
  array('name' => 'owner-unused', 'profile' => 'remove-record', 'comment' => 'vc-batch [mitra:user-1]'),
  array('name' => 'owner-used', 'profile' => 'remove-record', 'comment' => 'sep/02/2026 [mitra:user-1]'),
  array('name' => 'owner-expired', 'profile' => 'notice', 'comment' => 'up-batch [mitra:user-1]', 'limit-uptime' => '1s'),
  array('name' => 'other-owner', 'profile' => 'remove-record', 'comment' => 'vc-batch [mitra:user-2]'),
  array('name' => 'billing-user', 'profile' => 'billing', 'comment' => 'vc-batch [mitra:user-1]'),
), 'user-1');
voucherSummaryTestAssert($ownerSummary['total'] === 3, 'owner totals include only tagged users on voucher profiles');
voucherSummaryTestAssert($ownerSummary['unused'] === 1, 'owner unused total excludes used and expired vouchers');

echo 'voucher-summary-tests: OK' . PHP_EOL;
