<?php
/**
 * Tests for API response shape robustness (Effect RPC / NDJSON).
 *
 * The client parses Effect RPC NDJSON responses — each line is a JSON message,
 * the terminal one is `{"_tag":"Exit","exit":{...}}` carrying either a Success
 * value or a Failure cause. If the backend returns something else (malformed
 * NDJSON, HTML error pages, empty bodies), the plugin must fail with a clear
 * exception rather than a fatal PHP warning reaching the customer.
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

	/**
	 * Helper — mock a 200 response carrying a raw body. Caller provides the
	 * exact NDJSON bytes to simulate each malformed-response scenario.
	 */
	private function mockResponse( int $status, string $body ): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response( $status, $body );
	}

	public function testResponseWithoutExitMessageThrows(): void
	{
		/* Server sends NDJSON but never emits an Exit — parser can't extract
		   a result. */
		$this->mockResponse( 200, '{"_tag":"Request","id":"0","tag":"Foo","payload":{},"headers":[]}' . "\n" );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'no Exit message' );
		$this->callCheckout();
	}

	public function testCompletelyEmptyResponseThrows(): void
	{
		$this->mockResponse( 200, '' );

		$this->expectException( \RuntimeException::class );
		$this->callCheckout();
	}

	public function testExitSuccessMissingCheckoutSessionIdThrows(): void
	{
		/* Success value exists but doesn't carry the key the caller expects. */
		$this->mockResponse(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{}}}' . "\n"
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'checkoutSessionId' );
		$this->callCheckout();
	}

	public function testExitSuccessWithNullCheckoutSessionIdThrows(): void
	{
		$this->mockResponse(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":null}}}' . "\n"
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'checkoutSessionId' );
		$this->callCheckout();
	}

	public function testExitSuccessWithEmptyCheckoutSessionIdThrows(): void
	{
		$this->mockResponse(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":""}}}' . "\n"
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'empty checkoutSessionId' );
		$this->callCheckout();
	}

	public function testExitSuccessWithWhitespaceOnlyCheckoutSessionIdThrows(): void
	{
		$this->mockResponse(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"   "}}}' . "\n"
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'empty checkoutSessionId' );
		$this->callCheckout();
	}

	public function testExitFailureFailCauseSurfacesReadableMessage(): void
	{
		/* Domain-level failure (validation, business rejection). The backend
		   may return 200 or 4xx — rpcCall() parses the body either way and
		   extracts the Fail cause's error.message for the exception. */
		$this->mockResponse(
			422,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Failure","cause":{"_tag":"Fail","error":{"code":"validation","message":"Line item price must be positive","_tag":"ValidationError"}}}}' . "\n"
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Line item price must be positive' );
		$this->callCheckout();
	}

	public function testExitFailureDieCauseSurfacesReadableMessage(): void
	{
		/* An Effect Die cause wraps a defect — the plugin extracts the
		   defect message when it's a string or { message: "..." }. */
		$this->mockResponse(
			500,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Failure","cause":{"_tag":"Die","defect":{"message":"database connection lost"}}}}' . "\n"
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'database connection lost' );
		$this->callCheckout();
	}

	public function testDefectMessageIsSurfaced(): void
	{
		/* A top-level Defect message (as opposed to Exit/Failure/Die) means
		   the server-side parser bombed before even producing an Exit. */
		$this->mockResponse(
			500,
			'{"_tag":"Defect","defect":"Malformed request envelope"}' . "\n"
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'defect' );
		$this->callCheckout();
	}

	public function testRateLimitResponse429WithExitFailureSurfacesMessage(): void
	{
		/* Modern Effect RPC backends wrap rate-limit rejections in Exit/Fail
		   too — the plugin should surface "Rate limit exceeded" rather than
		   "HTTP 429: {...raw body...}". */
		$this->mockResponse(
			429,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Failure","cause":{"_tag":"Fail","error":{"code":"rate_limited","message":"Rate limit exceeded","_tag":"RateLimitError"}}}}' . "\n"
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Rate limit exceeded' );
		$this->callCheckout();
	}

	public function testGatewayTimeout504WithoutParseableBodyFallsBackToHttpGuard(): void
	{
		/* Transport-level failure: no valid NDJSON in the body. rpcCall()
		   tries parseRpcExit(), that throws, then the HTTP-status guard
		   fires and surfaces the status code + truncated body. */
		$this->mockResponse( 504, 'Gateway Timeout' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'HTTP 504' );
		$this->callCheckout();
	}

	public function testCloudflareErrorPageFallsBackToHttpGuard(): void
	{
		/* Cloudflare/CDN HTML error pages can't be parsed as NDJSON. */
		$this->mockResponse( 502, '<html><body><h1>502 Bad Gateway</h1></body></html>' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'HTTP 502' );
		$this->callCheckout();
	}
}
