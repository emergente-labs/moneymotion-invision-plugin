<?php
/**
 * Tests for auth() error paths
 *
 * Covers branches that aren't exercised by the happy-path tests:
 *   - Invoice recalculate throwing (swallowed, continues with transaction amount)
 *   - Amount mismatch between transaction and invoice (logged, continues)
 *   - API client throwing → LogicException surfaced to user
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableGateway;

class AuthErrorPathsTest extends TestCase
{
	private TestableGateway $gateway;

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		\IPS\nexus\Transaction::reset();
		\IPS\Http\Url\Request::reset();

		$this->gateway = new TestableGateway;
		$this->gateway->id = 1;
		$this->gateway->setTestSettings( array(
			'api_key' => 'mk_live_test',
			'webhook_secret' => 'whsec_test',
		));
	}

	public function testAuthContinuesWhenInvoiceSummaryThrows(): void
	{
		/* Transaction with an invoice that throws from summary().
		   auth() should catch \Throwable and continue. */
		$txn = new \IPS\nexus\Transaction;
		$txn->id = 100;
		$txn->amount = new \IPS\nexus\Money( '10.00', 'EUR' );

		/* Anonymous invoice subclass that throws from summary() */
		$txn->invoice = new class extends \IPS\nexus\Invoice {
			public function summary()
			{
				throw new \RuntimeException( 'DB error during summary' );
			}
		};

		\IPS\nexus\Transaction::register( 100, $txn );

		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_recovered"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $txn, array() );
		}
		catch ( \LogicException $e )
		{
			$this->fail( 'auth() should recover from summary() failure, not throw' );
		}
		catch ( \Exception $e ) { /* redirect may throw in test env */ }

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'failed to build invoice summary' ),
			'Summary failure should be logged'
		);
	}

	public function testAuthLogsAmountMismatchButContinues(): void
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->id = 100;
		$txn->amount = new \IPS\nexus\Money( '10.00', 'EUR' );

		/* Invoice whose amountToPay returns a different amount than transaction */
		$invoice = new \IPS\nexus\Invoice;
		$invoice->_amountToPay = new \IPS\nexus\Money( '15.00', 'EUR' );
		$txn->invoice = $invoice;

		\IPS\nexus\Transaction::register( 100, $txn );

		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200,
			'{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_mismatch"}}}' . "\n"
		);

		try
		{
			$this->gateway->auth( $txn, array() );
		}
		catch ( \Exception $e ) { /* redirect */ }

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'amount mismatch detected' ),
			'Amount mismatch between transaction and invoice should be logged'
		);

		/* Transaction amount (10.00 = 1000 cents) should still be used */
		$inserts = \IPS\Db::i()->getOperations( 'insert', 'moneymotion_sessions' );
		$this->assertSame( 1000, $inserts[0]['data']['amount_cents'] );
	}

	public function testAuthApiErrorThrowsLogicException(): void
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->id = 100;
		$txn->amount = new \IPS\nexus\Money( '10.00', 'EUR' );
		$txn->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( 100, $txn );

		/* Simulate API error */
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			500,
			'{"error":"MoneyMotion API down"}'
		);

		$this->expectException( \LogicException::class );
		$this->gateway->auth( $txn, array() );
	}

	public function testAuthApiExceptionPropagatedAsRuntimeException(): void
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->id = 100;
		$txn->amount = new \IPS\nexus\Money( '10.00', 'EUR' );
		$txn->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( 100, $txn );

		/* Simulate a network-level exception (connection refused etc.) */
		\IPS\Http\Url\Request::$nextResponse = new \RuntimeException( 'Connection refused' );

		/* auth() catches and rethrows as LogicException (user-facing message) */
		$this->expectException( \LogicException::class );
		$this->gateway->auth( $txn, array() );
	}

	public function testAuthWhenApiFailsLogsSessionFailure(): void
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->id = 100;
		$txn->amount = new \IPS\nexus\Money( '10.00', 'EUR' );
		$txn->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( 100, $txn );

		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			400,
			'{"error":"Bad request"}'
		);

		try
		{
			$this->gateway->auth( $txn, array() );
		}
		catch ( \LogicException $e ) { /* expected */ }

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'createCheckoutSession failed' ),
			'API failure should be logged before throwing'
		);
	}
}
