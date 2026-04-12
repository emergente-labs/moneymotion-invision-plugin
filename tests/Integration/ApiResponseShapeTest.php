<?php
/**
 * Tests for API response shape robustness
 *
 * The client hardcodes the path `result.data.json.checkoutSessionId`. If
 * moneymotion's response shape ever changes (or an error response comes
 * back where we expected success), the plugin should fail with a clear
 * exception, not a fatal PHP warning that would surface to the customer.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class ApiResponseShapeTest extends TestCase
{
	private \IPS\moneymotion\Api\_Client $client;

	protected function setUp(): void
	{
		\IPS\Http\Url\Request::reset();
		$this->client = new \IPS\moneymotion\Api\_Client( 'mk_live_test' );
	}

	private function callCheckout(): string
	{
		return $this->client->createCheckoutSession(
			'Test',
			array( 'success' => 'x', 'cancel' => 'x', 'failure' => 'x' ),
			'test@example.com',
			array()
		);
	}

	public function testResponseMissingResultKeyHandled(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"data":{"json":{"checkoutSessionId":"cs_abc"}}}' // No 'result' wrapper
		);

		$this->expectException( \Throwable::class );
		$this->callCheckout();
	}

	public function testResponseMissingDataKeyHandled(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"result":{"json":{"checkoutSessionId":"cs_abc"}}}' // No 'data' level
		);

		$this->expectException( \Throwable::class );
		$this->callCheckout();
	}

	public function testResponseMissingJsonKeyHandled(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"result":{"data":{"checkoutSessionId":"cs_abc"}}}' // No 'json' level
		);

		$this->expectException( \Throwable::class );
		$this->callCheckout();
	}

	public function testResponseMissingCheckoutSessionIdHandled(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"result":{"data":{"json":{}}}}' // No 'checkoutSessionId'
		);

		$this->expectException( \Throwable::class );
		$this->callCheckout();
	}

	public function testResponseWithNullCheckoutSessionIdThrows(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"result":{"data":{"json":{"checkoutSessionId":null}}}}'
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'empty checkoutSessionId' );
		$this->callCheckout();
	}

	public function testResponseWithEmptyCheckoutSessionIdThrows(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"result":{"data":{"json":{"checkoutSessionId":""}}}}'
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'empty checkoutSessionId' );
		$this->callCheckout();
	}

	public function testResponseWithWhitespaceOnlyCheckoutSessionIdThrows(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"result":{"data":{"json":{"checkoutSessionId":"   "}}}}'
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'empty checkoutSessionId' );
		$this->callCheckout();
	}

	public function testCompletelyEmptyResponseHandled(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response( 200, '{}' );

		$this->expectException( \Throwable::class );
		$this->callCheckout();
	}

	public function testRateLimitResponse429Handled(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			429,
			'{"error":"Rate limit exceeded"}'
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Rate limit exceeded' );
		$this->callCheckout();
	}

	public function testGatewayTimeout504Handled(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			504,
			'{"error":"Gateway timeout"}'
		);

		$this->expectException( \RuntimeException::class );
		$this->callCheckout();
	}

	public function testCloudflareErrorPageHandled(): void
	{
		/* CF/cloudfront error pages return HTML, not JSON */
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			502,
			'<html><body><h1>502 Bad Gateway</h1></body></html>'
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid JSON response' );
		$this->callCheckout();
	}

	public function testResponseWith200ButErrorBodyHandled(): void
	{
		/* Some APIs return 200 OK with an error in the body. We should
		   catch this — but currently the code just reads checkoutSessionId. */
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"error":"Something went wrong"}'
		);

		$this->expectException( \Throwable::class );
		$this->callCheckout();
	}
}
