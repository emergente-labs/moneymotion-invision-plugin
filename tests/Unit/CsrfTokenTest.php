<?php
/**
 * Tests for CSRF token generation and validation on return URLs.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;

class CsrfTokenTest extends TestCase
{
	private TestableWebhookController $controller;

	protected function setUp(): void
	{
		\IPS\Settings::reset();
		\IPS\Member::reset();
		$this->controller = new TestableWebhookController;
	}

	public function testGeneratedTokenValidates(): void
	{
		$token = $this->controller->testGenerateCsrfToken( 123, 'success' );
		$this->assertTrue(
			$this->controller->testValidateCsrfToken( 123, $token, 'success' )
		);
	}

	public function testDifferentActionInvalidates(): void
	{
		$token = $this->controller->testGenerateCsrfToken( 123, 'success' );
		$this->assertFalse(
			$this->controller->testValidateCsrfToken( 123, $token, 'cancel' )
		);
	}

	public function testDifferentTransactionIdInvalidates(): void
	{
		$token = $this->controller->testGenerateCsrfToken( 123, 'success' );
		$this->assertFalse(
			$this->controller->testValidateCsrfToken( 456, $token, 'success' )
		);
	}

	public function testEmptyTokenInvalidates(): void
	{
		$this->assertFalse(
			$this->controller->testValidateCsrfToken( 123, '', 'success' )
		);
	}

	public function testNullTokenInvalidates(): void
	{
		$this->assertFalse(
			$this->controller->testValidateCsrfToken( 123, null, 'success' )
		);
	}

	public function testNonScalarTokenInvalidates(): void
	{
		$this->assertFalse(
			$this->controller->testValidateCsrfToken( 123, array( 'bad' ), 'success' )
		);
	}

	public function testAllThreeActionsProduceDifferentTokens(): void
	{
		$success = $this->controller->testGenerateCsrfToken( 100, 'success' );
		$cancel  = $this->controller->testGenerateCsrfToken( 100, 'cancel' );
		$failure = $this->controller->testGenerateCsrfToken( 100, 'failure' );

		$this->assertNotSame( $success, $cancel );
		$this->assertNotSame( $success, $failure );
		$this->assertNotSame( $cancel, $failure );
	}

	public function testTokenIsDeterministic(): void
	{
		$token1 = $this->controller->testGenerateCsrfToken( 42, 'success' );
		$token2 = $this->controller->testGenerateCsrfToken( 42, 'success' );
		$this->assertSame( $token1, $token2 );
	}

	public function testRandomStringDoesNotValidate(): void
	{
		$this->assertFalse(
			$this->controller->testValidateCsrfToken( 123, 'random_garbage_string', 'success' )
		);
	}
}
