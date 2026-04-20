<?php
/**
 * Integration tests for API Client behavior under network and upstream failures.
 *
 * Motivated by a production report of "many different people unable to
 * purchase, getting connection errors". The pre-fix client made a single
 * HTTP attempt with the IPS-default 10s timeout and no retry, so a brief
 * network blip or DNS hiccup translated 1:1 into checkout failures for
 * every customer in flight at that moment.
 *
 * These tests pin the fix in place:
 *   - explicit per-request timeout (HTTP_TIMEOUT_SECONDS)
 *   - retry on \IPS\Http\Request\Exception (cURL-level failures only)
 *   - NO retry on HTTP 5xx / 4xx (server may have processed the call)
 *   - clear errors for malformed/empty success responses
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class ConnectionFailureTest extends TestCase
{
	private \IPS\moneymotion\Api\_Client $client;

	protected function setUp(): void
	{
		\IPS\Http\Url\Request::reset();
		$this->client = new \IPS\moneymotion\Api\_Client( 'mk_live_test_key' );
	}

	private function callCreate(): string
	{
		return $this->client->createCheckoutSession(
			'Test Invoice',
			array( 'success' => 'https://s', 'cancel' => 'https://c', 'failure' => 'https://f' ),
			'customer@example.com',
			array( array( 'name' => 'X', 'description' => 'Y', 'pricePerItemInCents' => 1000, 'quantity' => 1 ) )
		);
	}

	private function successResponse( string $sessionId = 'cs_ok' ): \IPS\Http\Response
	{
		return new \IPS\Http\Response(
			200,
			'{"result":{"data":{"json":{"checkoutSessionId":"' . $sessionId . '"}}}}'
		);
	}

	/* ---------- Timeout is configured explicitly ---------- */

	public function testRequestPassesExplicitTimeoutToHttpLayer(): void
	{
		$this->callCreate();

		$captured = \IPS\Http\Url\Request::$captured;
		$this->assertCount( 1, $captured );
		$this->assertSame(
			\IPS\moneymotion\Api\_Client::HTTP_TIMEOUT_SECONDS,
			$captured[0]['timeout'],
			'Client must pass an explicit timeout instead of relying on IPS default (10s).'
		);
	}

	public function testTimeoutIsLongerThanIpsDefault(): void
	{
		/* If we ever drop back to <=10s we lose the fraud-check headroom
		   that motivated raising it. Pin the floor. */
		$this->assertGreaterThan(
			10,
			\IPS\moneymotion\Api\_Client::HTTP_TIMEOUT_SECONDS,
			'Timeout must exceed the IPS default of 10s.'
		);
	}

	/* ---------- Retry on cURL-level failures ---------- */

	public function testRetriesOnceOnIpsHttpRequestException(): void
	{
		\IPS\Http\Url\Request::$responseQueue = array(
			new \IPS\Http\Request\Exception( 'cURL error 28: Operation timed out' ),
			$this->successResponse( 'cs_recovered' ),
		);

		$sessionId = $this->callCreate();

		$this->assertSame( 'cs_recovered', $sessionId );
		$this->assertCount( 2, \IPS\Http\Url\Request::$captured, 'Should make exactly two HTTP attempts.' );
	}

	public function testRetriesOnDnsFailure(): void
	{
		\IPS\Http\Url\Request::$responseQueue = array(
			new \IPS\Http\Request\Exception( 'cURL error 6: Could not resolve host: api.moneymotion.io' ),
			$this->successResponse( 'cs_after_dns' ),
		);

		$this->assertSame( 'cs_after_dns', $this->callCreate() );
	}

	public function testRetriesOnConnectionRefused(): void
	{
		\IPS\Http\Url\Request::$responseQueue = array(
			new \IPS\Http\Request\Exception( 'cURL error 7: Failed to connect' ),
			$this->successResponse( 'cs_after_refused' ),
		);

		$this->assertSame( 'cs_after_refused', $this->callCreate() );
	}

	public function testGivesUpAfterMaxAttemptsOnPersistentNetworkFailure(): void
	{
		\IPS\Http\Url\Request::$responseQueue = array(
			new \IPS\Http\Request\Exception( 'cURL error 28: Operation timed out' ),
			new \IPS\Http\Request\Exception( 'cURL error 28: Operation timed out' ),
		);

		$this->expectException( \IPS\Http\Request\Exception::class );
		try
		{
			$this->callCreate();
		}
		finally
		{
			$this->assertCount(
				\IPS\moneymotion\Api\_Client::MAX_ATTEMPTS,
				\IPS\Http\Url\Request::$captured,
				'Should exhaust all configured attempts before throwing.'
			);
		}
	}

	public function testDoesNotRetryGenericRuntimeException(): void
	{
		/* Only \IPS\Http\Request\Exception (cURL-level) is retryable. A
		   generic RuntimeException could come from anywhere and may not be
		   safe to repeat. */
		\IPS\Http\Url\Request::$responseQueue = array(
			new \RuntimeException( 'something else broke' ),
			$this->successResponse( 'cs_should_not_reach' ),
		);

		try
		{
			$this->callCreate();
			$this->fail( 'Expected RuntimeException to propagate without retry.' );
		}
		catch ( \IPS\Http\Request\Exception $e )
		{
			$this->fail( 'Generic RuntimeException must not be wrapped as Http\\Request\\Exception.' );
		}
		catch ( \RuntimeException $e )
		{
			$this->assertSame( 'something else broke', $e->getMessage() );
		}

		$this->assertCount( 1, \IPS\Http\Url\Request::$captured, 'Generic RuntimeException must not trigger retry.' );
	}

	/* ---------- HTTP error responses must NOT retry ----------
	   Server saw the request → safe-by-default to surface the failure
	   rather than risk a duplicate side-effect. */

	public function testDoesNotRetryHttp502(): void
	{
		\IPS\Http\Url\Request::$responseQueue = array(
			new \IPS\Http\Response( 502, '{"error":"Bad Gateway"}' ),
			$this->successResponse( 'cs_should_not_reach' ),
		);

		$this->expectException( \RuntimeException::class );
		try
		{
			$this->callCreate();
		}
		finally
		{
			$this->assertCount( 1, \IPS\Http\Url\Request::$captured );
		}
	}

	public function testDoesNotRetryHttp503(): void
	{
		\IPS\Http\Url\Request::$responseQueue = array(
			new \IPS\Http\Response( 503, '{"error":"Service Unavailable"}' ),
			$this->successResponse( 'cs_should_not_reach' ),
		);

		$this->expectException( \RuntimeException::class );
		try
		{
			$this->callCreate();
		}
		finally
		{
			$this->assertCount( 1, \IPS\Http\Url\Request::$captured );
		}
	}

	public function testDoesNotRetryHttp504(): void
	{
		\IPS\Http\Url\Request::$responseQueue = array(
			new \IPS\Http\Response( 504, '{"error":"Gateway Timeout"}' ),
			$this->successResponse( 'cs_should_not_reach' ),
		);

		$this->expectException( \RuntimeException::class );
		try
		{
			$this->callCreate();
		}
		finally
		{
			$this->assertCount( 1, \IPS\Http\Url\Request::$captured );
		}
	}

	public function testDoesNotRetryHttp429RateLimit(): void
	{
		\IPS\Http\Url\Request::$responseQueue = array(
			new \IPS\Http\Response( 429, '{"error":"Too Many Requests"}' ),
			$this->successResponse( 'cs_should_not_reach' ),
		);

		$this->expectException( \RuntimeException::class );
		try
		{
			$this->callCreate();
		}
		finally
		{
			$this->assertCount( 1, \IPS\Http\Url\Request::$captured );
		}
	}

	public function testHttp502SurfacesErrorMessage(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			502,
			'{"error":"Bad Gateway"}'
		);

		try
		{
			$this->callCreate();
			$this->fail( 'Expected RuntimeException for HTTP 502.' );
		}
		catch ( \RuntimeException $e )
		{
			$this->assertStringContainsString( 'Bad Gateway', $e->getMessage() );
		}
	}

	/* ---------- Malformed success responses ---------- */

	public function testHttp200EmptyBodyThrowsClearError(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response( 200, '' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid JSON response' );
		$this->callCreate();
	}

	public function testHttp200WithNullResultThrowsClearError(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"result":null}'
		);

		$this->expectException( \RuntimeException::class );
		/* Misshapen envelope must be reported, not silently propagated as
		   an empty checkoutSessionId — that would redirect customers to
		   https://moneymotion.io/checkout/ (broken page). */
		$this->expectExceptionMessage( 'unexpected response shape' );
		$this->callCreate();
	}

	public function testHttp200WithEmptyCheckoutSessionIdThrowsClearError(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"result":{"data":{"json":{"checkoutSessionId":""}}}}'
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'empty checkoutSessionId' );
		$this->callCreate();
	}

	public function testHttp200WithMissingDataEnvelopeThrowsClearError(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"result":{}}'
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'unexpected response shape' );
		$this->callCreate();
	}

	/* ---------- The exception type matches IPS's actual hierarchy ---------- */

	public function testIpsHttpRequestExceptionExtendsRuntimeException(): void
	{
		/* IPS 4.7.x defines _Exception extends RuntimeException. We rely on
		   that hierarchy: anywhere we catch RuntimeException upstream (in
		   auth()) will still see retried-and-rethrown network errors. */
		$this->assertTrue(
			is_subclass_of( \IPS\Http\Request\Exception::class, \RuntimeException::class ),
			'\\IPS\\Http\\Request\\Exception must extend RuntimeException.'
		);
	}
}
