<?php
/**
 * Tests for the generic exception path in findGateway()
 *
 * findGateway() has three distinct catch blocks:
 *  1. UnderflowException (no gateway row) → return NULL silently
 *  2. Exception from constructFromData → return typed stub
 *  3. Any other Exception (e.g. DB connection error) → log + return NULL
 *
 * Block 3 is hard to test without a DB that throws SQLExceptions, but we
 * can verify the log message format when it does fire.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;

/**
 * A DB stub that throws a generic \RuntimeException from select().
 * Lets us exercise the outer catch(\Exception) block in findGateway().
 */
class FailingDb extends \IPS\Db
{
	public function select( $columns, $table, $where = null )
	{
		throw new \RuntimeException( 'Simulated DB connection error' );
	}
}

class FindGatewayExceptionTest extends TestCase
{
	private TestableWebhookController $controller;

	protected function setUp(): void
	{
		\IPS\Log::reset();

		/* Swap the DB singleton for one that throws */
		$ref = new \ReflectionProperty( \IPS\Db::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, new FailingDb );

		$this->controller = new TestableWebhookController;
	}

	protected function tearDown(): void
	{
		\IPS\Db::reset();
	}

	public function testGenericExceptionLoggedAndReturnsNull(): void
	{
		$result = $this->controller->testFindGateway();

		$this->assertNull( $result, 'Generic DB error should return NULL to upstream' );
		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'findGateway error' ),
			'Generic errors must be logged with class name'
		);
		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'RuntimeException' ),
			'Exception class name must appear in log for diagnosis'
		);
		$this->assertTrue(
			\IPS\Log::hasMessageContaining( 'Simulated DB connection error' ),
			'Exception message must appear in log'
		);
	}
}
