<?php
/**
 * Integration tests for handleCheckoutComplete()
 *
 * Tests the full flow: webhook payload → session lookup → amount validation
 * → transaction approval → session status update.
 *
 * These tests use the real moneymotion webhook payload structure from docs
 * and expose the exact bugs that cause "takes payment, doesn't give product".
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;
use Tests\Fixtures\WebhookPayloads;

class HandleCheckoutCompleteTest extends TestCase
{
	private TestableWebhookController $controller;

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		\IPS\nexus\Transaction::reset();
		\IPS\Settings::reset();
		\IPS\Member::reset();
		\IPS\Output::reset();

		$this->controller = new TestableWebhookController;
	}

	/**
	 * Helper: set up a stored session and transaction for testing
	 */
	private function setupSessionAndTransaction(
		string $sessionId = 'cs_test_123',
		int $transactionId = 200,
		int $amountCents = 5000,
		string $currency = 'EUR',
		string $status = 'pending'
	): \IPS\nexus\Transaction {
		// Mock DB with stored session
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id'     => $sessionId,
				'transaction_id' => $transactionId,
				'invoice_id'     => 100,
				'amount_cents'   => $amountCents,
				'currency'       => $currency,
				'status'         => $status,
				'created_at'     => time() - 300,
				'updated_at'     => time() - 300,
			),
		);

		// Register a mock transaction
		$txn = new \IPS\nexus\Transaction;
		$txn->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( $transactionId, $txn );

		return $txn;
	}

	/**
	 * CRITICAL TEST: Real moneymotion webhook should approve the transaction.
	 * This test FAILS on current code because:
	 * 1. extractPaidAmountCents() doesn't check 'totalInCents'
	 * 2. extractPaidCurrency() returns NULL (no currency in webhook)
	 */
	public function testRealMoneymotionWebhookApprovesTransaction(): void
	{
		$txn = $this->setupSessionAndTransaction( 'cs_test_123', 200, 5000, 'EUR' );

		$payload = WebhookPayloads::completeReal( 'cs_test_123', 5000 );

		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertTrue(
			$txn->wasApproved,
			'Transaction must be approved when real moneymotion webhook arrives with correct amount'
		);
	}

	/**
	 * Verify session status is updated to 'complete' after approval
	 */
	public function testSessionMarkedCompleteAfterApproval(): void
	{
		$this->setupSessionAndTransaction( 'cs_test_123', 200, 5000 );

		$payload = WebhookPayloads::completeReal( 'cs_test_123', 5000 );
		$this->controller->testHandleCheckoutComplete( $payload );

		$updates = \IPS\Db::i()->getOperations( 'update', 'moneymotion_sessions' );
		$found = false;
		foreach ( $updates as $op )
		{
			if ( isset( $op['data']['status'] ) && $op['data']['status'] === 'complete' )
			{
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Session should be updated to complete status' );
	}

	/**
	 * Amount mismatch should block approval
	 */
	public function testAmountMismatchBlocksApproval(): void
	{
		$txn = $this->setupSessionAndTransaction( 'cs_test_123', 200, 5000 );

		// Webhook says 1000 cents, but we stored 5000
		$payload = WebhookPayloads::completeReal( 'cs_test_123', 1000 );

		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertFalse(
			$txn->wasApproved,
			'Transaction must NOT be approved when amounts mismatch'
		);

		// Session should be marked failed
		$updates = \IPS\Db::i()->getOperations( 'update', 'moneymotion_sessions' );
		$failedUpdate = false;
		foreach ( $updates as $op )
		{
			if ( isset( $op['data']['status'] ) && $op['data']['status'] === 'failed' )
			{
				$failedUpdate = true;
				break;
			}
		}
		$this->assertTrue( $failedUpdate, 'Session should be marked failed on amount mismatch' );
	}

	/**
	 * Duplicate webhook (already complete) should be idempotent
	 */
	public function testDuplicateWebhookIsIdempotent(): void
	{
		$txn = $this->setupSessionAndTransaction( 'cs_test_123', 200, 5000, 'EUR', 'complete' );

		$payload = WebhookPayloads::completeReal( 'cs_test_123', 5000 );
		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertFalse( $txn->wasApproved, 'Should not re-approve already completed session' );
		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'already complete' ),
			'Should log that session is already complete'
		);
	}

	/**
	 * Missing session ID in payload should be handled
	 */
	public function testMissingSessionIdHandled(): void
	{
		$payload = array(
			'event' => 'checkout_session:complete',
			'checkoutSession' => array(),
		);

		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'missing session ID' ),
			'Should log missing session ID'
		);
	}

	/**
	 * Session not found in DB should be handled
	 */
	public function testSessionNotFoundHandled(): void
	{
		// No mock data set up — session won't be found
		$payload = WebhookPayloads::completeReal( 'cs_nonexistent', 5000 );

		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'session not found' ),
			'Should log session not found'
		);
	}

	/**
	 * Transaction not found in IPS should be handled
	 */
	public function testTransactionNotFoundHandled(): void
	{
		// Set up session but don't register the transaction
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id'     => 'cs_test_123',
				'transaction_id' => 999,
				'invoice_id'     => 100,
				'amount_cents'   => 5000,
				'currency'       => 'EUR',
				'status'         => 'pending',
				'created_at'     => time(),
				'updated_at'     => time(),
			),
		);

		$payload = WebhookPayloads::completeReal( 'cs_test_123', 5000 );
		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'transaction' ) && \IPS\Log::hasMessageContaining( 'not found' ),
			'Should log transaction not found'
		);
	}

	/**
	 * $transaction->approve() exception should be caught and logged
	 */
	public function testApproveExceptionCaughtAndLogged(): void
	{
		$txn = $this->setupSessionAndTransaction( 'cs_test_123', 200, 5000 );
		$txn->approveException = new \RuntimeException( 'IPS internal error' );

		$payload = WebhookPayloads::completeReal( 'cs_test_123', 5000 );
		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'failed to approve' ),
			'Should log approval failure'
		);
	}

	/**
	 * Test with zero amount (free checkout?) — should work if amounts match
	 */
	public function testZeroAmountMatchWorks(): void
	{
		$txn = $this->setupSessionAndTransaction( 'cs_test_123', 200, 0 );

		$payload = WebhookPayloads::completeReal( 'cs_test_123', 0 );
		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertTrue( $txn->wasApproved, 'Zero amount should approve if matching' );
	}

	/**
	 * Test various currencies
	 */
	public function testWorksWithBRLCurrency(): void
	{
		$txn = $this->setupSessionAndTransaction( 'cs_test_123', 200, 10000, 'BRL' );

		$payload = WebhookPayloads::completeReal( 'cs_test_123', 10000 );
		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertTrue( $txn->wasApproved, 'BRL currency transactions should work' );
	}

	public function testWorksWithUSDCurrency(): void
	{
		$txn = $this->setupSessionAndTransaction( 'cs_test_123', 200, 2500, 'USD' );

		$payload = WebhookPayloads::completeReal( 'cs_test_123', 2500 );
		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertTrue( $txn->wasApproved, 'USD currency transactions should work' );
	}

	/**
	 * Log should contain meaningful audit trail
	 */
	public function testSuccessfulApprovalLogsAuditTrail(): void
	{
		$this->setupSessionAndTransaction( 'cs_test_123', 200, 5000 );

		$payload = WebhookPayloads::completeReal( 'cs_test_123', 5000 );
		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'approved' ),
			'Successful approval should be logged'
		);
	}
}
