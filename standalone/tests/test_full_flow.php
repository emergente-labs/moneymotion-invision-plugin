<?php
/**
 * Test: Full Checkout Flow (end-to-end)
 *
 * Run: php tests/test_full_flow.php
 * Requires the PHP server to be running on localhost:8000
 */

require_once __DIR__ . '/../MoneyMotionClient.php';
require_once __DIR__ . '/../Database.php';

$config = require __DIR__ . '/../config.php';
$passed = 0;
$failed = 0;

function test($name, $fn) {
    global $passed, $failed;
    try {
        $fn();
        echo "[PASS] {$name}\n";
        $passed++;
    } catch (Exception $e) {
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assert_true($val, $msg = '') { if (!$val) throw new Exception($msg ?: 'Expected true'); }
function assert_eq($a, $b, $msg = '') { if ($a !== $b) throw new Exception($msg ?: "Expected '{$b}', got '{$a}'"); }

echo "=== Full Flow Test ===\n\n";

/* Step 1: Create checkout via HTTP */
test('Step 1: Checkout creates session and redirects', function() {
    $ch = curl_init('http://localhost:8000/checkout.php?invoice_id=2&email=pcruz@sellhub.cx');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
    ));
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    assert_eq($code, 302, "Should redirect (302)");
    assert_true(strpos($response, 'moneymotion.io/checkout/') !== false, "Should redirect to moneymotion.io/checkout/");

    // Extract session ID from Location header
    preg_match('/Location: https:\/\/moneymotion\.io\/checkout\/(.+)/', $response, $m);
    assert_true(!empty($m[1]), "Should have session ID in redirect URL");

    $GLOBALS['test_session_id'] = trim($m[1]);
    echo "  -> Session: {$GLOBALS['test_session_id']}\n";
});

/* Step 2: Verify session stored in DB */
test('Step 2: Session stored in SQLite', function() use ($config) {
    $db = new Database($config['db_path']);
    $session = $db->getSession($GLOBALS['test_session_id']);

    assert_true($session !== false, "Session should exist in DB");
    assert_eq($session['status'], 'pending', "Status should be pending");
    assert_eq((int)$session['invoice_id'], 2, "Invoice ID should be 2");
    assert_eq($session['email'], 'pcruz@sellhub.cx', "Email should match");
    echo "  -> Amount: {$session['amount_cents']}c {$session['currency']}\n";
});

/* Step 3: Simulate webhook */
test('Step 3: Webhook marks session complete', function() use ($config) {
    $sessionId = $GLOBALS['test_session_id'];

    $payload = json_encode(array(
        'event' => 'checkout_session:complete',
        'checkoutSession' => array(
            'id' => $sessionId,
            'status' => 'complete',
            'amountInCents' => 100,
            'metadata' => array('invoice_id' => 2, 'source' => 'ips_community'),
        ),
        'customer' => array(
            'email' => 'pcruz@sellhub.cx',
            'firstName' => 'Pedro',
            'lastName' => 'Cruz',
        ),
    ));

    $ch = curl_init('http://localhost:8000/webhook.php');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    ));
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    assert_eq($code, 200, "Webhook should return 200");
    $body = json_decode($response, true);
    assert_eq($body['status'], 'ok');
});

/* Step 4: Verify DB updated */
test('Step 4: Session status updated to complete', function() use ($config) {
    $db = new Database($config['db_path']);
    $session = $db->getSession($GLOBALS['test_session_id']);

    assert_eq($session['status'], 'complete', "Status should be complete");
    assert_true((int)$session['updated_at'] > (int)$session['created_at'], "updated_at should be after created_at");
    echo "  -> Status: {$session['status']}\n";
});

/* Step 5: Verify duplicate webhook is idempotent */
test('Step 5: Duplicate webhook is idempotent', function() {
    $sessionId = $GLOBALS['test_session_id'];

    $payload = json_encode(array(
        'event' => 'checkout_session:complete',
        'checkoutSession' => array('id' => $sessionId, 'status' => 'complete'),
        'customer' => array('email' => 'pcruz@sellhub.cx'),
    ));

    $ch = curl_init('http://localhost:8000/webhook.php');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    ));
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    assert_eq($code, 200, "Duplicate webhook should still return 200");
});

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
