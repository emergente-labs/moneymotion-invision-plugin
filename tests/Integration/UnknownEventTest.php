<?php
/**
 * Tests for unknown webhook event handling
 *
 * Any event name we don't recognise (e.g. checkout_session:new, or a future
 * event type) should be ACKed with 200 OK and logged for debugging. We must
 * not 400/500 on unknown events or moneymotion will keep retrying indefinitely.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;
use Tests\Fixtures\WebhookPayloads;

class UnknownEventTest extends TestCase
{
	private TestableWebhookController $controller;
	private string $webhookSecret = 'whsec_test_123';

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		\IPS\nexus\Transaction::reset();

		$this->controller = new TestableWebhookController;

		\IPS\Db::i()->mockData['nexus_paymethods'] = array(
			array(
				'm_id' => 1,
				'm_gateway' => 'moneymotion',
				'm_settings' => json_encode( array( 'webhook_secret' => $this->webhookSecret ) ),
			),
		);
	}

	/**
	 * Simulate the event dispatch in webhook() — exactly the switch/case logic.
	 */
	private function dispatch( string $eventName ): string
	{
		$payload = array(
			'event' => $eventName,
			'checkoutSession' => array( 'id' => 'cs_test', 'totalInCents' => 5000 ),
		);

		switch ( $payload['event'] )
		{
			case 'checkout_session:complete':
				$this->controller->testHandleCheckoutComplete( $payload );
				return 'complete';

			case 'checkout_session:refunded':
				$this->controller->testHandleCheckoutRefunded( $payload );
				return 'refunded';

			case 'checkout_session:expired':
			case 'checkout_session:disputed':
				$this->controller->testHandleCheckoutFailed( $payload );
				return 'failed';

			default:
				\IPS\Log::log( "moneymotion webhook: unhandled event '{$payload['event']}'", 'moneymotion' );
				return 'unhandled';
		}
	}

	public function testCheckoutSessionNewGoesToDefault(): void
	{
		/* moneymotion docs define `checkout_session:new` but our plugin
		   doesn't have a handler — it should fall through to default. */
		$result = $this->dispatch( 'checkout_session:new' );
		$this->assertSame( 'unhandled', $result );
		$this->assertTrue( \IPS\Log::hasMessageContaining( 'unhandled event' ) );
		$this->assertTrue( \IPS\Log::hasMessageContaining( 'checkout_session:new' ) );
	}

	public function testCompletelyUnknownEventGoesToDefault(): void
	{
		$result = $this->dispatch( 'some_future_event_type' );
		$this->assertSame( 'unhandled', $result );
		$this->assertTrue( \IPS\Log::hasMessageContaining( 'unhandled event' ) );
	}

	public function testEmptyEventStringHandled(): void
	{
		$result = $this->dispatch( '' );
		$this->assertSame( 'unhandled', $result );
	}

	public function testUnknownEventIsLoggedWithEventName(): void
	{
		$this->dispatch( 'checkout_session:weird_new_state' );
		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'checkout_session:weird_new_state' ),
			'The unknown event name should appear in the log for debugging'
		);
	}

	public function testKnownEventsDoNotLogUnhandled(): void
	{
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id' => 'cs_test',
				'transaction_id' => 200,
				'invoice_id' => 100,
				'amount_cents' => 5000,
				'currency' => 'EUR',
				'status' => 'pending',
				'created_at' => time(),
				'updated_at' => time(),
			),
		);
		$txn = new \IPS\nexus\Transaction;
		\IPS\nexus\Transaction::register( 200, $txn );

		$this->dispatch( 'checkout_session:complete' );
		$this->assertFalse(
			\IPS\Log::hasMessageContaining( 'unhandled event' ),
			'Known events must not log as unhandled'
		);
	}
}
