<?php
/**
 * Tests for the optional currency mismatch check
 *
 * After the BUG 2 fix: if the webhook payload DOES carry a currency field
 * (future schema change or legacy test payload), we verify it matches the
 * stored currency and block approval on mismatch.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;

class CurrencyMismatchTest extends TestCase
{
	private TestableWebhookController $controller;

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		\IPS\nexus\Transaction::reset();
		$this->controller = new TestableWebhookController;
	}

	private function setupSession( string $storedCurrency = 'EUR', int $amountCents = 5000 ): \IPS\nexus\Transaction
	{
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id' => 'cs_test_123',
				'transaction_id' => 200,
				'invoice_id' => 100,
				'amount_cents' => $amountCents,
				'currency' => $storedCurrency,
				'status' => 'pending',
				'created_at' => time(),
				'updated_at' => time(),
			),
		);

		$txn = new \IPS\nexus\Transaction;
		$txn->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( 200, $txn );
		return $txn;
	}

	public function testApprovalBlockedWhenWebhookCurrencyDiffersFromStored(): void
	{
		$txn = $this->setupSession( 'EUR', 5000 );

		$payload = array(
			'event' => 'checkout_session:complete',
			'checkoutSession' => array(
				'id' => 'cs_test_123',
				'totalInCents' => 5000,
				'currency' => 'USD',   /* <-- doesn't match stored EUR */
			),
		);

		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertFalse(
			$txn->wasApproved,
			'Transaction must NOT be approved when webhook currency disagrees with stored'
		);

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'currency mismatch' ),
			'Currency mismatch must be logged'
		);
	}

	public function testApprovalProceedsWhenWebhookOmitsCurrency(): void
	{
		$txn = $this->setupSession( 'EUR', 5000 );

		$payload = array(
			'event' => 'checkout_session:complete',
			'checkoutSession' => array(
				'id' => 'cs_test_123',
				'totalInCents' => 5000,
				/* no currency field — real moneymotion behavior */
			),
		);

		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertTrue(
			$txn->wasApproved,
			'Approval must proceed when webhook omits currency (real moneymotion behavior)'
		);
	}

	public function testApprovalProceedsWhenWebhookCurrencyMatchesStored(): void
	{
		$txn = $this->setupSession( 'EUR', 5000 );

		$payload = array(
			'event' => 'checkout_session:complete',
			'checkoutSession' => array(
				'id' => 'cs_test_123',
				'totalInCents' => 5000,
				'currency' => 'EUR',
			),
		);

		$this->controller->testHandleCheckoutComplete( $payload );
		$this->assertTrue( $txn->wasApproved );
	}

	public function testCurrencyComparisonIsCaseInsensitive(): void
	{
		$txn = $this->setupSession( 'eur', 5000 );   /* stored lowercase */

		$payload = array(
			'event' => 'checkout_session:complete',
			'checkoutSession' => array(
				'id' => 'cs_test_123',
				'totalInCents' => 5000,
				'currency' => 'EUR',  /* webhook uppercase */
			),
		);

		$this->controller->testHandleCheckoutComplete( $payload );
		$this->assertTrue( $txn->wasApproved, 'Case should not matter for currency codes' );
	}

	public function testSessionMarkedFailedOnCurrencyMismatch(): void
	{
		$this->setupSession( 'EUR', 5000 );

		$payload = array(
			'event' => 'checkout_session:complete',
			'checkoutSession' => array(
				'id' => 'cs_test_123',
				'totalInCents' => 5000,
				'currency' => 'USD',
			),
		);

		$this->controller->testHandleCheckoutComplete( $payload );

		$updates = \IPS\Db::i()->getOperations( 'update', 'moneymotion_sessions' );
		$failed = false;
		foreach ( $updates as $op )
		{
			if ( isset( $op['data']['status'] ) && $op['data']['status'] === 'failed' )
			{
				$failed = true;
			}
		}
		$this->assertTrue( $failed, 'Session should be marked failed on currency mismatch' );
	}
}
