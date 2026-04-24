<?php
/**
 * Tests for handleCheckoutRefunded() error paths
 *
 * The refund handler wraps everything in try/catch(\Exception) and logs
 * with "refund webhook error". Ensure those paths are reached.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;
use Tests\Fixtures\WebhookPayloads;

class HandleRefundedErrorPathsTest extends TestCase
{
	private TestableWebhookController $controller;

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		\IPS\nexus\Transaction::reset();
		$this->controller = new TestableWebhookController;
	}

	public function testRefundWithSessionThatHasNoMatchingTransactionLogsError(): void
	{
		/* Session exists but the transaction doesn't */
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id' => 'cs_orphan',
				'transaction_id' => 99999,     /* doesn't exist */
				'invoice_id' => 100,
				'amount_cents' => 5000,
				'currency' => 'EUR',
				'status' => 'complete',
				'created_at' => time(),
				'updated_at' => time(),
			),
		);

		$payload = WebhookPayloads::refunded( 'cs_orphan' );
		$this->controller->testHandleCheckoutRefunded( $payload );

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'refund webhook error' ),
			'Missing transaction during refund should be logged as error'
		);
	}

	public function testRefundEmptySessionIdReturnsEarly(): void
	{
		$payload = array(
			'event' => 'checkout_session:refunded',
			'checkoutSession' => array(),   /* no id */
		);

		$this->controller->testHandleCheckoutRefunded( $payload );

		/* No DB updates should happen */
		$updates = \IPS\Db::i()->getOperations( 'update' );
		$this->assertEmpty( $updates );
	}

	public function testRefundMissingCheckoutSessionKeyHandled(): void
	{
		$payload = array( 'event' => 'checkout_session:refunded' );
		/* No checkoutSession key at all */
		$this->controller->testHandleCheckoutRefunded( $payload );
		/* Should not throw */
		$this->assertTrue( true );
	}
}
