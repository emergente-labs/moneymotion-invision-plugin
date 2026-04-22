<?php
/**
 * Full end-to-end auth() flow tests
 *
 * auth() is the entry point when a customer clicks "Pay with moneymotion".
 * This test exercises the full method with mocked API responses.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableGateway;

class AuthFullFlowTest extends TestCase
{
	private TestableGateway $gateway;
	private \IPS\nexus\Transaction $transaction;

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		\IPS\nexus\Transaction::reset();
		\IPS\Settings::reset();
		\IPS\Member::reset();
		\IPS\Output::reset();
		\IPS\Http\Url\Request::reset();

		$this->gateway = new TestableGateway;
		$this->gateway->id = 1;
		$this->gateway->setTestSettings( array(
			'api_key' => 'mk_live_test_abc123',
			'webhook_secret' => 'whsec_test_xyz789',
		));

		$this->transaction = new \IPS\nexus\Transaction;
		$this->transaction->id = 100;
		$this->transaction->amount = new \IPS\nexus\Money( '25.00', 'EUR' );
		$this->transaction->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( 100, $this->transaction );
	}

	public function testAuthCreatesCheckoutSessionViaApi(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_abc_123"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e )
		{
			// Redirect throws in some test setups
		}

		$captured = \IPS\Http\Url\Request::$captured;
		$this->assertNotEmpty( $captured, 'API call should have been made' );
		$this->assertStringEndsWith( '/rpc', $captured[0]['url'] );
	}

	public function testAuthStoresSessionInDb(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_stored_test"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e ) {}

		$inserts = \IPS\Db::i()->getOperations( 'insert', 'moneymotion_sessions' );
		$this->assertNotEmpty( $inserts, 'Session should be stored in moneymotion_sessions' );

		$stored = $inserts[0]['data'];
		$this->assertSame( 'cs_stored_test', $stored['session_id'] );
		$this->assertSame( 100, $stored['transaction_id'] );
		$this->assertSame( 'pending', $stored['status'] );
		$this->assertSame( 'EUR', $stored['currency'] );
		$this->assertSame( 2500, $stored['amount_cents'] );
	}

	public function testAuthDeletesExistingSessionsForTransaction(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_new"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e ) {}

		// Should delete existing sessions before inserting new one
		$deletes = \IPS\Db::i()->getOperations( 'delete', 'moneymotion_sessions' );
		$this->assertNotEmpty( $deletes, 'Should delete existing sessions for this transaction' );
	}

	public function testAuthStoresSessionIdOnTransaction(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_on_txn"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e ) {}

		$this->assertSame( 'cs_on_txn', $this->transaction->gw_id );
	}

	public function testAuthPassesAmountInCentsCorrectly(): void
	{
		// $25.00 → 2500 cents
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_test"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e ) {}

		$captured = \IPS\Http\Url\Request::$captured[0];
		$body = json_decode( rtrim($captured['body'], "\n"), true );
		$this->assertSame( 2500, $body['payload']['lineItems'][0]['pricePerItemInCents'] );
	}

	public function testAuthIncludesMetadataWithInvoiceAndTransactionIds(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_test"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e ) {}

		$captured = \IPS\Http\Url\Request::$captured[0];
		$body = json_decode( rtrim($captured['body'], "\n"), true );
		$metadata = $body['payload']['metadata'];

		$this->assertSame( 100, $metadata['transaction_id'] );
		$this->assertSame( 1, $metadata['gateway_id'] );
		$this->assertArrayHasKey( 'invoice_id', $metadata );
	}

	public function testAuthUrlsIncludeCsrfTokens(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_test"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e ) {}

		$captured = \IPS\Http\Url\Request::$captured[0];
		$body = json_decode( rtrim($captured['body'], "\n"), true );
		$urls = $body['payload']['urls'];

		$this->assertStringContainsString( 'csrf_token=', $urls['success'] );
		$this->assertStringContainsString( 'csrf_token=', $urls['cancel'] );
		$this->assertStringContainsString( 'csrf_token=', $urls['failure'] );
	}

	public function testAuthForcesCallbackUrlsToHttps(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_test"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e ) {}

		$captured = \IPS\Http\Url\Request::$captured[0];
		$body = json_decode( rtrim($captured['body'], "\n"), true );
		$urls = $body['payload']['urls'];

		$this->assertStringStartsWith( 'https://', $urls['success'] );
		$this->assertStringStartsWith( 'https://', $urls['cancel'] );
		$this->assertStringStartsWith( 'https://', $urls['failure'] );
	}

	public function testAuthApiFailureThrowsLogicException(): void
	{
		/* Backend returns HTTP 401 for invalid API key with the readable
		   message inside Exit/Failure/Fail. The client surfaces that as a
		   RuntimeException, the gateway catches it and rethrows as
		   LogicException with a translated user-facing message. */
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			401,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Failure","cause":{"_tag":"Fail","error":{"code":"unauthorized","message":"Invalid API key","_tag":"AuthenticationError"}}}}' . "\n"
		);

		$this->expectException( \LogicException::class );
		$this->gateway->auth( $this->transaction, array() );
	}

	public function testAuthApiFailureLogged(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			500,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Failure","cause":{"_tag":"Die","defect":{"message":"Internal Server Error"}}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \LogicException $e ) {}

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'createCheckoutSession failed' ),
			'API failures should be logged with details'
		);
	}

	public function testAuthLogsSessionCreation(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_logged"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e ) {}

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'checkout session created' ),
			'Successful session creation should be logged'
		);
	}

	public function testAuthLogsPaymentAttempt(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_logged"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e ) {}

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'payment attempt started' ),
			'Payment attempt should be logged with audit trail'
		);
	}

	public function testAuthLogsInvoiceSummary(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_logged"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e ) {}

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'invoice summary' ),
			'Invoice summary should be logged for debugging'
		);
	}

	public function testAuthHandlesLargeAmounts(): void
	{
		$this->transaction->amount = new \IPS\nexus\Money( '9999.99', 'EUR' );

		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_large"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e ) {}

		$captured = \IPS\Http\Url\Request::$captured[0];
		$body = json_decode( rtrim($captured['body'], "\n"), true );
		$this->assertSame( 999999, $body['payload']['lineItems'][0]['pricePerItemInCents'] );
	}

	public function testAuthHandlesSmallAmounts(): void
	{
		$this->transaction->amount = new \IPS\nexus\Money( '0.01', 'EUR' );

		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_small"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e ) {}

		$captured = \IPS\Http\Url\Request::$captured[0];
		$body = json_decode( rtrim($captured['body'], "\n"), true );
		$this->assertSame( 1, $body['payload']['lineItems'][0]['pricePerItemInCents'] );
	}

	public function testAuthPassesCurrencyHeaderToApi(): void
	{
		$this->transaction->amount = new \IPS\nexus\Money( '100.00', 'BRL' );

		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_brl"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $this->transaction, array() );
		}
		catch ( \Exception $e ) {}

		$captured = \IPS\Http\Url\Request::$captured[0];
		$this->assertSame( 'BRL', $captured['headers']['x-currency'] );
	}

	public function testAuthSavesTransactionIfMissingId(): void
	{
		// Create a transaction without ID
		$newTxn = new \IPS\nexus\Transaction;
		$newTxn->invoice = new \IPS\nexus\Invoice;
		$newTxn->amount = new \IPS\nexus\Money( '10.00', 'EUR' );
		// No id set and not registered

		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_newtxn"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $newTxn, array() );
		}
		catch ( \Exception $e ) {}

		// The transaction should now have an ID
		$this->assertNotNull( $newTxn->id, 'auth() should save transaction to get an ID' );
	}
}
