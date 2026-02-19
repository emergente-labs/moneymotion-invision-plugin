<?php
/**
 * Test: Webhook Handler
 *
 * Run: php tests/test_webhook.php
 * Requires the PHP server to be running on localhost:8000
 */

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

function http_post($url, $body, $headers = array()) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $body,
        CURLOPT_HTTPHEADER      => $headers,
    ));
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $code, 'body' => $response);
}

function http_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true));
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $code, 'body' => $response);
}

echo "=== Webhook Handler Tests ===\n";
echo "(Server must be running on localhost:8000)\n\n";

/* Check server is running */
$check = @http_get('http://localhost:8000/check_db.php');
if ($check['code'] === 0) {
    echo "[SKIP] Server not running on localhost:8000. Start it with:\n";
    echo "  php -S localhost:8000 -t standalone/\n";
    exit(1);
}

test('Webhook rejects GET requests', function() {
    $r = http_get('http://localhost:8000/webhook.php');
    assert_eq($r['code'], 405, "Should return 405 for GET");
});

test('Webhook rejects empty POST', function() {
    $r = http_post('http://localhost:8000/webhook.php', '', array('Content-Type: application/json'));
    assert_eq($r['code'], 400, "Should return 400 for empty body");
});

test('Webhook rejects invalid JSON', function() {
    $r = http_post('http://localhost:8000/webhook.php', 'not-json', array('Content-Type: application/json'));
    assert_eq($r['code'], 400, "Should return 400 for invalid JSON");
});

test('Webhook rejects payload without event', function() {
    $r = http_post('http://localhost:8000/webhook.php', '{"foo":"bar"}', array('Content-Type: application/json'));
    assert_eq($r['code'], 400, "Should return 400 for missing event");
});

test('Webhook accepts valid checkout_session:complete', function() {
    $payload = json_encode(array(
        'event' => 'checkout_session:complete',
        'checkoutSession' => array(
            'id' => 'test-session-' . time(),
            'status' => 'complete',
            'amountInCents' => 100,
            'metadata' => array('invoice_id' => 999, 'source' => 'test'),
        ),
        'customer' => array('email' => 'test@test.com'),
    ));
    $r = http_post('http://localhost:8000/webhook.php', $payload, array('Content-Type: application/json'));
    assert_eq($r['code'], 200, "Should return 200 OK");
    $body = json_decode($r['body'], true);
    assert_eq($body['status'], 'ok', "Should return status ok");
});

test('Webhook handles unknown events gracefully', function() {
    $payload = json_encode(array(
        'event' => 'some_unknown:event',
        'checkoutSession' => array('id' => 'test'),
    ));
    $r = http_post('http://localhost:8000/webhook.php', $payload, array('Content-Type: application/json'));
    assert_eq($r['code'], 200, "Should return 200 for unknown events");
});

test('Webhook handles checkout_session:expired', function() {
    $payload = json_encode(array(
        'event' => 'checkout_session:expired',
        'checkoutSession' => array('id' => 'expired-session-test'),
    ));
    $r = http_post('http://localhost:8000/webhook.php', $payload, array('Content-Type: application/json'));
    assert_eq($r['code'], 200);
});

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
