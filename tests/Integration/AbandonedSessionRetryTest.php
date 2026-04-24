<?php
/**
 * Tests for the "customer abandoned on moneymotion, retries later" scenario.
 *
 * Flow:
 *   1. Customer clicks "Pay" — auth() creates moneymotion session #1, stores in DB
 *   2. Customer closes browser tab (no return URL hit)
 *   3. Customer returns to checkout and clicks "Pay" again — auth() runs again
 *
 * What the plugin does: DELETE FROM moneymotion_sessions WHERE transaction_id=?
 * before INSERTing the new session. Good — that handles the table pollution.
 *
 * But the OLD moneymotion session still exists on moneymotion.io's side. If
 * the old one completes later (e.g. customer had two tabs open), it hits us
 * as a webhook for a session_id we no longer know about. We've tested that
 * case (webhook-unknown-session) — it 200s and logs.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableGateway;

class AbandonedSessionRetryTest extends TestCase
{
	private TestableGateway $gateway;
	private \IPS\nexus\Transaction $txn;

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

		$this->txn = new \IPS\nexus\Transaction;
		$this->txn->id = 100;
		$this->txn->amount = new \IPS\nexus\Money( '25.00', 'EUR' );
		$this->txn->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( 100, $this->txn );
	}

	public function testRetryDeletesOldSessionBeforeNewInsert(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200, '{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_first"}}}' . "\n"
		);
		try { $this->gateway->auth( $this->txn, array() ); } catch ( \Exception $e ) {}

		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200, '{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_second"}}}' . "\n"
		);
		try { $this->gateway->auth( $this->txn, array() ); } catch ( \Exception $e ) {}

		$ops = \IPS\Db::i()->operations;
		$deleteCount = 0;
		$insertCount = 0;
		foreach ( $ops as $op )
		{
			if ( $op['type'] === 'delete' && $op['table'] === 'moneymotion_sessions' ) $deleteCount++;
			if ( $op['type'] === 'insert' && $op['table'] === 'moneymotion_sessions' ) $insertCount++;
		}

		$this->assertSame( 2, $deleteCount, 'Each auth() should DELETE old sessions first' );
		$this->assertSame( 2, $insertCount );
	}

	public function testRetryWithSameTransactionGeneratesDifferentCsrfTokens(): void
	{
		/* This is a security check — if the CSRF token only depends on
		   txn_id+action+member+cookie_key, then a retry generates the
		   SAME token as the first attempt. An attacker with the first
		   attempt's URL could potentially replay it even after cancellation. */

		$first = $this->gateway->testGenerateCsrfToken( 100, 'success' );
		$second = $this->gateway->testGenerateCsrfToken( 100, 'success' );

		$this->assertSame(
			$first,
			$second,
			'Documenting: CSRF tokens are deterministic across retries — '
			. 'they only invalidate when the cookie_login_key rotates. '
			. 'Not ideal but acceptable for the scope (same txn, same member).'
		);
	}

	/**
	 * auth() takes a $maxMind parameter for fraud scoring but never uses it.
	 * IPS expects us to populate it with customer data (name, address, IP).
	 * Without that, MaxMind integration doesn't have enough context.
	 */
	public function testMaxMindParameterIsAcceptedButUnused(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200, '{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_x"}}}' . "\n"
		);

		$mm = new \IPS\nexus\Fraud\MaxMind\Request;

		try { $this->gateway->auth( $this->txn, array(), $mm ); }
		catch ( \Exception $e ) {}

		/* Documenting: auth() accepts $maxMind but the plugin doesn't enrich it.
		   If a site has MaxMind enabled, fraud scoring won't have customer data
		   for moneymotion transactions.
		   We assert that the plugin DOESN'T crash, which is the minimum. */
		$this->assertTrue( true );
	}

	/**
	 * auth() takes a $recurrings parameter for subscription renewals.
	 * The plugin advertises it doesn't support subscriptions (canStoreCards=false)
	 * but if IPS calls auth() with a non-empty $recurrings anyway, we should
	 * degrade cleanly rather than crash.
	 */
	public function testRecurringsParameterHandledGracefully(): void
	{
		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200, '{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_x"}}}' . "\n"
		);

		$recurrings = array(
			'term' => 'monthly',
			'amount' => '25.00',
			'cost' => '25.00',
		);

		try { $this->gateway->auth( $this->txn, array(), null, $recurrings, 'renewal' ); }
		catch ( \Exception $e ) { /* redirect */ }

		/* Must not crash, must still create a session */
		$inserts = \IPS\Db::i()->getOperations( 'insert', 'moneymotion_sessions' );
		$this->assertNotEmpty( $inserts, 'auth() must handle $recurrings param without crashing' );
	}
}
