<?php
/**
 * Tests for extractPaidCurrency()
 *
 * BUG FOUND: moneymotion webhook checkoutSession does NOT include a currency
 * field. The currency is only available via the getCheckoutSessionInfo API
 * under totalPrice.currency. The webhook only sends totalInCents.
 *
 * This means extractPaidCurrency() ALWAYS returns NULL for real webhooks,
 * which blocks approval on line 197-205 of webhook.php.
 *
 * FIX: When the webhook doesn't provide currency, we should trust the
 * currency stored in moneymotion_sessions (set at checkout creation time)
 * rather than requiring it from the webhook.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;

class ExtractPaidCurrencyTest extends TestCase
{
	private TestableWebhookController $controller;

	protected function setUp(): void
	{
		$this->controller = new TestableWebhookController;
	}

	/**
	 * CRITICAL: Real moneymotion webhook has NO currency field in checkoutSession.
	 * This test documents the current (broken) behavior.
	 */
	public function testReturnsNullForRealMoneymotionWebhook(): void
	{
		// Real webhook checkoutSession per docs — no currency field
		$session = array(
			'id' => 'cs_test_123',
			'createdByCustomerId' => 'cust_abc',
			'status' => 'completed',
			'totalInCents' => 5000,
			'metadata' => (object) array(),
			'storeId' => 'store_001',
		);
		$result = $this->controller->testExtractPaidCurrency( $session );
		// Current behavior: returns NULL → blocks approval → BUG
		// After fix: should handle missing currency gracefully
		$this->assertNull( $result, 'Real MM webhook has no currency — this NULL causes the bug' );
	}

	/**
	 * Extracts from 'currency' key
	 */
	public function testExtractsFromCurrency(): void
	{
		$session = array( 'currency' => 'EUR' );
		$result = $this->controller->testExtractPaidCurrency( $session );
		$this->assertSame( 'EUR', $result );
	}

	/**
	 * Extracts from 'currencyCode' key
	 */
	public function testExtractsFromCurrencyCode(): void
	{
		$session = array( 'currencyCode' => 'BRL' );
		$result = $this->controller->testExtractPaidCurrency( $session );
		$this->assertSame( 'BRL', $result );
	}

	/**
	 * Extracts from 'currency_code' key
	 */
	public function testExtractsFromCurrencyCodeSnakeCase(): void
	{
		$session = array( 'currency_code' => 'USD' );
		$result = $this->controller->testExtractPaidCurrency( $session );
		$this->assertSame( 'USD', $result );
	}

	public function testReturnsNullForEmptySession(): void
	{
		$result = $this->controller->testExtractPaidCurrency( array() );
		$this->assertNull( $result );
	}

	public function testIgnoresEmptyStrings(): void
	{
		$session = array( 'currency' => '' );
		$result = $this->controller->testExtractPaidCurrency( $session );
		$this->assertNull( $result );
	}

	public function testIgnoresNonScalarValues(): void
	{
		$session = array( 'currency' => array( 'code' => 'EUR' ) );
		$result = $this->controller->testExtractPaidCurrency( $session );
		$this->assertNull( $result );
	}

	/**
	 * Full real webhook payload from fixture
	 */
	public function testRealCompleteWebhookPayload(): void
	{
		$payload = \Tests\Fixtures\WebhookPayloads::completeReal();
		$checkoutSession = $payload['checkoutSession'];
		$result = $this->controller->testExtractPaidCurrency( $checkoutSession );
		// Real webhook has no currency — this is the bug
		$this->assertNull( $result, 'Real webhook has no currency field' );
	}
}
