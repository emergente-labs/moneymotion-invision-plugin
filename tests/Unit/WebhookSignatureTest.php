<?php
/**
 * Tests for verifyWebhookSignature()
 *
 * Per moneymotion docs: HMAC-SHA512, base64 encoded.
 * crypto.createHmac("sha512", secret).update(rawBody).digest("base64")
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;

class WebhookSignatureTest extends TestCase
{
	private TestableWebhookController $controller;
	private string $secret = 'whsec_test_secret_key_123';

	protected function setUp(): void
	{
		$this->controller = new TestableWebhookController;
	}

	public function testValidSignatureAccepted(): void
	{
		$body = '{"event":"checkout_session:complete","checkoutSession":{"id":"cs_123"}}';
		$signature = base64_encode( hash_hmac( 'sha512', $body, $this->secret, true ) );

		$this->assertTrue(
			$this->controller->testVerifyWebhookSignature( $body, $signature, $this->secret )
		);
	}

	public function testInvalidSignatureRejected(): void
	{
		$body = '{"event":"checkout_session:complete"}';
		$signature = 'totally_wrong_signature';

		$this->assertFalse(
			$this->controller->testVerifyWebhookSignature( $body, $signature, $this->secret )
		);
	}

	public function testTamperedBodyRejected(): void
	{
		$originalBody = '{"event":"checkout_session:complete","checkoutSession":{"totalInCents":5000}}';
		$signature = base64_encode( hash_hmac( 'sha512', $originalBody, $this->secret, true ) );

		$tamperedBody = '{"event":"checkout_session:complete","checkoutSession":{"totalInCents":1}}';

		$this->assertFalse(
			$this->controller->testVerifyWebhookSignature( $tamperedBody, $signature, $this->secret )
		);
	}

	public function testWrongSecretRejected(): void
	{
		$body = '{"event":"checkout_session:complete"}';
		$signature = base64_encode( hash_hmac( 'sha512', $body, $this->secret, true ) );

		$this->assertFalse(
			$this->controller->testVerifyWebhookSignature( $body, $signature, 'wrong_secret' )
		);
	}

	public function testEmptyBodyHandled(): void
	{
		$body = '';
		$signature = base64_encode( hash_hmac( 'sha512', $body, $this->secret, true ) );

		$this->assertTrue(
			$this->controller->testVerifyWebhookSignature( $body, $signature, $this->secret )
		);
	}

	/**
	 * Test with real fixture payload and sign helper
	 */
	public function testFixturePayloadSignature(): void
	{
		$payload = \Tests\Fixtures\WebhookPayloads::completeReal();
		$rawBody = json_encode( $payload );
		$signature = \Tests\Fixtures\WebhookPayloads::sign( $payload, $this->secret );

		$this->assertTrue(
			$this->controller->testVerifyWebhookSignature( $rawBody, $signature, $this->secret )
		);
	}

	/**
	 * Ensure timing-safe comparison (hash_equals) is used.
	 * We can't directly test timing, but we verify the method
	 * uses the same algorithm as moneymotion docs.
	 */
	public function testMatchesMoneymotionDocsAlgorithm(): void
	{
		$body = '{"test":"data"}';

		// moneymotion docs: crypto.createHmac("sha512", secret).update(data).digest("base64")
		$expectedSignature = base64_encode( hash_hmac( 'sha512', $body, $this->secret, true ) );

		$this->assertTrue(
			$this->controller->testVerifyWebhookSignature( $body, $expectedSignature, $this->secret )
		);
	}
}
