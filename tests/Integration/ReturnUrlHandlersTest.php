<?php
/**
 * Integration tests for success/cancel/failure return URL handlers
 *
 * Tests the customer-facing return paths after checkout.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;

class ReturnUrlHandlersTest extends TestCase
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
		\IPS\Request::reset();

		$this->controller = new TestableWebhookController;
	}

	private function registerTransaction( int $id ): \IPS\nexus\Transaction
	{
		$txn = new \IPS\nexus\Transaction;
		$txn->invoice = new \IPS\nexus\Invoice;
		\IPS\nexus\Transaction::register( $id, $txn );
		return $txn;
	}

	private function setRequestParams( int $transactionId, string $csrfToken ): void
	{
		\IPS\Request::i()->t = $transactionId;
		\IPS\Request::i()->csrf_token = $csrfToken;
	}

	/* --- SUCCESS handler --- */

	public function testSuccessRedirectsToInvoiceWithValidCsrf(): void
	{
		$txn = $this->registerTransaction( 100 );
		$token = $this->controller->testGenerateCsrfToken( 100, 'success' );
		$this->setRequestParams( 100, $token );

		// Call via reflection since success() is protected
		$ref = new \ReflectionMethod( $this->controller, 'success' );
		$ref->setAccessible( true );
		$ref->invoke( $this->controller );

		$output = \IPS\Output::i();
		$this->assertNotNull( $output->lastRedirect );
		$this->assertStringContainsString( 'invoice', $output->lastRedirect['url'] );
		$this->assertSame( 'moneymotion_payment_success', $output->lastRedirect['message'] );
	}

	public function testSuccessRejectsInvalidCsrf(): void
	{
		$this->registerTransaction( 100 );
		$this->setRequestParams( 100, 'bad_token' );

		$ref = new \ReflectionMethod( $this->controller, 'success' );
		$ref->setAccessible( true );
		$ref->invoke( $this->controller );

		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'CSRF token validation failed' ),
			'Should log CSRF failure'
		);
	}

	public function testSuccessHandlesMissingTransaction(): void
	{
		// Valid CSRF but transaction doesn't exist
		$token = $this->controller->testGenerateCsrfToken( 999, 'success' );
		$this->setRequestParams( 999, $token );

		$ref = new \ReflectionMethod( $this->controller, 'success' );
		$ref->setAccessible( true );
		$ref->invoke( $this->controller );

		$output = \IPS\Output::i();
		$this->assertNotNull( $output->lastRedirect );
		$this->assertSame( 'moneymotion_payment_processing', $output->lastRedirect['message'] );
	}

	/* --- CANCEL handler --- */

	public function testCancelMarksTransactionRefused(): void
	{
		$txn = $this->registerTransaction( 100 );
		$txn->status = \IPS\nexus\Transaction::STATUS_PENDING;
		$token = $this->controller->testGenerateCsrfToken( 100, 'cancel' );
		$this->setRequestParams( 100, $token );

		$ref = new \ReflectionMethod( $this->controller, 'cancel' );
		$ref->setAccessible( true );
		$ref->invoke( $this->controller );

		$this->assertSame(
			\IPS\nexus\Transaction::STATUS_REFUSED,
			$txn->status,
			'Cancel should mark pending transaction as refused'
		);
	}

	public function testCancelDoesNotChangeNonPendingTransaction(): void
	{
		$txn = $this->registerTransaction( 100 );
		$txn->status = \IPS\nexus\Transaction::STATUS_PAID;
		$token = $this->controller->testGenerateCsrfToken( 100, 'cancel' );
		$this->setRequestParams( 100, $token );

		$ref = new \ReflectionMethod( $this->controller, 'cancel' );
		$ref->setAccessible( true );
		$ref->invoke( $this->controller );

		$this->assertSame(
			\IPS\nexus\Transaction::STATUS_PAID,
			$txn->status,
			'Cancel should NOT change a paid transaction'
		);
	}

	public function testCancelUpdatesSessionStatus(): void
	{
		$this->registerTransaction( 100 );
		$token = $this->controller->testGenerateCsrfToken( 100, 'cancel' );
		$this->setRequestParams( 100, $token );

		$ref = new \ReflectionMethod( $this->controller, 'cancel' );
		$ref->setAccessible( true );
		$ref->invoke( $this->controller );

		$updates = \IPS\Db::i()->getOperations( 'update', 'moneymotion_sessions' );
		$found = false;
		foreach ( $updates as $op )
		{
			if ( isset( $op['data']['status'] ) && $op['data']['status'] === 'cancelled' )
			{
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Session should be marked cancelled' );
	}

	public function testCancelRedirectsToCheckout(): void
	{
		$this->registerTransaction( 100 );
		$token = $this->controller->testGenerateCsrfToken( 100, 'cancel' );
		$this->setRequestParams( 100, $token );

		$ref = new \ReflectionMethod( $this->controller, 'cancel' );
		$ref->setAccessible( true );
		$ref->invoke( $this->controller );

		$output = \IPS\Output::i();
		$this->assertNotNull( $output->lastRedirect );
		$this->assertStringContainsString( 'checkout', $output->lastRedirect['url'] );
		$this->assertSame( 'moneymotion_payment_cancelled', $output->lastRedirect['message'] );
	}

	public function testCancelRejectsInvalidCsrf(): void
	{
		$this->registerTransaction( 100 );
		$this->setRequestParams( 100, 'bad_token' );

		$ref = new \ReflectionMethod( $this->controller, 'cancel' );
		$ref->setAccessible( true );
		$ref->invoke( $this->controller );

		$this->assertTrue( \IPS\Log::hasMessageContaining( 'CSRF token validation failed' ) );
	}

	/* --- FAILURE handler --- */

	public function testFailureMarksTransactionRefused(): void
	{
		$txn = $this->registerTransaction( 100 );
		$txn->status = \IPS\nexus\Transaction::STATUS_PENDING;
		$token = $this->controller->testGenerateCsrfToken( 100, 'failure' );
		$this->setRequestParams( 100, $token );

		$ref = new \ReflectionMethod( $this->controller, 'failure' );
		$ref->setAccessible( true );
		$ref->invoke( $this->controller );

		$this->assertSame( \IPS\nexus\Transaction::STATUS_REFUSED, $txn->status );
	}

	public function testFailureUpdatesSessionToFailed(): void
	{
		$this->registerTransaction( 100 );
		$token = $this->controller->testGenerateCsrfToken( 100, 'failure' );
		$this->setRequestParams( 100, $token );

		$ref = new \ReflectionMethod( $this->controller, 'failure' );
		$ref->setAccessible( true );
		$ref->invoke( $this->controller );

		$updates = \IPS\Db::i()->getOperations( 'update', 'moneymotion_sessions' );
		$found = false;
		foreach ( $updates as $op )
		{
			if ( isset( $op['data']['status'] ) && $op['data']['status'] === 'failed' )
			{
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Session should be marked failed' );
	}

	public function testFailureRedirectsToCheckout(): void
	{
		$this->registerTransaction( 100 );
		$token = $this->controller->testGenerateCsrfToken( 100, 'failure' );
		$this->setRequestParams( 100, $token );

		$ref = new \ReflectionMethod( $this->controller, 'failure' );
		$ref->setAccessible( true );
		$ref->invoke( $this->controller );

		$output = \IPS\Output::i();
		$this->assertNotNull( $output->lastRedirect );
		$this->assertSame( 'moneymotion_payment_failed', $output->lastRedirect['message'] );
	}

	public function testFailureHandlesMissingTransaction(): void
	{
		$token = $this->controller->testGenerateCsrfToken( 999, 'failure' );
		$this->setRequestParams( 999, $token );

		$ref = new \ReflectionMethod( $this->controller, 'failure' );
		$ref->setAccessible( true );
		$ref->invoke( $this->controller );

		$output = \IPS\Output::i();
		$this->assertNotNull( $output->lastRedirect );
		$this->assertSame( 'moneymotion_payment_failed', $output->lastRedirect['message'] );
	}

	public function testFailureRejectsInvalidCsrf(): void
	{
		$this->registerTransaction( 100 );
		$this->setRequestParams( 100, 'bad_token' );

		$ref = new \ReflectionMethod( $this->controller, 'failure' );
		$ref->setAccessible( true );
		$ref->invoke( $this->controller );

		$this->assertTrue( \IPS\Log::hasMessageContaining( 'CSRF token validation failed' ) );
	}
}
