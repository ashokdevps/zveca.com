<?php
/**
 * Razorpay webhook: https://<your-domain>/razorpay-webhook
 * Configure in Razorpay Dashboard → Settings → Webhooks with the events payment.captured,
 * order.paid and payment.failed, and the same secret as Settings → Pricing & payments.
 *
 * Confirms payments server-to-server, so an order is marked paid even if the customer closes
 * the browser before returning to the site. Every event is logged once (deduplicated by event id).
 */
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$respond = function (int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['status' => $msg]);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $respond(405, 'method not allowed');
$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 1_000_000) $respond(413, 'too large');
if (!rzp_verify_webhook_signature($raw, (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? ''))) {
    error_log('Razorpay webhook: invalid signature from ' . client_ip());
    $respond(400, 'invalid signature');
}

$body = json_decode($raw, true);
if (!is_array($body) || empty($body['event'])) $respond(400, 'bad payload');
$event = (string) $body['event'];
$payment = $body['payload']['payment']['entity'] ?? [];
$order = $body['payload']['order']['entity'] ?? [];
$orderId = (string) ($payment['order_id'] ?? ($order['id'] ?? ''));
$paymentId = (string) ($payment['id'] ?? '');
$eventId = (string) ($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '') ?: hash('sha256', $raw);

$sub = $orderId !== '' ? one('SELECT * FROM hrm_subscriptions WHERE gateway_order_id = ?', [$orderId]) : null;
try {
    insert('hrm_payment_events', ['subscription_id' => $sub['id'] ?? null, 'event_id' => mb_substr($eventId, 0, 80), 'event' => mb_substr($event, 0, 60),
        'gateway_order_id' => $orderId ?: null, 'gateway_payment_id' => $paymentId ?: null, 'amount' => isset($payment['amount']) ? $payment['amount'] / 100 : null,
        'source' => 'webhook', 'payload' => mb_substr($raw, 0, 60000)]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') $respond(200, 'duplicate'); // already processed this delivery
    throw $e;
}
if (!$sub) {
    // Resdex subscription orders (client portal → Plan & Credits). Activation is idempotent (rdx_order_mark_paid).
    $ro = $orderId !== '' ? one('SELECT * FROM hrm_rdx_orders WHERE gateway_order_id = ?', [$orderId]) : null;
    if (!$ro) $respond(200, 'ignored: unknown order'); // e.g. payments for other products on the same account
    if (in_array($event, ['payment.captured', 'order.paid'], true) && $ro['status'] !== 'paid') {
        $amount = (int) ($payment['amount'] ?? ($order['amount_paid'] ?? 0));
        if ($amount !== (int) round($ro['total'] * 100) || ($payment && ($payment['status'] ?? '') !== 'captured')) {
            q('UPDATE hrm_rdx_orders SET failure_reason = ? WHERE id = ?', ['Webhook amount/status mismatch — review manually', $ro['id']]);
            $respond(200, 'mismatch logged');
        }
        rdx_order_mark_paid((int) $ro['id'], $paymentId, (string) ($payment['method'] ?? ''), 'webhook');
        $respond(200, 'paid');
    }
    if ($event === 'payment.failed' && $ro['status'] === 'created') {
        q("UPDATE hrm_rdx_orders SET status = 'failed', failure_reason = ? WHERE id = ?", [mb_substr((string) ($payment['error_description'] ?? 'Payment failed'), 0, 255), $ro['id']]);
    }
    $respond(200, 'ok');
}

if (in_array($event, ['payment.captured', 'order.paid'], true) && $sub['status'] !== 'paid') {
    $amount = (int) ($payment['amount'] ?? ($order['amount_paid'] ?? 0));
    if ($amount !== (int) round($sub['total'] * 100) || ($payment && ($payment['status'] ?? '') !== 'captured')) {
        q('UPDATE hrm_subscriptions SET failure_reason = ? WHERE id = ?', ['Webhook amount/status mismatch — review manually', $sub['id']]);
        $respond(200, 'mismatch logged');
    }
    subscription_mark_paid($sub, $paymentId, (string) ($payment['method'] ?? ''), 'webhook');
    $respond(200, 'paid');
}
if ($event === 'payment.failed' && $sub['status'] === 'created') {
    $reason = (string) ($payment['error_description'] ?? 'Payment failed');
    q('UPDATE hrm_subscriptions SET failure_reason = ? WHERE id = ?', [mb_substr($reason, 0, 255), $sub['id']]);
}
$respond(200, 'ok');
