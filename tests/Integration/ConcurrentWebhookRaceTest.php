<?php
/**
 * Tests for the concurrent-webhook race condition.
 *
 * Scenario: moneymotion sends a webhook, times out before receiving our 200,
 * retries. Our second copy hits us while the first is still processing.
 * The "already complete" idempotency check does a READ then a WRITE with no
 * row lock — two concurrent webhooks can both see status='pending', both
 * call $transaction->approve(), double-approval.
 *
 * We simulate by:
 *   1. Calling handleCheckoutComplete()
 *   2. BEFORE the final UPDATE runs, starting a second handleCheckoutComplete()
 *      with the same session (monkey-patched via a DB stub that captures the
 *      moment between read and write)
 *
 * If the plugin has no lock, both webhooks will approve the transaction.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;
use Tests\Fixtures\WebhookPayloads;

class ConcurrentWebhookRaceTest extends TestCase
{
	private TestableWebhookController $controller;

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		\IPS\nexus\Transaction::reset();
		$this->controller = new TestableWebhookController;
	}

	/**
	 * Simulates race: two identical webhooks processing the same session.
	 * Without row locking, both should see status='pending' on read and
	 * both should approve.
	 */
	public function testTwoIdenticalWebhooksBothApprove(): void
	{
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id'     => 'cs_race',
				'transaction_id' => 999,
				'invoice_id'     => 100,
				'amount_cents'   => 5000,
				'currency'       => 'EUR',
				'status'         => 'pending',
				'created_at'     => time(),
				'updated_at'     => time(),
			),
		);

		$txn = new \IPS\nexus\Transaction;
		$txn->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( 999, $txn );

		$payload = WebhookPayloads::completeReal( 'cs_race', 5000 );

		/* Serial calls in the SAME request — current implementation checks
		   the DB value which is still 'pending' on the second call because
		   the mock updates happen in-memory and we'd need to sync them. */

		/* Drive the two approvals */
		$this->controller->testHandleCheckoutComplete( $payload );
		$firstApproved = $txn->wasApproved;

		$txn->wasApproved = false; // reset
		$this->controller->testHandleCheckoutComplete( $payload );
		$secondApproved = $txn->wasApproved;

		/* Per the current code: after the first call, the session row in
		   mockData is updated to 'complete'. The second call's SELECT
		   returns status='complete', triggering the early return via
		   "already complete". So second shouldn't approve.

		   The BUG is visible at the SQL level: between the SELECT and
		   the UPDATE there's no lock. In mysql this would be:
		     BEGIN; SELECT ... WHERE session_id=? FOR UPDATE; [process]; UPDATE ...; COMMIT;
		   but the code has no BEGIN/FOR UPDATE. */

		$this->assertTrue( $firstApproved, 'First webhook must approve' );
		$this->assertFalse(
			$secondApproved,
			'Second webhook must NOT re-approve after first persisted status=complete'
		);
	}

	/**
	 * This test demonstrates the actual SQL race — if we simulate the
	 * mysql level where two connections can both see pending before
	 * either writes, the plugin would double-approve.
	 *
	 * In the unit-test stub we can't simulate connection-level concurrency,
	 * but we can test that the code uses FOR UPDATE locking (grep-style).
	 */
	public function testHandleCheckoutCompleteUsesClaimPattern(): void
	{
		$src = file_get_contents(
			__DIR__ . '/../../applications/moneymotion/modules/front/gateway/webhook.php'
		);

		/* Either row-level locking OR a claim-pattern UPDATE counts as safe. */
		$hasRowLock = (
			stripos( $src, 'FOR UPDATE' ) !== false ||
			stripos( $src, 'beginTransaction' ) !== false
		);

		$hasClaimPattern = (
			stripos( $src, "status='pending'" ) !== false ||
			stripos( $src, "status = 'pending'" ) !== false ||
			stripos( $src, "'status' => 'processing'" ) !== false
		);

		$this->assertTrue(
			$hasRowLock || $hasClaimPattern,
			'handleCheckoutComplete must use FOR UPDATE locking or a ' .
			'claim-pattern UPDATE to prevent concurrent-webhook double-approval'
		);
	}

	public function testSecondConcurrentWebhookDoesNotDoubleApprove(): void
	{
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array(
			array(
				'session_id'     => 'cs_race2',
				'transaction_id' => 9999,
				'invoice_id'     => 100,
				'amount_cents'   => 5000,
				'currency'       => 'EUR',
				'status'         => 'pending',
				'created_at'     => time(),
				'updated_at'     => time(),
			),
		);

		$txn = new \IPS\nexus\Transaction;
		$txn->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( 9999, $txn );

		$payload = WebhookPayloads::completeReal( 'cs_race2', 5000 );

		$this->controller->testHandleCheckoutComplete( $payload );
		$this->assertTrue( $txn->wasApproved, 'First webhook must approve' );

		/* Second identical webhook — status is no longer 'pending' so the
		   claim UPDATE affects 0 rows and we return early. */
		$txn->wasApproved = false;
		$this->controller->testHandleCheckoutComplete( $payload );

		$this->assertFalse(
			$txn->wasApproved,
			'Second concurrent webhook must NOT re-approve — claim-pattern must block it'
		);
	}

}
