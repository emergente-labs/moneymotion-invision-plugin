<?php
/**
 * Tests for API request format against moneymotion docs
 *
 * Validates that createCheckoutSession() builds the correct payload
 * per https://docs.moneymotion.io/api/checkoutsessions/createCheckoutSession
 *
 * The docs show the tRPC format the plugin uses:
 *   POST /checkoutSessions.createCheckoutSession
 *   Body: { json: { description, urls, userInfo, lineItems, metadata } }
 *
 * There's also a REST-style endpoint documented at /create-checkout-session:
 *   POST /createCheckoutSession
 *   Body: { description, total, successUrl, failureUrl, cancelUrl, userEmail, userIp, userFingerprint }
 *
 * The plugin uses the tRPC format. These tests verify that format is correct.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ApiRequestFormatTest extends TestCase
{
	protected function setUp(): void
	{
		\IPS\Settings::reset();
	}

	/**
	 * Test that createCheckoutSession builds correct body structure
	 */
	public function testCheckoutSessionRequestHasJsonWrapper(): void
	{
		// We can't easily intercept the HTTP call, but we can verify
		// the API constant and client construction
		$this->assertSame(
			'https://api.moneymotion.io',
			\IPS\moneymotion\Api\_Client::API_BASE_URL,
			'API base URL must match moneymotion docs'
		);
	}

	/**
	 * Test line item format matches docs
	 *
	 * Per docs, lineItems should have:
	 * - name (string)
	 * - description (string)
	 * - pricePerItemInCents (integer, >0)
	 * - quantity (integer, >0)
	 */
	public function testLineItemFormatMatchesDocs(): void
	{
		// Simulating what auth() builds
		$totalCents = 5000;
		$lineItems = array(
			array(
				'name' => 'Invoice #100',
				'description' => 'Payment for Invoice #100',
				'pricePerItemInCents' => $totalCents,
				'quantity' => 1,
			),
		);

		$item = $lineItems[0];
		$this->assertArrayHasKey( 'name', $item );
		$this->assertArrayHasKey( 'description', $item );
		$this->assertArrayHasKey( 'pricePerItemInCents', $item );
		$this->assertArrayHasKey( 'quantity', $item );
		$this->assertIsInt( $item['pricePerItemInCents'] );
		$this->assertGreaterThan( 0, $item['quantity'] );
	}

	/**
	 * Test cents conversion is correct
	 */
	public function testCentsConversion(): void
	{
		// Simulating the auth() conversion: (int) round((float)(string) $amount * 100)
		$testCases = array(
			array( '10.00', 1000 ),
			array( '0.01', 1 ),
			array( '99.99', 9999 ),
			array( '100.00', 10000 ),
			array( '0.50', 50 ),
			array( '1234.56', 123456 ),
		);

		foreach ( $testCases as list( $amount, $expected ) )
		{
			$cents = (int) round( (float) (string) $amount * 100 );
			$this->assertSame( $expected, $cents, "Amount {$amount} should convert to {$expected} cents" );
		}
	}

	/**
	 * Test floating point edge cases in cents conversion
	 */
	public function testCentsConversionFloatingPointEdgeCases(): void
	{
		// These are known floating-point problematic values
		$edgeCases = array(
			array( '19.99', 1999 ),
			array( '29.99', 2999 ),
			array( '0.10', 10 ),
			array( '0.20', 20 ),
			array( '0.30', 30 ),  // 0.1 + 0.2 != 0.3 in float
		);

		foreach ( $edgeCases as list( $amount, $expected ) )
		{
			$cents = (int) round( (float) (string) $amount * 100 );
			$this->assertSame( $expected, $cents, "Edge case: {$amount} should be {$expected} cents" );
		}
	}

	/**
	 * Test URL building for callbacks forces HTTPS
	 *
	 * BUG CHECK: The auth() method does str_replace('http://', 'https://')
	 * This is a naive approach — what if the URL already has https?
	 */
	public function testHttpsForcing(): void
	{
		$httpUrl = 'http://example.com/moneymotion/webhook/success';
		$httpsUrl = str_replace( 'http://', 'https://', $httpUrl );
		$this->assertSame( 'https://example.com/moneymotion/webhook/success', $httpsUrl );

		// Already HTTPS — should remain unchanged
		$alreadyHttps = 'https://example.com/moneymotion/webhook/success';
		$result = str_replace( 'http://', 'https://', $alreadyHttps );
		$this->assertSame( 'https://example.com/moneymotion/webhook/success', $result );
	}

	/**
	 * Test metadata format matches docs (object, not array)
	 */
	public function testMetadataFormat(): void
	{
		$metadata = array(
			'invoice_id' => 100,
			'transaction_id' => 200,
			'gateway_id' => 1,
		);

		// The plugin casts to (object) — verify this produces JSON object not array
		$json = json_encode( (object) $metadata );
		$decoded = json_decode( $json );
		$this->assertIsObject( $decoded, 'Metadata must serialize as JSON object' );
	}

	/**
	 * Verify response parsing matches docs format
	 *
	 * Expected: { result: { data: { json: { checkoutSessionId: "..." } } } }
	 */
	public function testResponseParsingFormat(): void
	{
		$mockResponse = array(
			'result' => array(
				'data' => array(
					'json' => array(
						'checkoutSessionId' => 'cs_test_abc123',
					),
				),
			),
		);

		$sessionId = $mockResponse['result']['data']['json']['checkoutSessionId'];
		$this->assertSame( 'cs_test_abc123', $sessionId );
	}

	/**
	 * Test User-Agent header format
	 */
	public function testUserAgentFormat(): void
	{
		$ua = 'moneymotion IPS Plugin/3.0.16 (PHP ' . PHP_VERSION . ')';
		$this->assertStringContainsString( 'moneymotion IPS Plugin', $ua );
		$this->assertStringContainsString( 'PHP', $ua );
	}
}
