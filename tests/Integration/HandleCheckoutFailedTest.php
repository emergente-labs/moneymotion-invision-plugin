<?php
/**
 * Integration tests for handleCheckoutFailed() (expired + disputed events)
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;
use Tests\Fixtures\WebhookPayloads;

class HandleCheckoutFailedTest extends TestCase
{
	private TestableWebhookController $controller;

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		\IPS\nexus\Transaction::reset();

		$this->controller = new TestableWebhookController;
	}

	public function testExpiredEventMarksSessionFailed(): void
	{
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id'     => 'cs_test_123',
				'transaction_id' => 200,
				'invoice_id'     => 100,
				'amount_cents'   => 5000,
				'currency'       => 'EUR',
				'status'         => 'pending',
				'created_at'     => time(),
				'updated_at'     => time(),
			),
		);

		$payload = WebhookPayloads::expired( 'cs_test_123' );
		$this->controller->testHandleCheckoutFailed( $payload );

		$updates = \IPS\Db::i()->getOperations( 'update', 'moneymotion_sessions' );
		$found = false;
		foreach ( $updates as $op )
		{
			if ( isset( $op['data']['status'] ) && $op['data']['status'] === 'failed' )
			{
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Session should be marked failed on expiry' );
	}

	public function testDisputedEventMarksSessionFailed(): void
	{
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id'     => 'cs_test_123',
				'transaction_id' => 200,
				'invoice_id'     => 100,
				'amount_cents'   => 5000,
				'currency'       => 'EUR',
				'status'         => 'complete',
				'created_at'     => time(),
				'updated_at'     => time(),
			),
		);

		$payload = WebhookPayloads::disputed( 'cs_test_123' );
		$this->controller->testHandleCheckoutFailed( $payload );

		$updates = \IPS\Db::i()->getOperations( 'update', 'moneymotion_sessions' );
		$found = false;
		foreach ( $updates as $op )
		{
			if ( isset( $op['data']['status'] ) && $op['data']['status'] === 'failed' )
			{
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Session should be marked failed on dispute' );
	}

	public function testMissingSessionIdHandled(): void
	{
		$payload = array(
			'event' => 'checkout_session:expired',
			'checkoutSession' => array(),
		);
		$this->controller->testHandleCheckoutFailed( $payload );
		// Should not throw
		$this->assertTrue( true );
	}

	public function testLogsFailedSession(): void
	{
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id'     => 'cs_test_123',
				'transaction_id' => 200,
				'invoice_id'     => 100,
				'amount_cents'   => 5000,
				'currency'       => 'EUR',
				'status'         => 'pending',
				'created_at'     => time(),
				'updated_at'     => time(),
			),
		);

		$payload = WebhookPayloads::expired( 'cs_test_123' );
		$this->controller->testHandleCheckoutFailed( $payload );

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'marked as failed' ),
			'Should log that session was marked failed'
		);
	}
}
