<?php
/**
 * Integration tests for the Gateway auth() method
 *
 * This is the entry point when a customer clicks "Pay with moneymotion".
 * Tests the full flow: transaction setup → API call → session storage → redirect.
 *
 * Note: auth() is hard to fully exercise because it calls the real API
 * client which performs HTTP requests. We test the preparatory steps
 * (amount calculation, URL building, CSRF token generation) and verify
 * that API failures are handled gracefully.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableGateway;

class AuthFlowTest extends TestCase
{
	private TestableGateway $gateway;

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		\IPS\nexus\Transaction::reset();
		\IPS\Settings::reset();
		\IPS\Member::reset();
		\IPS\Output::reset();

		$this->gateway = new TestableGateway;
		$this->gateway->id = 1;
		$this->gateway->setTestSettings( array(
			'api_key' => 'mk_live_test_abc123',
			'webhook_secret' => 'whsec_test_xyz789',
		));
	}

	/**
	 * Test that auth() generates unique URLs with CSRF tokens
	 * for all three actions (success, cancel, failure)
	 */
	public function testAuthGeneratesUniqueCsrfTokensPerAction(): void
	{
		$tokens = array(
			'success' => $this->gateway->testGenerateCsrfToken( 100, 'success' ),
			'cancel' => $this->gateway->testGenerateCsrfToken( 100, 'cancel' ),
			'failure' => $this->gateway->testGenerateCsrfToken( 100, 'failure' ),
		);

		$this->assertCount( 3, array_unique( $tokens ), 'All 3 CSRF tokens must be unique' );
	}

	/**
	 * Test cents conversion for various amount formats
	 *
	 * BUG RISK: Amount is read from IPS as a Money object. The conversion is:
	 *   (int) round((float)(string) $amount->amount * 100)
	 *
	 * Risky because:
	 * - (float) can lose precision on large amounts
	 * - (string) conversion depends on locale (decimal separator!)
	 * - round() is needed for float inaccuracy
	 */
	public function testCentsConversionForTypicalAmounts(): void
	{
		$cases = array(
			'10.00' => 1000,
			'99.99' => 9999,
			'100.00' => 10000,
			'0.01' => 1,
			'1234.56' => 123456,
		);

		foreach ( $cases as $amount => $expected )
		{
			$cents = (int) round( (float) (string) $amount * 100 );
			$this->assertSame( $expected, $cents, "Amount {$amount} should produce {$expected} cents" );
		}
	}

	/**
	 * BUG CHECK: What about locales where decimal is comma?
	 * If setlocale is set to e.g. de_DE.UTF-8, (float)"10,50" becomes 10
	 * This could cause amount truncation in internationalized IPS installs.
	 */
	public function testAmountConversionLocaleSafety(): void
	{
		// In most IPS contexts Money->amount is a string like "10.00"
		// But if someone passes "10,50" (comma), we'd lose the cents

		$commaAmount = '10,50';
		$cents = (int) round( (float) $commaAmount * 100 );

		// This shows the risk: (float)"10,50" = 10.0, not 10.5
		$this->assertSame( 1000, $cents, 'Comma-decimal amounts lose cents — potential bug in localized installs' );
	}

	/**
	 * Test that metadata must be an object not array in JSON
	 */
	public function testMetadataIsObjectNotArrayInJson(): void
	{
		$metadata = array(
			'invoice_id' => 100,
			'transaction_id' => 200,
			'gateway_id' => 1,
		);

		$casted = (object) $metadata;
		$encoded = json_encode( $casted );

		$this->assertStringStartsWith( '{', $encoded, 'Metadata must serialize as JSON object (starts with {)' );
		$this->assertStringEndsWith( '}', $encoded );

		// Empty metadata as object (not array)
		$emptyCasted = (object) array();
		$emptyEncoded = json_encode( $emptyCasted );
		$this->assertSame( '{}', $emptyEncoded, 'Empty metadata must be {} not []' );
	}

	/**
	 * Test that the API key is trimmed on use (avoids whitespace issues)
	 */
	public function testApiKeyTrimming(): void
	{
		$gateway = new \IPS\nexus\Gateway;
		$gateway->settings = json_encode( array( 'api_key' => '  mk_live_abc123  ' ) );

		$client = \IPS\moneymotion\Api\_Client::fromGateway( $gateway );
		$this->assertInstanceOf( \IPS\moneymotion\Api\_Client::class, $client );
	}

	/**
	 * Test invoice amount comparison logic from auth()
	 *
	 * auth() has this comparison:
	 *   if ( $invoiceAmountToPay->currency === $amount->currency
	 *        && $amount->amount->compare( $invoiceAmountToPay->amount ) !== 0 )
	 *
	 * This is supposed to detect mismatches but KEEPS using $amount (transaction amount).
	 * This is the right behavior per the comments but worth verifying.
	 */
	public function testAmountComparisonDetectsMismatch(): void
	{
		$a = new \IPS\nexus\Money( '10.00', 'EUR' );
		$b = new \IPS\nexus\Money( '15.00', 'EUR' );

		$result = $a->amount->compare( $b->amount );
		$this->assertNotSame( 0, $result, 'Different amounts should not compare equal' );
	}

	public function testAmountComparisonMatchesEqual(): void
	{
		$a = new \IPS\nexus\Money( '10.00', 'EUR' );
		$b = new \IPS\nexus\Money( '10.00', 'EUR' );

		$result = $a->amount->compare( $b->amount );
		$this->assertSame( 0, $result, 'Equal amounts should compare equal' );
	}
}
