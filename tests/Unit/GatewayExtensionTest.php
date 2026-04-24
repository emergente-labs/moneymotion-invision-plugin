<?php
/**
 * Tests for the Gateway extension class
 *
 * Tests: supports(), canStoreCards(), canAdminCharge(), settings(),
 * testSettings(), paymentScreen(), capture(), void(), extraData()
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableGateway;

class GatewayExtensionTest extends TestCase
{
	private TestableGateway $gateway;

	protected function setUp(): void
	{
		\IPS\Settings::reset();
		\IPS\Member::reset();
		\IPS\Output::reset();
		\IPS\Db::reset();
		\IPS\Log::reset();

		$this->gateway = new TestableGateway;
		$this->gateway->id = 1;
		$this->gateway->setTestSettings( array(
			'api_key' => 'mk_live_test123',
			'webhook_secret' => 'whsec_test123',
		));
	}

	/* --- supports() --- */

	public function testSupportsAuth(): void
	{
		$this->assertTrue( $this->gateway->supports( 'auth' ) );
	}

	public function testSupportsCapture(): void
	{
		$this->assertTrue( $this->gateway->supports( 'capture' ) );
	}

	public function testDoesNotSupportRefund(): void
	{
		$this->assertFalse( $this->gateway->supports( 'refund' ) );
	}

	public function testDoesNotSupportSubscription(): void
	{
		$this->assertFalse( $this->gateway->supports( 'subscription' ) );
	}

	public function testDoesNotSupportBillingAgreement(): void
	{
		$this->assertFalse( $this->gateway->supports( 'billing_agreement' ) );
	}

	/* --- canStoreCards() --- */

	public function testCannotStoreCards(): void
	{
		$this->assertFalse( $this->gateway->canStoreCards() );
	}

	public function testCannotStoreCardsAdminOnly(): void
	{
		$this->assertFalse( $this->gateway->canStoreCards( true ) );
	}

	/* --- canAdminCharge() --- */

	public function testCannotAdminCharge(): void
	{
		$customer = new \IPS\nexus\Customer;
		$this->assertFalse( $this->gateway->canAdminCharge( $customer ) );
	}

	/* --- testSettings() validation --- */

	public function testTestSettingsPassesWithValidSettings(): void
	{
		$settings = array(
			'api_key' => 'mk_live_abc123',
			'webhook_secret' => 'whsec_xyz789',
		);
		$result = $this->gateway->testSettings( $settings );
		$this->assertSame( $settings, $result );
	}

	public function testTestSettingsThrowsOnEmptyApiKey(): void
	{
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'API key is required' );
		$this->gateway->testSettings( array( 'api_key' => '', 'webhook_secret' => 'sec' ) );
	}

	public function testTestSettingsThrowsOnMissingApiKey(): void
	{
		$this->expectException( \InvalidArgumentException::class );
		$this->gateway->testSettings( array( 'webhook_secret' => 'sec' ) );
	}

	public function testTestSettingsThrowsOnEmptyWebhookSecret(): void
	{
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Webhook Secret is required' );
		$this->gateway->testSettings( array( 'api_key' => 'mk_live_abc', 'webhook_secret' => '' ) );
	}

	public function testTestSettingsThrowsOnMissingWebhookSecret(): void
	{
		$this->expectException( \InvalidArgumentException::class );
		$this->gateway->testSettings( array( 'api_key' => 'mk_live_abc' ) );
	}

	/* --- capture() --- */

	public function testCaptureIsNoOp(): void
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->id = 100;
		// Should not throw — capture is handled by webhook
		$this->gateway->capture( $txn );
		$this->assertTrue( true );
	}

	/* --- void() --- */

	public function testVoidUpdatesSessions(): void
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->id = 100;

		$this->gateway->void( $txn );

		$updates = \IPS\Db::i()->getOperations( 'update', 'moneymotion_sessions' );
		$this->assertNotEmpty( $updates, 'void() should update moneymotion_sessions' );
		$this->assertSame( 'cancelled', $updates[0]['data']['status'] );
	}

	public function testVoidLogs(): void
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->id = 100;

		$this->gateway->void( $txn );

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'voided' ),
			'void() should log the void action'
		);
	}

	/* --- extraData() --- */

	public function testExtraDataReturnsSessionId(): void
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->gw_id = 'cs_session_abc123';

		$result = $this->gateway->extraData( $txn );
		$this->assertStringContainsString( 'cs_session_abc123', $result );
	}

	public function testExtraDataReturnsEmptyWithoutGwId(): void
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->gw_id = '';

		$result = $this->gateway->extraData( $txn );
		$this->assertSame( '', $result );
	}

	public function testExtraDataReturnsEmptyWithNullGwId(): void
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->gw_id = null;

		$result = $this->gateway->extraData( $txn );
		$this->assertSame( '', $result );
	}

	/* --- CSRF Token in Gateway class --- */

	public function testGatewayCsrfTokenMatchesWebhookCsrfToken(): void
	{
		// Both the gateway and webhook controller generate CSRF tokens
		// with the same algorithm. They must match for return URLs to work.
		$webhookController = new \Tests\Stubs\TestableWebhookController;

		$gatewayToken = $this->gateway->testGenerateCsrfToken( 123, 'success' );
		$webhookToken = $webhookController->testGenerateCsrfToken( 123, 'success' );

		$this->assertSame(
			$gatewayToken,
			$webhookToken,
			'Gateway and webhook CSRF tokens must match for the same transaction/action'
		);
	}
}
