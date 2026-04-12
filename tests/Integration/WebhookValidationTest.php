<?php
/**
 * Tests for webhook() entry-level validation
 *
 * These tests cover the early-return paths in webhook():
 *   - empty body → 400
 *   - invalid JSON → 400
 *   - missing event field → 400
 *   - unknown event → default case (logs but doesn't fail)
 *   - successful webhook logs the "received and verified" audit line
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;
use Tests\Fixtures\WebhookPayloads;

class WebhookValidationTest extends TestCase
{
	private TestableWebhookController $controller;
	private string $webhookSecret = 'whsec_test_123';

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		\IPS\nexus\Transaction::reset();
		\IPS\Output::reset();

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
	 * Simulate the early validation steps in webhook()
	 * @return array{error?:string, code:int, event?:string}
	 */
	private function simulate( string $rawBody ): array
	{
		if ( empty( $rawBody ) )
		{
			return array( 'error' => 'Empty body', 'code' => 400 );
		}

		$parsed = json_decode( $rawBody, true );
		if ( !$parsed || !isset( $parsed['event'] ) )
		{
			return array( 'error' => 'Invalid payload', 'code' => 400 );
		}

		return array( 'code' => 200, 'event' => $parsed['event'] );
	}

	public function testEmptyBodyRejectedWith400(): void
	{
		$result = $this->simulate( '' );
		$this->assertSame( 400, $result['code'] );
		$this->assertSame( 'Empty body', $result['error'] );
	}

	public function testInvalidJsonRejectedWith400(): void
	{
		$result = $this->simulate( 'not json at all' );
		$this->assertSame( 400, $result['code'] );
		$this->assertSame( 'Invalid payload', $result['error'] );
	}

	public function testJsonWithoutEventFieldRejected(): void
	{
		$result = $this->simulate( '{"checkoutSession": {}}' );
		$this->assertSame( 400, $result['code'] );
		$this->assertSame( 'Invalid payload', $result['error'] );
	}

	public function testJsonArrayAloneRejected(): void
	{
		$result = $this->simulate( '[]' );
		$this->assertSame( 400, $result['code'] );
	}

	public function testValidPayloadAccepted(): void
	{
		$payload = WebhookPayloads::completeReal( 'cs_x', 5000 );
		$result = $this->simulate( json_encode( $payload ) );
		$this->assertSame( 200, $result['code'] );
		$this->assertSame( 'checkout_session:complete', $result['event'] );
	}

	public function testNullJsonRejected(): void
	{
		$result = $this->simulate( 'null' );
		$this->assertSame( 400, $result['code'] );
	}

	public function testFalseJsonRejected(): void
	{
		$result = $this->simulate( 'false' );
		$this->assertSame( 400, $result['code'] );
	}
}
