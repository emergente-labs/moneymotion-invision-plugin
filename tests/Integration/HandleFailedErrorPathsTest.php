<?php
/**
 * Tests for handleCheckoutFailed() error paths
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;
use Tests\Fixtures\WebhookPayloads;

class HandleFailedErrorPathsTest extends TestCase
{
	private TestableWebhookController $controller;

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		$this->controller = new TestableWebhookController;
	}

	public function testFailedEmptyCheckoutSessionArrayReturnsEarly(): void
	{
		$payload = array(
			'event' => 'checkout_session:expired',
			'checkoutSession' => array(),
		);

		$this->controller->testHandleCheckoutFailed( $payload );

		$updates = \IPS\Db::i()->getOperations( 'update' );
		$this->assertEmpty( $updates, 'No DB updates should happen without session ID' );
	}

	public function testFailedMissingCheckoutSessionKeyReturnsEarly(): void
	{
		$payload = array( 'event' => 'checkout_session:expired' );

		$this->controller->testHandleCheckoutFailed( $payload );

		$updates = \IPS\Db::i()->getOperations( 'update' );
		$this->assertEmpty( $updates );
	}

	public function testFailedWithNonexistentSessionStillUpdates(): void
	{
		/* handleCheckoutFailed just does a blind UPDATE by session_id — if
		   no rows match, the DB update is still issued (affects 0 rows). */
		$payload = WebhookPayloads::expired( 'cs_ghost' );
		$this->controller->testHandleCheckoutFailed( $payload );

		$updates = \IPS\Db::i()->getOperations( 'update', 'moneymotion_sessions' );
		$this->assertNotEmpty( $updates, 'UPDATE should still be attempted' );
		$this->assertSame( 'failed', $updates[0]['data']['status'] );
	}
}
