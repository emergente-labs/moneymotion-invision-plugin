<?php
/**
 * Tests for zero-decimal currency handling.
 *
 * Currencies like JPY, KRW, HUF, CLP have ZERO decimal places — "1 JPY" is
 * one unit, not 100. The ISO 4217 minor-unit table governs this.
 *
 * The plugin blindly multiplies by 100 everywhere:
 *   $totalCents = (int) round( (float) $amount->amount * 100 );
 *
 * For a ¥500 transaction:
 *   - IPS stores amount = 500 (correct)
 *   - Plugin sends pricePerItemInCents: 50000 (WRONG — should be 500)
 *   - Customer gets charged ¥50,000 — 100× the intended amount!
 *
 * These tests FAIL if the bug exists.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableGateway;

class ZeroDecimalCurrencyTest extends TestCase
{
	private TestableGateway $gateway;

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		\IPS\nexus\Transaction::reset();
		\IPS\Settings::reset();
		\IPS\Member::reset();
		\IPS\Http\Url\Request::reset();

		$this->gateway = new TestableGateway;
		$this->gateway->id = 1;
		$this->gateway->setTestSettings( array(
			'api_key' => 'mk_live_test',
			'webhook_secret' => 'whsec_test',
		));
	}

	/**
	 * The ISO-4217 zero-decimal currencies (partial list of well-known ones).
	 */
	public static function zeroDecimalCurrencies(): array
	{
		return array(
			'JPY (Japanese Yen)'     => array( 'JPY', '500' ),
			'KRW (Korean Won)'       => array( 'KRW', '10000' ),
			'HUF (Hungarian Forint)' => array( 'HUF', '3000' ),
			'CLP (Chilean Peso)'     => array( 'CLP', '7000' ),
			'VND (Vietnamese Dong)'  => array( 'VND', '25000' ),
		);
	}

	/**
	 * @dataProvider zeroDecimalCurrencies
	 */
	public function testZeroDecimalCurrencySentToApiAsMinorUnit( string $currency, string $amount ): void
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->id = 100;
		$txn->amount = new \IPS\nexus\Money( $amount, $currency );
		$txn->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( 100, $txn );

		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200, '{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_test"}}}' . "\n"
		);

		try { $this->gateway->auth( $txn, array() ); }
		catch ( \Exception $e ) { /* redirect */ }

		$captured = \IPS\Http\Url\Request::$captured[0];
		$body = json_decode( rtrim($captured['body'], "\n"), true );
		$sent = $body['payload']['lineItems'][0]['pricePerItemInCents'];

		$expected = (int) $amount; // for zero-decimal currencies, the minor unit IS the major unit

		$this->assertSame(
			$expected,
			$sent,
			"For {$currency} '{$amount}' should send {$expected}, not {$sent} (100× error — customer overcharged)"
		);
	}

	public function testJpySessionAmountStoredInDbIsCorrect(): void
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->id = 100;
		$txn->amount = new \IPS\nexus\Money( '500', 'JPY' );
		$txn->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( 100, $txn );

		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200, '{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_jpy"}}}' . "\n"
		);

		try { $this->gateway->auth( $txn, array() ); }
		catch ( \Exception $e ) {}

		$inserts = \IPS\Db::i()->getOperations( 'insert', 'moneymotion_sessions' );
		$stored = $inserts[0]['data']['amount_cents'];

		$this->assertSame(
			500,
			$stored,
			"For ¥500, moneymotion_sessions.amount_cents must be 500 (the minor unit), not 50000"
		);
	}

	/**
	 * Regular 2-decimal currencies must still work correctly
	 */
	public function testEurStillConvertsCorrectly(): void
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->id = 100;
		$txn->amount = new \IPS\nexus\Money( '10.00', 'EUR' );
		$txn->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( 100, $txn );

		\IPS\Http\Url\Request::$nextResponse = new \IPS\Http\Response(
			200, '{"_tag":"Exit","requestId":"0","exit":{"_tag":"Success","value":{"checkoutSessionId":"cs_eur"}}}' . "\n"
		);

		try { $this->gateway->auth( $txn, array() ); }
		catch ( \Exception $e ) {}

		$captured = \IPS\Http\Url\Request::$captured[0];
		$body = json_decode( rtrim($captured['body'], "\n"), true );

		$this->assertSame( 1000, $body['payload']['lineItems'][0]['pricePerItemInCents'] );
	}
}
