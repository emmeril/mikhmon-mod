<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/payment_gateway.php';
require_once __DIR__ . '/include/database.php';

function paymentNotificationRespond($httpCode, $payload) {
  http_response_code($httpCode);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit;
}

function paymentNotificationHeader($name) {
  $serverName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return isset($_SERVER[$serverName]) ? trim((string) $_SERVER[$serverName]) : '';
}

function paymentNotificationFindInvoice($orderId) {
  $database = mikhmonReadDatabase();
  foreach ((array) ($database['invoices'] ?? array()) as $session => $invoices) {
    foreach ((array) $invoices as $invoice) {
      if ((string) ($invoice['payment_order_id'] ?? '') === (string) $orderId || (string) ($invoice['number'] ?? '') === (string) $orderId || (string) ($invoice['id'] ?? '') === (string) $orderId) {
        return array('session' => (string) $session, 'invoice' => $invoice);
      }
    }
  }
  return array();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') paymentNotificationRespond(405, array('success' => false, 'message' => 'Method not allowed.'));

$provider = isset($_GET['provider']) ? strtolower(trim((string) $_GET['provider'])) : '';
$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) paymentNotificationRespond(400, array('success' => false, 'message' => 'Invalid JSON payload.'));

$config = mikhmonPaymentGatewayReadConfig();
$orderId = '';
$paid = false;
$status = '';
$reference = '';

if ($provider === 'midtrans') {
  if (!mikhmonPaymentGatewayValidMidtransNotification($payload, $config['midtrans']['server_key'])) paymentNotificationRespond(401, array('success' => false, 'message' => 'Invalid Midtrans signature.'));
  $orderId = (string) ($payload['order_id'] ?? '');
  $paid = mikhmonPaymentGatewayMidtransPaid($payload);
  $status = (string) ($payload['transaction_status'] ?? 'unknown');
  $reference = (string) ($payload['transaction_id'] ?? '');
} elseif ($provider === 'xendit') {
  $callbackToken = paymentNotificationHeader('X-Callback-Token');
  if (!mikhmonPaymentGatewayValidXenditCallback($callbackToken, $config['xendit']['webhook_token'])) paymentNotificationRespond(401, array('success' => false, 'message' => 'Invalid Xendit callback token.'));
  $orderId = (string) ($payload['external_id'] ?? '');
  $paid = mikhmonPaymentGatewayXenditPaid($payload);
  $status = (string) ($payload['status'] ?? 'unknown');
  $reference = (string) ($payload['id'] ?? '');
} else {
  paymentNotificationRespond(400, array('success' => false, 'message' => 'Unknown provider.'));
}

if ($orderId === '') paymentNotificationRespond(400, array('success' => false, 'message' => 'Missing order ID.'));
$match = paymentNotificationFindInvoice($orderId);
if (!$match) paymentNotificationRespond(404, array('success' => false, 'message' => 'Invoice not found.'));

$invoice = $match['invoice'];
$notifiedAmount = $provider === 'midtrans' ? (float) ($payload['gross_amount'] ?? 0) : (float) ($payload['paid_amount'] ?? ($payload['amount'] ?? 0));
if ($paid && (int) round($notifiedAmount) !== (int) round((float) ($invoice['amount'] ?? 0))) paymentNotificationRespond(422, array('success' => false, 'message' => 'Payment amount does not match invoice.'));
$invoice['payment_gateway'] = $provider;
$invoice['payment_reference'] = $reference !== '' ? $reference : ($invoice['payment_reference'] ?? '');
$invoice['gateway_status'] = $status;
$invoice['gateway_updated_at'] = time();
if ($paid) {
  // Billing performs the router activation transaction before changing invoice status.
  $invoice['gateway_payment_received'] = true;
  $invoice['gateway_paid_at'] = time();
}
if (mikhmonSaveInvoice($match['session'], $invoice) === false) paymentNotificationRespond(500, array('success' => false, 'message' => 'Invoice update failed.'));

paymentNotificationRespond(200, array('success' => true, 'received' => true, 'paid' => $paid));
