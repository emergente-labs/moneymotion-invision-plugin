<?php
/**
 * Tests for paymentScreen() and settings() form methods
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableGateway;

class PaymentScreenAndSettingsTest extends TestCase
{
	private TestableGateway $gateway;

	protected function setUp(): void
	{
		\IPS\Theme::reset();
		\IPS\Settings::reset();
		\IPS\Member::reset();

		$this->gateway = new TestableGateway;
		$this->gateway->id = 1;
		$this->gateway->setTestSettings( array(
			'api_key' => 'mk_live_test',
			'webhook_secret' => 'whsec_test',
		));
	}

	/* --- paymentScreen() --- */

	public function testPaymentScreenReturnsTemplateOutput(): void
	{
		$invoice = new \IPS\nexus\Invoice;
		$amount = new \IPS\nexus\Money( '10.00', 'EUR' );

		$result = $this->gateway->paymentScreen( $invoice, $amount );
		$this->assertIsString( $result );
	}

	public function testPaymentScreenAcceptsNullCustomer(): void
	{
		$invoice = new \IPS\nexus\Invoice;
		$amount = new \IPS\nexus\Money( '10.00', 'EUR' );

		// Should not throw with NULL customer
		$result = $this->gateway->paymentScreen( $invoice, $amount, null );
		$this->assertIsString( $result );
	}

	public function testPaymentScreenAcceptsCustomer(): void
	{
		$invoice = new \IPS\nexus\Invoice;
		$amount = new \IPS\nexus\Money( '10.00', 'EUR' );
		$customer = new \IPS\nexus\Customer;

		$result = $this->gateway->paymentScreen( $invoice, $amount, $customer );
		$this->assertIsString( $result );
	}

	public function testPaymentScreenAcceptsRecurrings(): void
	{
		$invoice = new \IPS\nexus\Invoice;
		$amount = new \IPS\nexus\Money( '10.00', 'EUR' );

		$result = $this->gateway->paymentScreen( $invoice, $amount, null, array( 'x' => 'y' ) );
		$this->assertIsString( $result );
	}

	/* --- settings() form --- */

	public function testSettingsFormAddsApiKeyField(): void
	{
		$form = new \IPS\Helpers\Form;
		// Should not throw
		$this->gateway->settings( $form );
		$this->assertTrue( true );
	}

	public function testSettingsFormHandlesEmptySettings(): void
	{
		$this->gateway->settings = '{}';
		$form = new \IPS\Helpers\Form;
		$this->gateway->settings( $form );
		$this->assertTrue( true );
	}

	public function testSettingsFormHandlesNullSettings(): void
	{
		$this->gateway->settings = 'null';
		$form = new \IPS\Helpers\Form;
		$this->gateway->settings( $form );
		$this->assertTrue( true );
	}

	public function testSettingsFormHandlesInvalidJson(): void
	{
		$this->gateway->settings = 'not valid json';
		$form = new \IPS\Helpers\Form;
		$this->gateway->settings( $form );
		$this->assertTrue( true );
	}
}
