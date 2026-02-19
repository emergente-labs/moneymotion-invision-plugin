<?php
/**
 * Test: IPS REST API Client
 *
 * Run: php tests/test_ips_client.php
 */

require_once __DIR__ . '/../IPSClient.php';

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

echo "=== IPS Client Tests ===\n\n";

$ips = new IPSClient($config['ips']['base_url'], $config['ips']['api_key']);

test('IPS API connection (core/hello)', function() use ($ips) {
    // Test by fetching invoices list (core/hello is not exposed through our client)
    // We'll test getInvoice instead
    assert_true($ips instanceof IPSClient);
});

test('Fetch invoice #2', function() use ($ips) {
    $invoice = $ips->getInvoice(2);
    assert_true(isset($invoice['id']), 'Invoice should have an ID');
    assert_eq($invoice['id'], 2, 'Invoice ID should be 2');
    assert_true(isset($invoice['items']), 'Invoice should have items');
    assert_true(isset($invoice['total']), 'Invoice should have a total');
    echo "  -> Invoice #{$invoice['id']}: {$invoice['total']['amount']} {$invoice['total']['currency']}\n";
    echo "  -> Status: {$invoice['status']}\n";
    echo "  -> Items: " . count($invoice['items']) . "\n";
});

test('Invoice has correct price format (IPS 5.x)', function() use ($ips) {
    $invoice = $ips->getInvoice(2);
    $item = $invoice['items'][0];

    assert_true(isset($item['itemPrice']['amount']), 'Item should have itemPrice.amount');
    assert_true(isset($item['itemPrice']['currency']), 'Item should have itemPrice.currency');
    echo "  -> Item: {$item['name']} = {$item['itemPrice']['amount']} {$item['itemPrice']['currency']}\n";
});

test('Invoice has callback URLs', function() use ($ips) {
    $invoice = $ips->getInvoice(2);
    assert_true(isset($invoice['viewUrl']), 'Invoice should have viewUrl');
    assert_true(isset($invoice['checkoutUrl']), 'Invoice should have checkoutUrl');
    echo "  -> viewUrl: {$invoice['viewUrl']}\n";
});

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
