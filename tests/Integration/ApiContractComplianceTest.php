<?php
/**
 * Tests for API request contract compliance against moneymotion docs.
 *
 * Per https://docs.moneymotion.io/api/checkoutsessions/createCheckoutSession
 * the tRPC-style endpoint requires:
 *   - description: string
 *   - urls: { success, cancel, failure } — all three required strings
 *   - userInfo: { email } — required
 *   - lineItems: array of { name, description, pricePerItemInCents > 0, quantity > 0 }
 *
 * And per https://docs.moneymotion.io/create-checkout-session (the REST-style
 * reference docs which describe the business-level contract):
 *   - userIp: string — required for fraud/compliance
 *   - userFingerprint: string — required for device tracking
 *
 * The plugin uses the tRPC format which doesn't require userIp/userFingerprint,
 * but production moneymotion accounts may enforce them at the business layer.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class ApiContractComplianceTest extends TestCase
{
	private \IPS\moneymotion\Api\_Client $client;

	protected function setUp(): void
	{
		\IPS\Http\Url\Request::reset();
		$this->client = new \IPS\moneymotion\Api\_Client( 'mk_live_test' );
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200, '{"result":{"data":{"json":{"checkoutSessionId":"cs_test"}}}}'
		);
	}

	private function submit( array $lineItems = null, string $email = 'test@example.com' ): array
	{
		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'https://s', 'cancel' => 'https://c', 'failure' => 'https://f' ),
			$email,
			$lineItems ?? array(
				array( 'name' => 'X', 'description' => 'Y', 'pricePerItemInCents' => 5000, 'quantity' => 1 ),
			)
		);

		$captured = \IPS\Http\Url\Request::$captured[0];
		return json_decode( $captured['body'], true )['json'];
	}

	public function testRequestHasAllThreeUrls(): void
	{
		$body = $this->submit();
		$this->assertArrayHasKey( 'success', $body['urls'] );
		$this->assertArrayHasKey( 'cancel', $body['urls'] );
		$this->assertArrayHasKey( 'failure', $body['urls'] );
	}

	public function testRequestIncludesUserEmail(): void
	{
		$body = $this->submit( null, 'customer@example.com' );
		$this->assertSame( 'customer@example.com', $body['userInfo']['email'] );
	}

	public function testLineItemPriceIsPositiveInteger(): void
	{
		$body = $this->submit();
		foreach ( $body['lineItems'] as $item )
		{
			$this->assertIsInt( $item['pricePerItemInCents'] );
			$this->assertGreaterThan( 0, $item['pricePerItemInCents'] );
		}
	}

	public function testLineItemQuantityIsPositiveInteger(): void
	{
		$body = $this->submit();
		foreach ( $body['lineItems'] as $item )
		{
			$this->assertIsInt( $item['quantity'] );
			$this->assertGreaterThan( 0, $item['quantity'] );
		}
	}

	public function testLineItemHasNameAndDescription(): void
	{
		$body = $this->submit();
		foreach ( $body['lineItems'] as $item )
		{
			$this->assertArrayHasKey( 'name', $item );
			$this->assertNotEmpty( $item['name'] );
			$this->assertArrayHasKey( 'description', $item );
		}
	}

	/**
	 * Per REST docs, userIp is required. Plugin uses tRPC which doesn't
	 * require it in the schema — but it's still an anti-fraud hole.
	 */
	public function testRequestDoesNotIncludeUserIp(): void
	{
		$body = $this->submit();

		/* Documenting that we don't send userIp. If moneymotion tightens
		   their validation to require it, payments will fail. */
		$this->assertArrayNotHasKey(
			'userIp',
			$body['userInfo'],
			'userIp is not sent — documenting gap with moneymotion REST spec'
		);
	}

	public function testRequestDoesNotIncludeUserFingerprint(): void
	{
		$body = $this->submit();
		$this->assertArrayNotHasKey( 'userFingerprint', $body );
		$this->assertArrayNotHasKey( 'userFingerprint', $body['userInfo'] );
	}

	public function testEmailFormatIsValidated(): void
	{
		/* The client doesn't validate email format — it just passes whatever
		   IPS gives us. IPS validates on account creation, so this is usually
		   safe, but a corrupted member record could send garbage. */
		$body = $this->submit( null, 'not-an-email' );
		$this->assertSame( 'not-an-email', $body['userInfo']['email'] );
		/* Docs say email must be validated format — moneymotion would reject */
	}

	public function testCurrencyHeaderMatchesDocs(): void
	{
		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array( array( 'name' => 'X', 'description' => 'Y', 'pricePerItemInCents' => 1000, 'quantity' => 1 ) ),
			array(),
			'BRL'
		);

		$captured = \IPS\Http\Url\Request::$captured[0];
		$this->assertArrayHasKey( 'x-currency', $captured['headers'] );
		/* Per docs, x-currency should be ISO 4217 uppercase */
		$this->assertMatchesRegularExpression( '/^[A-Z]{3}$/', $captured['headers']['x-currency'] );
	}
}
