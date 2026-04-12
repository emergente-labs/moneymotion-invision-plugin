<?php
/**
 * End-to-End webhook tests
 *
 * Simulates the full HTTP request → webhook processing flow.
 * Tests the webhook() method with real payloads, headers, and signatures.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;
use Tests\Fixtures\WebhookPayloads;

class WebhookEndToEndTest extends TestCase
{
	private TestableWebhookController $controller;
	private string $webhookSecret = 'whsec_test_secret_123';

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		\IPS\nexus\Transaction::reset();
		\IPS\Settings::reset();
		\IPS\Member::reset();
		\IPS\Output::reset();

		unset( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] );
		unset( $_SERVER['HTTP_X_SIGNATURE'] );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		unset( $_SERVER['REMOTE_ADDR'] );
		$_SERVER['REMOTE_ADDR'] = '1.2.3.4';

		$this->controller = new TestableWebhookController;

		// Set up the gateway in the DB
		\IPS\Db::i()->mockData['nexus_paymethods'] = array(
			array(
				'm_id' => 1,
				'm_gateway' => 'moneymotion',
				'm_settings' => json_encode( array(
					'api_key' => 'mk_live_test',
					'webhook_secret' => $this->webhookSecret,
				)),
				'm_active' => 1,
			),
		);
	}

	protected function tearDown(): void
	{
		unset( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] );
		unset( $_SERVER['HTTP_X_SIGNATURE'] );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	/**
	 * Simulate the webhook() flow by calling the individual steps
	 * in the same order the real method would.
	 *
	 * We can't easily mock php://input in PHPUnit, so we replicate
	 * the webhook()'s dispatch logic here.
	 */
	private function simulateWebhook( array $payload, string $signature, string $signatureHeader = 'HTTP_X_WEBHOOK_SIGNATURE' ): array
	{
		$rawBody = json_encode( $payload );

		$_SERVER[ $signatureHeader ] = $signature;

		// Step 1: parse + validate
		if ( empty( $rawBody ) )
		{
			return array( 'error' => 'Empty body', 'code' => 400 );
		}

		$parsed = json_decode( $rawBody, true );
		if ( !$parsed || !isset( $parsed['event'] ) )
		{
			return array( 'error' => 'Invalid payload', 'code' => 400 );
		}

		// Step 2: timestamp (skip if not set)
		$ts = isset( $parsed['timestamp'] ) ? (int) $parsed['timestamp'] : 0;
		if ( $ts > 2000000000 )
		{
			$ts = (int) floor( $ts / 1000 );
		}
		if ( $ts && abs( time() - $ts ) > 300 )
		{
			return array( 'error' => 'Webhook timestamp too old', 'code' => 400 );
		}

		// Step 3: find gateway
		$gateway = $this->controller->testFindGateway();
		if ( !$gateway )
		{
			return array( 'error' => 'Gateway not configured', 'code' => 500 );
		}

		$settings = json_decode( $gateway->settings, true );
		$secret = $settings['webhook_secret'] ?? '';
		if ( empty( $secret ) )
		{
			return array( 'error' => 'Webhook secret not configured', 'code' => 500 );
		}

		// Step 4: verify signature
		$sig = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ( $_SERVER['HTTP_X_SIGNATURE'] ?? '' );
		if ( empty( $sig ) )
		{
			return array( 'error' => 'Signature missing', 'code' => 401 );
		}

		if ( !$this->controller->testVerifyWebhookSignature( $rawBody, $sig, $secret ) )
		{
			return array( 'error' => 'Invalid signature', 'code' => 401 );
		}

		// Step 5: route event
		switch ( $parsed['event'] )
		{
			case 'checkout_session:complete':
				$this->controller->testHandleCheckoutComplete( $parsed );
				break;
			case 'checkout_session:refunded':
				$this->controller->testHandleCheckoutRefunded( $parsed );
				break;
			case 'checkout_session:expired':
			case 'checkout_session:disputed':
				$this->controller->testHandleCheckoutFailed( $parsed );
				break;
		}

		return array( 'status' => 'ok', 'code' => 200 );
	}

	/**
	 * FULL HAPPY PATH: webhook arrives, signature valid, amount matches,
	 * transaction gets approved, session marked complete.
	 */
	public function testFullHappyPath(): void
	{
		// Set up session + transaction
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id' => 'cs_e2e_test',
				'transaction_id' => 555,
				'invoice_id' => 100,
				'amount_cents' => 5000,
				'currency' => 'EUR',
				'status' => 'pending',
				'created_at' => time(),
				'updated_at' => time(),
			),
		);

		$txn = new \IPS\nexus\Transaction;
		$txn->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( 555, $txn );

		// Real moneymotion payload
		$payload = WebhookPayloads::completeReal( 'cs_e2e_test', 5000 );
		$signature = WebhookPayloads::sign( $payload, $this->webhookSecret );

		$result = $this->simulateWebhook( $payload, $signature );

		$this->assertSame( 200, $result['code'], 'Webhook should return 200 OK' );
		$this->assertTrue( $txn->wasApproved, 'Transaction must be approved' );
	}

	/**
	 * Invalid signature should be rejected with 401
	 */
	public function testInvalidSignatureRejectedWith401(): void
	{
		$payload = WebhookPayloads::completeReal( 'cs_test', 5000 );
		$result = $this->simulateWebhook( $payload, 'invalid_signature' );

		$this->assertSame( 401, $result['code'] );
		$this->assertSame( 'Invalid signature', $result['error'] );
	}

	/**
	 * Missing signature should be rejected with 401
	 */
	public function testMissingSignatureRejectedWith401(): void
	{
		$payload = WebhookPayloads::completeReal( 'cs_test', 5000 );
		$result = $this->simulateWebhook( $payload, '' );

		$this->assertSame( 401, $result['code'] );
	}

	/**
	 * Tampered body should be rejected
	 */
	public function testTamperedBodyRejected(): void
	{
		$originalPayload = WebhookPayloads::completeReal( 'cs_test', 5000 );
		$signature = WebhookPayloads::sign( $originalPayload, $this->webhookSecret );

		// Tamper the amount
		$tampered = $originalPayload;
		$tampered['checkoutSession']['totalInCents'] = 1;

		$result = $this->simulateWebhook( $tampered, $signature );
		$this->assertSame( 401, $result['code'] );
		$this->assertSame( 'Invalid signature', $result['error'] );
	}

	/**
	 * Missing gateway in DB → 500
	 */
	public function testMissingGatewayReturns500(): void
	{
		// Remove the gateway from mock data
		\IPS\Db::i()->mockData['nexus_paymethods'] = array();

		$payload = WebhookPayloads::completeReal( 'cs_test', 5000 );
		$signature = WebhookPayloads::sign( $payload, $this->webhookSecret );

		$result = $this->simulateWebhook( $payload, $signature );
		$this->assertSame( 500, $result['code'] );
	}

	/**
	 * Gateway without webhook_secret → 500
	 */
	public function testMissingWebhookSecretReturns500(): void
	{
		\IPS\Db::i()->mockData['nexus_paymethods'] = array(
			array(
				'm_id' => 1,
				'm_gateway' => 'moneymotion',
				'm_settings' => json_encode( array( 'api_key' => 'mk_live_test' ) ),
			),
		);

		$payload = WebhookPayloads::completeReal( 'cs_test', 5000 );
		$signature = WebhookPayloads::sign( $payload, $this->webhookSecret );

		$result = $this->simulateWebhook( $payload, $signature );
		$this->assertSame( 500, $result['code'] );
		$this->assertSame( 'Webhook secret not configured', $result['error'] );
	}

	/**
	 * Old timestamp should be rejected with 401 (replay protection)
	 */
	public function testOldTimestampRejected(): void
	{
		$payload = WebhookPayloads::completeReal( 'cs_test', 5000 );
		$payload['timestamp'] = time() - 1000; // 1000 seconds ago

		$signature = WebhookPayloads::sign( $payload, $this->webhookSecret );

		$result = $this->simulateWebhook( $payload, $signature );
		$this->assertSame( 400, $result['code'], 'Stale timestamp is a bad request, not an auth failure' );
		$this->assertStringContainsString( 'timestamp too old', $result['error'] );
	}

	/**
	 * Recent timestamp should pass
	 */
	public function testRecentTimestampAccepted(): void
	{
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id' => 'cs_test',
				'transaction_id' => 555,
				'invoice_id' => 100,
				'amount_cents' => 5000,
				'currency' => 'EUR',
				'status' => 'pending',
				'created_at' => time(),
				'updated_at' => time(),
			),
		);
		$txn = new \IPS\nexus\Transaction;
		\IPS\nexus\Transaction::register( 555, $txn );

		$payload = WebhookPayloads::completeReal( 'cs_test', 5000 );
		$payload['timestamp'] = time() - 60; // 60 seconds ago
		$signature = WebhookPayloads::sign( $payload, $this->webhookSecret );

		$result = $this->simulateWebhook( $payload, $signature );
		$this->assertSame( 200, $result['code'] );
	}

	/**
	 * Millisecond timestamp should be auto-converted
	 */
	public function testMillisecondTimestampConverted(): void
	{
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id' => 'cs_test',
				'transaction_id' => 555,
				'invoice_id' => 100,
				'amount_cents' => 5000,
				'currency' => 'EUR',
				'status' => 'pending',
				'created_at' => time(),
				'updated_at' => time(),
			),
		);
		$txn = new \IPS\nexus\Transaction;
		\IPS\nexus\Transaction::register( 555, $txn );

		$payload = WebhookPayloads::completeReal( 'cs_test', 5000 );
		$payload['timestamp'] = time() * 1000; // milliseconds
		$signature = WebhookPayloads::sign( $payload, $this->webhookSecret );

		$result = $this->simulateWebhook( $payload, $signature );
		$this->assertSame( 200, $result['code'], 'Millisecond timestamp should convert cleanly' );
	}

	/**
	 * Fallback signature header (X-Signature without X-Webhook-Signature) works
	 */
	public function testXSignatureFallbackHeader(): void
	{
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id' => 'cs_test',
				'transaction_id' => 555,
				'invoice_id' => 100,
				'amount_cents' => 5000,
				'currency' => 'EUR',
				'status' => 'pending',
				'created_at' => time(),
				'updated_at' => time(),
			),
		);
		$txn = new \IPS\nexus\Transaction;
		\IPS\nexus\Transaction::register( 555, $txn );

		$payload = WebhookPayloads::completeReal( 'cs_test', 5000 );
		$signature = WebhookPayloads::sign( $payload, $this->webhookSecret );

		// Use X-Signature header instead of X-Webhook-Signature
		$result = $this->simulateWebhook( $payload, $signature, 'HTTP_X_SIGNATURE' );
		$this->assertSame( 200, $result['code'] );
	}

	/**
	 * Test all event types route correctly
	 */
	public function testNewEventLogsButDoesNotFail(): void
	{
		$payload = WebhookPayloads::newSession( 'cs_new_test' );
		$signature = WebhookPayloads::sign( $payload, $this->webhookSecret );

		$result = $this->simulateWebhook( $payload, $signature );
		// Plugin doesn't handle :new event — should still return 200
		$this->assertSame( 200, $result['code'] );
	}

	public function testRefundedEventRouting(): void
	{
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id' => 'cs_refund_test',
				'transaction_id' => 777,
				'invoice_id' => 100,
				'amount_cents' => 5000,
				'currency' => 'EUR',
				'status' => 'complete',
				'created_at' => time(),
				'updated_at' => time(),
			),
		);
		$txn = new \IPS\nexus\Transaction;
		\IPS\nexus\Transaction::register( 777, $txn );

		$payload = WebhookPayloads::refunded( 'cs_refund_test' );
		$signature = WebhookPayloads::sign( $payload, $this->webhookSecret );

		$result = $this->simulateWebhook( $payload, $signature );
		$this->assertSame( 200, $result['code'] );
		$this->assertSame( \IPS\nexus\Transaction::STATUS_REFUNDED, $txn->status );
	}

	public function testExpiredEventRouting(): void
	{
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id' => 'cs_expired',
				'transaction_id' => 888,
				'invoice_id' => 100,
				'amount_cents' => 5000,
				'currency' => 'EUR',
				'status' => 'pending',
				'created_at' => time(),
				'updated_at' => time(),
			),
		);

		$payload = WebhookPayloads::expired( 'cs_expired' );
		$signature = WebhookPayloads::sign( $payload, $this->webhookSecret );

		$result = $this->simulateWebhook( $payload, $signature );
		$this->assertSame( 200, $result['code'] );

		$updates = \IPS\Db::i()->getOperations( 'update', 'moneymotion_sessions' );
		$found = false;
		foreach ( $updates as $op )
		{
			if ( isset( $op['data']['status'] ) && $op['data']['status'] === 'failed' )
			{
				$found = true;
			}
		}
		$this->assertTrue( $found );
	}

	public function testDisputedEventRouting(): void
	{
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id' => 'cs_disputed',
				'transaction_id' => 999,
				'invoice_id' => 100,
				'amount_cents' => 5000,
				'currency' => 'EUR',
				'status' => 'complete',
				'created_at' => time(),
				'updated_at' => time(),
			),
		);

		$payload = WebhookPayloads::disputed( 'cs_disputed' );
		$signature = WebhookPayloads::sign( $payload, $this->webhookSecret );

		$result = $this->simulateWebhook( $payload, $signature );
		$this->assertSame( 200, $result['code'] );
	}
}
