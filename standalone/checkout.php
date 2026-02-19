<?php
/**
 * Checkout Page
 *
 * Usage: checkout.php?invoice_id=123&email=customer@example.com
 *
 * Creates a MoneyMotion checkout session and redirects the customer to pay.
 */

require_once __DIR__ . '/MoneyMotionClient.php';
require_once __DIR__ . '/IPSClient.php';
require_once __DIR__ . '/Database.php';

$config = require __DIR__ . '/config.php';

/* ---------- Validate input ---------- */

$invoiceId = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;
$email = isset($_GET['email']) ? trim($_GET['email']) : '';

if (!$invoiceId) {
    http_response_code(400);
    die('Missing invoice_id parameter. Usage: checkout.php?invoice_id=123&email=customer@example.com');
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    die('Missing or invalid email parameter.');
}

/* ---------- Fetch invoice from IPS ---------- */

try {
    $ips = new IPSClient($config['ips']['base_url'], $config['ips']['api_key']);
    $invoice = $ips->getInvoice($invoiceId);
} catch (Exception $e) {
    http_response_code(500);
    die('Could not fetch invoice: ' . $e->getMessage());
}

/* ---------- Build line items ---------- */

$lineItems = array();
$totalCents = 0;

if (isset($invoice['items']) && is_array($invoice['items'])) {
    foreach ($invoice['items'] as $item) {
        /* IPS 5.x returns price as {"currency":"USD","amount":"1.00"} */
        $price = 0;
        if (isset($item['itemPrice']['amount'])) {
            $price = (float) $item['itemPrice']['amount'];
        } elseif (isset($item['linePrice']['amount'])) {
            $price = (float) $item['linePrice']['amount'];
        } elseif (isset($item['price'])) {
            $price = (float) $item['price'];
        }
        $priceCents = (int) round($price * 100);
        $qty = isset($item['quantity']) ? (int) $item['quantity'] : 1;
        $lineItems[] = array(
            'name'                  => isset($item['name']) ? $item['name'] : 'Item',
            'description'           => isset($item['name']) ? $item['name'] : 'Item',
            'pricePerItemInCents'   => $priceCents,
            'quantity'              => $qty,
        );
        $totalCents += $priceCents * $qty;
    }
}

/* Fallback: use invoice total if no line items */
if (empty($lineItems)) {
    $total = isset($invoice['total']['amount']) ? (float) $invoice['total']['amount'] : (isset($invoice['total']) ? (float) $invoice['total'] : 0);
    $totalCents = (int) round($total * 100);
    $lineItems[] = array(
        'name'                  => "Invoice #{$invoiceId}",
        'description'           => "Payment for Invoice #{$invoiceId}",
        'pricePerItemInCents'   => $totalCents,
        'quantity'              => 1,
    );
}

if ($totalCents <= 0) {
    http_response_code(400);
    die('Invoice has no amount to pay.');
}

/* ---------- Build URLs ---------- */

/* MoneyMotion requires HTTPS URLs. Use IPS community URL for return redirects */
$ipsUrl = rtrim($config['ips']['base_url'], '/');
$invoiceViewUrl = isset($invoice['viewUrl']) ? $invoice['viewUrl'] : $ipsUrl;
$urls = array(
    'success'   => $invoiceViewUrl,
    'cancel'    => isset($invoice['checkoutUrl']) ? $invoice['checkoutUrl'] : $ipsUrl,
    'failure'   => isset($invoice['checkoutUrl']) ? $invoice['checkoutUrl'] : $ipsUrl,
);

/* ---------- Create checkout session ---------- */

try {
    $mm = new MoneyMotionClient(
        $config['moneymotion']['api_key'],
        $config['moneymotion']['api_base_url']
    );

    /* Get currency from invoice */
    $currency = 'USD';
    if (isset($invoice['total']['currency'])) {
        $currency = $invoice['total']['currency'];
    }

    $sessionId = $mm->createCheckoutSession(
        "Invoice #{$invoiceId}",
        $urls,
        $email,
        $lineItems,
        array(
            'invoice_id'    => $invoiceId,
            'source'        => 'ips_community',
        ),
        $currency
    );
} catch (Exception $e) {
    http_response_code(500);
    die('Could not create checkout session: ' . $e->getMessage());
}

/* ---------- Save session to DB ---------- */

try {
    $db = new Database($config['db_path']);
    $db->saveSession($sessionId, array(
        'invoice_id'    => $invoiceId,
        'email'         => $email,
        'description'   => "Invoice #{$invoiceId}",
        'amount_cents'  => $totalCents,
    ));
} catch (Exception $e) {
    // Log but don't block - the session was already created
    error_log('MoneyMotion DB error: ' . $e->getMessage());
}

/* ---------- Redirect to MoneyMotion checkout ---------- */

$checkoutUrl = "https://moneymotion.io/checkout/{$sessionId}";
header("Location: {$checkoutUrl}");
exit;
