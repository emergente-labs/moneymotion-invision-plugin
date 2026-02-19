<?php
/**
 * Test: MoneyMotion API Client
 *
 * Run: php tests/test_moneymotion_client.php
 */

require_once __DIR__ . '/../MoneyMotionClient.php';

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

echo "=== MoneyMotion Client Tests ===\n\n";

/* ---- Unit Tests ---- */

test('Client instantiation', function() use ($config) {
    $client = new MoneyMotionClient($config['moneymotion']['api_key']);
    assert_true($client instanceof MoneyMotionClient);
});

test('Webhook signature verification - valid', function() {
    $secret = 'test-secret-key';
    $body = '{"event":"checkout_session:complete"}';
    $signature = base64_encode(hash_hmac('sha512', $body, $secret, true));
    assert_true(MoneyMotionClient::verifySignature($body, $signature, $secret));
});

test('Webhook signature verification - invalid', function() {
    $secret = 'test-secret-key';
    $body = '{"event":"checkout_session:complete"}';
    $wrongSig = base64_encode(hash_hmac('sha512', $body, 'wrong-key', true));
    assert_true(!MoneyMotionClient::verifySignature($body, $wrongSig, $secret));
});

test('Webhook signature verification - tampered body', function() {
    $secret = 'test-secret-key';
    $body = '{"event":"checkout_session:complete"}';
    $signature = base64_encode(hash_hmac('sha512', $body, $secret, true));
    $tampered = '{"event":"checkout_session:complete","hacked":true}';
    assert_true(!MoneyMotionClient::verifySignature($tampered, $signature, $secret));
});

/* ---- Integration Test (live API) ---- */

test('Create checkout session (live API)', function() use ($config) {
    $client = new MoneyMotionClient(
        $config['moneymotion']['api_key'],
        $config['moneymotion']['api_base_url']
    );

    $sessionId = $client->createCheckoutSession(
        'Test Invoice #999',
        array(
            'success' => 'https://r336463.invisionservice.com/',
            'cancel'  => 'https://r336463.invisionservice.com/',
            'failure' => 'https://r336463.invisionservice.com/',
        ),
        'test@example.com',
        array(
            array(
                'name'                  => 'Test Product',
                'description'           => 'Test product for automated testing',
                'pricePerItemInCents'   => 100,
                'quantity'              => 1,
            ),
        ),
        array('test' => true),
        'USD'
    );

    assert_true(!empty($sessionId), 'Session ID should not be empty');
    assert_true(is_string($sessionId), 'Session ID should be a string');
    echo "  -> Session ID: {$sessionId}\n";
});

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
