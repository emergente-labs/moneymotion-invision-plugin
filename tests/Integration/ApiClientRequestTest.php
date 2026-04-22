<?php
/**
 * Integration tests for API Client HTTP request building (Effect RPC / NDJSON).
 *
 * Verifies that createCheckoutSession() sends the Effect RPC envelope the
 * moneymotion backend expects at POST /rpc:
 *
 *     POST https://api.moneymotion.io/rpc
 *     Content-Type: application/ndjson
 *     {"_tag":"Request","id":"0","tag":"CheckoutSessionsCreateCheckoutSession","payload":{…},"headers":[]}
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class ApiClientRequestTest extends TestCase
{
	private \IPS\moneymotion\Api\_Client $client;

	protected function setUp(): void
	{
		\IPS\Http\Url\Request::reset();
		$this->client = new \IPS\moneymotion\Api\_Client( 'mk_live_test_key_abc' );
	}

	/**
	 * Helper — returns the decoded Effect RPC envelope that was sent on the
	 * most recent POST. Strips the trailing newline required by the NDJSON
	 * framing before decoding.
	 */
	private function capturedEnvelope(): array
	{
		$req = \IPS\Http\Url\Request::$captured[0];
		$body = rtrim( $req['body'], "\n" );
		$decoded = json_decode( $body, true );
		$this->assertIsArray( $decoded, 'Request body must be a single-line JSON envelope' );
		return $decoded;
	}

	public function testSendsPostRequestToRpcEndpoint(): void
	{
		$this->client->createCheckoutSession(
			'Test Invoice',
			array(
				'success' => 'https://example.com/success',
				'cancel'  => 'https://example.com/cancel',
				'failure' => 'https://example.com/failure',
			),
			'test@example.com',
			array( array( 'name' => 'Test Item', 'description' => 'Test', 'pricePerItemInCents' => 5000, 'quantity' => 1 ) )
		);

		$captured = \IPS\Http\Url\Request::$captured;
		$this->assertCount( 1, $captured );

		$req = $captured[0];
		$this->assertSame( 'POST', $req['method'] );
		$this->assertSame( 'https://api.moneymotion.io/rpc', $req['url'] );
	}

	public function testIncludesApiKeyHeader(): void
	{
		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);

		$captured = \IPS\Http\Url\Request::$captured[0];
		$this->assertArrayHasKey( 'x-api-key', $captured['headers'] );
		$this->assertSame( 'mk_live_test_key_abc', $captured['headers']['x-api-key'] );
	}

	public function testContentTypeIsNdjson(): void
	{
		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);

		$captured = \IPS\Http\Url\Request::$captured[0];
		$this->assertSame( 'application/ndjson', $captured['headers']['Content-Type'] );
		$this->assertSame( 'application/ndjson', $captured['headers']['Accept'] );
	}

	public function testIncludesCurrencyHeader(): void
	{
		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array(),
			array(),
			'BRL'
		);

		$captured = \IPS\Http\Url\Request::$captured[0];
		$this->assertArrayHasKey( 'x-currency', $captured['headers'] );
		$this->assertSame( 'BRL', $captured['headers']['x-currency'] );
	}

	public function testIncludesUserAgentHeader(): void
	{
		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);

		$captured = \IPS\Http\Url\Request::$captured[0];
		$this->assertArrayHasKey( 'User-Agent', $captured['headers'] );
		$this->assertStringContainsString( 'moneymotion IPS Plugin', $captured['headers']['User-Agent'] );
	}

	public function testRequestBodyIsEffectRpcEnvelope(): void
	{
		$this->client->createCheckoutSession(
			'Test Desc',
			array( 'success' => 'https://s', 'cancel' => 'https://c', 'failure' => 'https://f' ),
			'test@example.com',
			array( array( 'name' => 'X', 'description' => 'Y', 'pricePerItemInCents' => 1000, 'quantity' => 1 ) )
		);

		$envelope = $this->capturedEnvelope();

		$this->assertSame( 'Request', $envelope['_tag'] );
		$this->assertSame( '0', $envelope['id'] );
		$this->assertSame( 'CheckoutSessionsCreateCheckoutSession', $envelope['tag'] );
		$this->assertArrayHasKey( 'payload', $envelope );
		$this->assertSame( array(), $envelope['headers'] );
	}

	public function testRequestBodyHasTrailingNewline(): void
	{
		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);

		/* NDJSON framing requires a trailing newline after each message —
		   the parser on the server uses \n as the record separator. */
		$body = \IPS\Http\Url\Request::$captured[0]['body'];
		$this->assertStringEndsWith( "\n", $body );
	}

	public function testRequestPayloadIncludesAllRequiredFields(): void
	{
		$this->client->createCheckoutSession(
			'Invoice #100',
			array(
				'success' => 'https://site.com/success',
				'cancel'  => 'https://site.com/cancel',
				'failure' => 'https://site.com/failure',
			),
			'customer@example.com',
			array( array( 'name' => 'Product', 'description' => 'Desc', 'pricePerItemInCents' => 2500, 'quantity' => 2 ) )
		);

		$payload = $this->capturedEnvelope()['payload'];

		$this->assertSame( 'Invoice #100', $payload['description'] );
		$this->assertSame( 'https://site.com/success', $payload['urls']['success'] );
		$this->assertSame( 'https://site.com/cancel', $payload['urls']['cancel'] );
		$this->assertSame( 'https://site.com/failure', $payload['urls']['failure'] );
		$this->assertSame( 'customer@example.com', $payload['userInfo']['email'] );
		$this->assertCount( 1, $payload['lineItems'] );
		$this->assertSame( 2500, $payload['lineItems'][0]['pricePerItemInCents'] );
	}

	public function testEmptyMetadataIsOmitted(): void
	{
		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array(),
			array()
		);

		/* Empty metadata is OMITTED, not sent as {} — the server schema marks
		   it optional and the PR made this change to avoid sending a noisy
		   empty object on every call. */
		$payload = $this->capturedEnvelope()['payload'];
		$this->assertArrayNotHasKey( 'metadata', $payload );
	}

	public function testMetadataWithValuesIsSerializedAsObject(): void
	{
		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array(),
			array( 'invoice_id' => 100, 'transaction_id' => 200 )
		);

		$payload = $this->capturedEnvelope()['payload'];

		$this->assertArrayHasKey( 'metadata', $payload );
		$this->assertSame( 100, $payload['metadata']['invoice_id'] );
		$this->assertSame( 200, $payload['metadata']['transaction_id'] );

		/* Ensure metadata is serialized as a JSON object, not an array — the
		   server schema expects a Record/Object, and [] would be rejected. */
		$body = \IPS\Http\Url\Request::$captured[0]['body'];
		$this->assertStringContainsString( '"metadata":{', $body );
	}

	public function testSuccessfulResponseReturnsSessionId(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_abc_xyz_123"}}}' . "\n"
		);

		$sessionId = $this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);

		$this->assertSame( 'cs_abc_xyz_123', $sessionId );
	}

	public function testAuthFailureSurfacesReadableCauseMessage(): void
	{
		/* Backend returns HTTP 401 with an Exit/Failure whose Fail cause
		   carries a human-readable message. rpcCall() must parse the body
		   BEFORE bailing on the HTTP status so that message reaches the
		   caller rather than the raw JSON being dumped into the exception. */
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			401,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Failure","cause":{"_tag":"Fail","error":{"code":"unauthorized","message":"Invalid API key","_tag":"AuthenticationError"}}}}' . "\n"
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid API key' );

		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);
	}

	public function testNonNdjsonResponseThrowsException(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response( 200, 'not ndjson' );

		$this->expectException( \RuntimeException::class );
		/* The parser finds no Exit message in garbage content. */
		$this->expectExceptionMessage( 'no Exit message' );

		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);
	}

	public function testServerErrorWithoutParseableBodyThrowsViaHttpGuard(): void
	{
		/* A 500 with HTML (Cloudflare error page, origin down, etc.) isn't
		   valid NDJSON — the parser throws, rpcCall() falls back to the
		   HTTP-status guard and surfaces the status + truncated body. */
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			500,
			'<html><body>Internal Server Error</body></html>'
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'HTTP 500' );

		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);
	}

	public function testEmptyApiKeyThrowsException(): void
	{
		$client = new \IPS\moneymotion\Api\_Client( '' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'API key is empty' );

		$client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);
	}

	public function testDefaultCurrencyIsBRL(): void
	{
		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);

		$captured = \IPS\Http\Url\Request::$captured[0];
		$this->assertSame( 'BRL', $captured['headers']['x-currency'] );
	}
}
