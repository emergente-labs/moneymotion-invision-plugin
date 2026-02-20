<?php
/**
 * moneymotion Webhook Handler
 *
 * Set this URL in your moneymotion dashboard:
 *   https://your-domain.com/moneymotionplugin/webhook.php
 *
 * Subscribe to: checkout_session:complete
 */

require_once __DIR__ . '/MoneyMotionClient.php';
require_once __DIR__ . '/IPSClient.php';
require_once __DIR__ . '/Database.php';

$config = require __DIR__ . '/config.php';

/* ---------- Only accept POST ---------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('error' => 'Method not allowed'));
    exit;
}

/* ---------- Read raw body ---------- */

$rawBody = file_get_contents('php://input');

if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(array('error' => 'Empty body'));
    exit;
}

/* ---------- Verify signature (if secret is configured) ---------- */

$webhookSecret = $config['moneymotion']['webhook_secret'];

if (!empty($webhookSecret)) {
    $signature = '';

    // Check common header names
    $headerNames = array('HTTP_X_WEBHOOK_SIGNATURE', 'HTTP_X_SIGNATURE', 'HTTP_X_MM_SIGNATURE');
    foreach ($headerNames as $header) {
        if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
            $signature = $_SERVER[$header];
            break;
        }
    }

    if (!empty($signature)) {
        if (!MoneyMotionClient::verifySignature($rawBody, $signature, $webhookSecret)) {
            error_log('moneymotion webhook: invalid signature');
            http_response_code(401);
            echo json_encode(array('error' => 'Invalid signature'));
            exit;
        }
    }
}

/* ---------- Parse payload ---------- */

$payload = json_decode($rawBody, true);

if (!$payload || !isset($payload['event'])) {
    http_response_code(400);
    echo json_encode(array('error' => 'Invalid payload'));
    exit;
}

$event = $payload['event'];
error_log("moneymotion webhook received: {$event}");

/* ---------- Handle events ---------- */

$db = new Database($config['db_path']);

switch ($event) {
    case 'checkout_session:complete':
        handleComplete($payload, $db, $config);
        break;

    case 'checkout_session:refunded':
        handleRefunded($payload, $db);
        break;

    case 'checkout_session:expired':
    case 'checkout_session:disputed':
        handleFailed($payload, $db);
        break;

    default:
        error_log("moneymotion webhook: unhandled event '{$event}'");
        break;
}

/* Return 200 OK */
http_response_code(200);
echo json_encode(array('status' => 'ok'));
exit;


/* ============================================================
   Event Handlers
   ============================================================ */

/**
 * Handle checkout_session:complete — mark order as paid
 */
function handleComplete(array $payload, Database $db, array $config)
{
    $checkoutSession = isset($payload['checkoutSession']) ? $payload['checkoutSession'] : array();
    $sessionId = isset($checkoutSession['id']) ? $checkoutSession['id'] : '';

    if (empty($sessionId)) {
        error_log('moneymotion webhook: complete event missing session ID');
        return;
    }

    /* Look up our stored session */
    $session = $db->getSession($sessionId);

    /* Try metadata fallback */
    if (!$session) {
        $metadata = isset($checkoutSession['metadata']) ? $checkoutSession['metadata'] : array();
        $invoiceId = isset($metadata['invoice_id']) ? (int) $metadata['invoice_id'] : 0;

        if ($invoiceId) {
            $session = $db->getSessionByInvoice($invoiceId);
        }
    }

    if (!$session) {
        error_log("moneymotion webhook: session not found for {$sessionId}");
        return;
    }

    /* Already processed? */
    if ($session['status'] === 'complete') {
        error_log("moneymotion webhook: session {$sessionId} already complete, skipping");
        return;
    }

    /* Mark as complete in our DB */
    $db->updateStatus($sessionId, 'complete');

    /* Notify IPS via REST API - mark invoice as paid */
    try {
        $ips = new IPSClient($config['ips']['base_url'], $config['ips']['api_key']);
        $invoice = $ips->getInvoice($session['invoice_id']);

        error_log("moneymotion: payment complete for invoice #{$session['invoice_id']}, session {$sessionId}");
        error_log("moneymotion: invoice status from IPS: " . (isset($invoice['status']) ? $invoice['status'] : 'unknown'));

        // The IPS REST API will show the invoice status
        // For full transaction approval, the IPS application version handles this natively
        // With standalone, the admin can verify in ACP that payment was received

    } catch (Exception $e) {
        error_log("moneymotion: error checking IPS invoice: " . $e->getMessage());
    }
}

/**
 * Handle refund
 */
function handleRefunded(array $payload, Database $db)
{
    $checkoutSession = isset($payload['checkoutSession']) ? $payload['checkoutSession'] : array();
    $sessionId = isset($checkoutSession['id']) ? $checkoutSession['id'] : '';

    if (!empty($sessionId)) {
        $db->updateStatus($sessionId, 'refunded');
        error_log("moneymotion: session {$sessionId} refunded");
    }
}

/**
 * Handle expired/disputed
 */
function handleFailed(array $payload, Database $db)
{
    $checkoutSession = isset($payload['checkoutSession']) ? $payload['checkoutSession'] : array();
    $sessionId = isset($checkoutSession['id']) ? $checkoutSession['id'] : '';

    if (!empty($sessionId)) {
        $db->updateStatus($sessionId, 'failed');
        error_log("moneymotion: session {$sessionId} failed/expired/disputed");
    }
}
