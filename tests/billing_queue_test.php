<?php

date_default_timezone_set('Asia/Jakarta');
$queuePath = tempnam(sys_get_temp_dir(), 'mikhmon-payment-queue-');
putenv('MIKHMON_FONNTE_QUEUE=' . $queuePath);
require dirname(__DIR__) . '/include/database.php';
require dirname(__DIR__) . '/lib/fonnte.php';
require dirname(__DIR__) . '/lib/billing_automation.php';

function billingQueueTestAssert($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
}

$now = strtotime('2026-09-01 23:00:00');
$config = array_merge(mikhmonFonnteDefaults(), array(
  'enabled' => false,
  'token' => 'test-token',
  'queue_min_delay_minutes' => 1,
  'queue_max_delay_minutes' => 1,
));
$first = mikhmonBillingAutomationQueuedSend('08123456789', 'Pembayaran diterima.', $config, $now, false);
$second = mikhmonBillingAutomationQueuedSend('08123456780', 'Pembayaran diterima.', $config, $now, false);
$queue = mikhmonBillingAutomationReadQueue();

billingQueueTestAssert(!empty($first['attempted']), 'a payment notification can claim a queue slot outside reminder work hours');
billingQueueTestAssert(empty($second['attempted']), 'only one payment notification is attempted in one worker run');
billingQueueTestAssert((int) ($queue['next_attempt_at'] ?? 0) === $now + 60, 'the payment queue schedules its next slot without moving to the next workday');

@unlink($queuePath);
putenv('MIKHMON_FONNTE_QUEUE');
echo 'billing-queue-tests: OK' . PHP_EOL;
