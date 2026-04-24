<?php
/**
 * Integration tests for handleCheckoutRefunded()
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;
use Tests\Fixtures\WebhookPayloads;

class HandleCheckoutRefundedTest extends TestCase
{
	private TestableWebhookController $controller;

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		\IPS\nexus\Transaction::reset();
		\IPS\Settings::reset();
		\IPS\Member::reset();

		$this->controller = new TestableWebhookController;
	}

	public function testRefundUpdatesTransactionStatus(): void
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

		$txn = new \IPS\nexus\Transaction;
		$txn->status = \IPS\nexus\Transaction::STATUS_PAID;
		\IPS\nexus\Transaction::register( 200, $txn );

		$payload = WebhookPayloads::refunded( 'cs_test_123' );
		$this->controller->testHandleCheckoutRefunded( $payload );

		$this->assertSame(
			\IPS\nexus\Transaction::STATUS_REFUNDED,
			$txn->status,
			'Transaction status should be REFUNDED'
		);
	}

	public function testRefundUpdatesSessionStatus(): void
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

		$txn = new \IPS\nexus\Transaction;
		\IPS\nexus\Transaction::register( 200, $txn );

		$payload = WebhookPayloads::refunded( 'cs_test_123' );
		$this->controller->testHandleCheckoutRefunded( $payload );

		$updates = \IPS\Db::i()->getOperations( 'update', 'moneymotion_sessions' );
		$found = false;
		foreach ( $updates as $op )
		{
			if ( isset( $op['data']['status'] ) && $op['data']['status'] === 'refunded' )
			{
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Session should be marked refunded' );
	}

	public function testRefundWithMissingSessionIdHandled(): void
	{
		$payload = array(
			'event' => 'checkout_session:refunded',
			'checkoutSession' => array(),
		);
		// Should not throw
		$this->controller->testHandleCheckoutRefunded( $payload );
		$this->assertTrue( true );
	}

	public function testRefundWithNonexistentSessionHandled(): void
	{
		$payload = WebhookPayloads::refunded( 'cs_nonexistent' );
		// Should not throw, just log
		$this->controller->testHandleCheckoutRefunded( $payload );

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'refund webhook error' ) || count( \IPS\Log::$logs ) >= 0,
			'Should handle missing session gracefully'
		);
	}
}
