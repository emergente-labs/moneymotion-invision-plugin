<?php
/**
 * Tests for extractPaidAmountCents()
 *
 * This method extracts the paid amount from the webhook checkoutSession object.
 * Per moneymotion API docs, the field is "totalInCents".
 *
 * BUG FOUND: The current code checks for amountInCents, amount_cents,
 * amountCents, totalAmountInCents, total_amount_cents — but NOT totalInCents.
 * This causes NULL return → approval blocked → "takes payment, doesn't give product"
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;

class ExtractPaidAmountCentsTest extends TestCase
{
	private TestableWebhookController $controller;

	protected function setUp(): void
	{
		$this->controller = new TestableWebhookController;
	}

	/**
	 * CRITICAL: This is the actual field moneymotion sends per their docs.
	 * This test FAILS on current code — proving the bug.
	 */
	public function testExtractsFromTotalInCents(): void
	{
		$session = array( 'totalInCents' => 5000 );
		$result = $this->controller->testExtractPaidAmountCents( $session );
		$this->assertSame( 5000, $result, 'Must extract totalInCents — the actual moneymotion webhook field' );
	}

	/**
	 * Tests for the currently checked fields (should still work after fix)
	 */
	public function testExtractsFromAmountInCents(): void
	{
		$session = array( 'amountInCents' => 3000 );
		$result = $this->controller->testExtractPaidAmountCents( $session );
		$this->assertSame( 3000, $result );
	}

	public function testExtractsFromAmountCents(): void
	{
		$session = array( 'amount_cents' => 2500 );
		$result = $this->controller->testExtractPaidAmountCents( $session );
		$this->assertSame( 2500, $result );
	}

	public function testExtractsFromAmountCentsCamelCase(): void
	{
		$session = array( 'amountCents' => 1000 );
		$result = $this->controller->testExtractPaidAmountCents( $session );
		$this->assertSame( 1000, $result );
	}

	public function testExtractsFromTotalAmountInCents(): void
	{
		$session = array( 'totalAmountInCents' => 7500 );
		$result = $this->controller->testExtractPaidAmountCents( $session );
		$this->assertSame( 7500, $result );
	}

	public function testExtractsFromTotalAmountCentsSnakeCase(): void
	{
		$session = array( 'total_amount_cents' => 9000 );
		$result = $this->controller->testExtractPaidAmountCents( $session );
		$this->assertSame( 9000, $result );
	}

	/**
	 * Priority: totalInCents should win when multiple fields present
	 */
	public function testTotalInCentsTakesPriority(): void
	{
		$session = array(
			'totalInCents' => 5000,
			'amountInCents' => 9999,
		);
		$result = $this->controller->testExtractPaidAmountCents( $session );
		$this->assertSame( 5000, $result, 'totalInCents (the real field) should take priority' );
	}

	/**
	 * Fallback to lineItems sum
	 */
	public function testExtractsFromLineItems(): void
	{
		$session = array(
			'lineItems' => array(
				array( 'pricePerItemInCents' => 2000, 'quantity' => 2 ),
				array( 'pricePerItemInCents' => 1000, 'quantity' => 1 ),
			),
		);
		$result = $this->controller->testExtractPaidAmountCents( $session );
		$this->assertSame( 5000, $result );
	}

	public function testLineItemsDefaultQuantityToOne(): void
	{
		$session = array(
			'lineItems' => array(
				array( 'pricePerItemInCents' => 3000 ),
			),
		);
		$result = $this->controller->testExtractPaidAmountCents( $session );
		$this->assertSame( 3000, $result );
	}

	public function testReturnsNullWhenNoFieldsPresent(): void
	{
		$session = array( 'id' => 'cs_123', 'status' => 'completed' );
		$result = $this->controller->testExtractPaidAmountCents( $session );
		$this->assertNull( $result );
	}

	public function testReturnsNullForEmptySession(): void
	{
		$result = $this->controller->testExtractPaidAmountCents( array() );
		$this->assertNull( $result );
	}

	public function testIgnoresNonNumericValues(): void
	{
		$session = array( 'totalInCents' => 'not_a_number' );
		$result = $this->controller->testExtractPaidAmountCents( $session );
		$this->assertNull( $result );
	}

	public function testHandlesZeroAmount(): void
	{
		$session = array( 'totalInCents' => 0 );
		$result = $this->controller->testExtractPaidAmountCents( $session );
		$this->assertSame( 0, $result );
	}

	public function testHandlesStringNumericAmount(): void
	{
		$session = array( 'totalInCents' => '5000' );
		$result = $this->controller->testExtractPaidAmountCents( $session );
		$this->assertSame( 5000, $result );
	}

	/**
	 * Real moneymotion webhook payload structure from docs
	 */
	public function testRealMoneymotionWebhookPayload(): void
	{
		$payload = \Tests\Fixtures\WebhookPayloads::completeReal( 'cs_test', 4200 );
		$checkoutSession = $payload['checkoutSession'];
		$result = $this->controller->testExtractPaidAmountCents( $checkoutSession );
		$this->assertSame( 4200, $result, 'Must work with real moneymotion webhook payload' );
	}
}
