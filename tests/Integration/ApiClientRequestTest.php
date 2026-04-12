<?php
/**
 * Integration tests for API Client HTTP request building
 *
 * Uses the stub's captured request mechanism to verify that
 * createCheckoutSession() sends exactly what moneymotion API expects.
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

	public function testSendsPostRequestToCorrectUrl(): void
	{
		$this->client->createCheckoutSession(
			'Test Invoice',
			array(
				'success' => 'https://example.com/success',
				'cancel' => 'https://example.com/cancel',
				'failure' => 'https://example.com/failure',
			),
			'test@example.com',
			array(
				array(
					'name' => 'Test Item',
					'description' => 'Test',
					'pricePerItemInCents' => 5000,
					'quantity' => 1,
				),
			)
		);

		$captured = \IPS\Http\Url\Request::$captured;
		$this->assertCount( 1, $captured );

		$req = $captured[0];
		$this->assertSame( 'POST', $req['method'] );
		$this->assertStringContainsString(
			'https://api.moneymotion.io/checkoutSessions.createCheckoutSession',
			$req['url']
		);
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

	public function testIncludesContentTypeHeader(): void
	{
		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);

		$captured = \IPS\Http\Url\Request::$captured[0];
		$this->assertSame( 'application/json', $captured['headers']['Content-Type'] );
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

	public function testRequestBodyHasJsonWrapper(): void
	{
		$this->client->createCheckoutSession(
			'Test Desc',
			array( 'success' => 'https://s', 'cancel' => 'https://c', 'failure' => 'https://f' ),
			'test@example.com',
			array(
				array( 'name' => 'X', 'description' => 'Y', 'pricePerItemInCents' => 1000, 'quantity' => 1 )
			)
		);

		$captured = \IPS\Http\Url\Request::$captured[0];
		$body = json_decode( $captured['body'], true );

		$this->assertArrayHasKey( 'json', $body, 'Request body must have "json" wrapper' );
	}

	public function testRequestIncludesAllRequiredFields(): void
	{
		$this->client->createCheckoutSession(
			'Invoice #100',
			array(
				'success' => 'https://site.com/success',
				'cancel' => 'https://site.com/cancel',
				'failure' => 'https://site.com/failure',
			),
			'customer@example.com',
			array(
				array( 'name' => 'Product', 'description' => 'Desc', 'pricePerItemInCents' => 2500, 'quantity' => 2 ),
			)
		);

		$captured = \IPS\Http\Url\Request::$captured[0];
		$json = json_decode( $captured['body'], true )['json'];

		$this->assertSame( 'Invoice #100', $json['description'] );
		$this->assertSame( 'https://site.com/success', $json['urls']['success'] );
		$this->assertSame( 'https://site.com/cancel', $json['urls']['cancel'] );
		$this->assertSame( 'https://site.com/failure', $json['urls']['failure'] );
		$this->assertSame( 'customer@example.com', $json['userInfo']['email'] );
		$this->assertCount( 1, $json['lineItems'] );
		$this->assertSame( 2500, $json['lineItems'][0]['pricePerItemInCents'] );
	}

	public function testEmptyMetadataIsObject(): void
	{
		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array(),
			array()
		);

		$captured = \IPS\Http\Url\Request::$captured[0];

		// Empty metadata must serialize as {} not []
		$this->assertStringContainsString( '"metadata":{}', $captured['body'] );
	}

	public function testMetadataWithValuesIsObject(): void
	{
		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array(),
			array( 'invoice_id' => 100, 'transaction_id' => 200 )
		);

		$captured = \IPS\Http\Url\Request::$captured[0];
		$body = json_decode( $captured['body'], true );

		$this->assertArrayHasKey( 'metadata', $body['json'] );
		$this->assertSame( 100, $body['json']['metadata']['invoice_id'] );
		$this->assertSame( 200, $body['json']['metadata']['transaction_id'] );
	}

	public function testSuccessfulResponseReturnsSessionId(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"result":{"data":{"json":{"checkoutSessionId":"cs_abc_xyz_123"}}}}'
		);

		$sessionId = $this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);

		$this->assertSame( 'cs_abc_xyz_123', $sessionId );
	}

	public function testApiErrorThrowsRuntimeException(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			400,
			'{"error":"Invalid API Key"}'
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid API Key' );

		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);
	}

	public function testNonJsonResponseThrowsException(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response( 200, 'not json' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid JSON response' );

		$this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);
	}

	public function testServerErrorThrowsException(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			500,
			'{"error":"Internal Server Error"}'
		);

		$this->expectException( \RuntimeException::class );
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

	/**
	 * Verify default currency is BRL (per plugin default)
	 */
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
