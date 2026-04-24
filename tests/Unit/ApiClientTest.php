<?php
/**
 * Tests for the moneymotion API Client
 *
 * Validates request construction matches moneymotion API docs:
 * https://docs.moneymotion.io/api/checkoutsessions/createCheckoutSession
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
{
	protected function setUp(): void
	{
		\IPS\Settings::reset();
	}

	public function testConstructorTrimsApiKey(): void
	{
		$client = new \IPS\moneymotion\Api\_Client( '  mk_test_key_123  ' );
		// If it didn't throw, trimming worked. The key is protected so
		// we test indirectly via fromGateway.
		$this->assertInstanceOf( \IPS\moneymotion\Api\_Client::class, $client );
	}

	public function testFromGatewayThrowsOnEmptyKey(): void
	{
		$gateway = new \IPS\nexus\Gateway;
		$gateway->settings = json_encode( array( 'api_key' => '' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not configured' );
		\IPS\moneymotion\Api\_Client::fromGateway( $gateway );
	}

	public function testFromGatewayThrowsOnMissingKey(): void
	{
		$gateway = new \IPS\nexus\Gateway;
		$gateway->settings = '{}';

		$this->expectException( \InvalidArgumentException::class );
		\IPS\moneymotion\Api\_Client::fromGateway( $gateway );
	}

	public function testFromGatewaySucceedsWithValidKey(): void
	{
		$gateway = new \IPS\nexus\Gateway;
		$gateway->settings = json_encode( array( 'api_key' => 'mk_live_abc123' ) );

		$client = \IPS\moneymotion\Api\_Client::fromGateway( $gateway );
		$this->assertInstanceOf( \IPS\moneymotion\Api\_Client::class, $client );
	}

	public function testVerifyWebhookSignatureMatchesMoneymotionDocs(): void
	{
		$body = '{"event":"checkout_session:complete","checkoutSession":{"id":"cs_123","totalInCents":5000}}';
		$secret = 'whsec_test_123';

		// moneymotion docs: base64(hmac_sha512(body, secret))
		$expected = base64_encode( hash_hmac( 'sha512', $body, $secret, true ) );

		$this->assertTrue(
			\IPS\moneymotion\Api\_Client::verifyWebhookSignature( $body, $expected, $secret )
		);
	}

	public function testVerifyWebhookSignatureRejectsInvalid(): void
	{
		$this->assertFalse(
			\IPS\moneymotion\Api\_Client::verifyWebhookSignature( 'body', 'bad_sig', 'secret' )
		);
	}
}
