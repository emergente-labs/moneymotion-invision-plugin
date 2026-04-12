<?php
/**
 * Tests for API Client GET request path
 *
 * The request() method handles both POST (public API) and GET (for future
 * endpoints). createCheckoutSession uses POST; the GET branch is currently
 * dormant but must not crash if ever used.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Expose the protected request() method so the GET branch is testable
 * without adding a public API to the shipped Client.
 */
class ExposedApiClient extends \IPS\moneymotion\Api\_Client
{
	public function testRequest( $endpoint, array $data = array(), $method = 'POST', array $extraHeaders = array() )
	{
		return $this->request( $endpoint, $data, $method, $extraHeaders );
	}
}

class ApiClientGetRequestTest extends TestCase
{
	private ExposedApiClient $client;

	protected function setUp(): void
	{
		\IPS\Http\Url\Request::reset();
		$this->client = new ExposedApiClient( 'mk_live_test' );
	}

	public function testGetRequestDoesNotSendBody(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response( 200, '{"ok":true}' );

		$this->client->testRequest( 'ping/ping', array(), 'GET' );

		$captured = \IPS\Http\Url\Request::$captured[0];
		$this->assertSame( 'GET', $captured['method'] );
		$this->assertArrayNotHasKey( 'body', $captured, 'GET request must not carry a body' );
	}

	public function testGetRequestStillAuthenticatesViaApiKeyHeader(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response( 200, '{"ok":true}' );

		$this->client->testRequest( 'ping/ping', array(), 'GET' );

		$captured = \IPS\Http\Url\Request::$captured[0];
		$this->assertSame( 'mk_live_test', $captured['headers']['x-api-key'] );
	}

	public function testGetRequestSendsExtraHeaders(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response( 200, '{"ok":true}' );

		$this->client->testRequest( 'ping/ping', array(), 'GET', array( 'X-Custom' => 'abc' ) );

		$captured = \IPS\Http\Url\Request::$captured[0];
		$this->assertSame( 'abc', $captured['headers']['X-Custom'] );
	}

	public function testPostRequestWithEmptyBody(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response( 200, '{"ok":true}' );

		$this->client->testRequest( 'some.endpoint', array(), 'POST' );

		$captured = \IPS\Http\Url\Request::$captured[0];
		$this->assertSame( 'POST', $captured['method'] );
		$this->assertSame( '[]', $captured['body'], 'Empty data should still serialize' );
	}

	public function testRequestTrimsApiKey(): void
	{
		$client = new ExposedApiClient( '  mk_live_test  ' );
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response( 200, '{"ok":true}' );

		$client->testRequest( 'ping/ping', array(), 'GET' );

		$captured = \IPS\Http\Url\Request::$captured[0];
		$this->assertSame( 'mk_live_test', $captured['headers']['x-api-key'], 'API key should be trimmed' );
	}
}
