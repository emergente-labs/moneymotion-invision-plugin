<?php
/**
 * Integration tests for the install script
 *
 * Tests that the install routine creates the moneymotion_sessions
 * table with the correct schema.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class InstallScriptTest extends TestCase
{
	protected function setUp(): void
	{
		\IPS\Db::reset();
	}

	public function testInstallScriptLoadsWithoutError(): void
	{
		require_once __DIR__ . '/../../applications/moneymotion/setup/install.php';
		$this->assertTrue( function_exists( '\\IPS\\moneymotion\\setup\\install\\step1' ) );
	}

	public function testInstallStep1CreatesTable(): void
	{
		require_once __DIR__ . '/../../applications/moneymotion/setup/install.php';

		// Table doesn't exist initially
		$result = call_user_func( '\\IPS\\moneymotion\\setup\\install\\step1' );
		$this->assertTrue( $result );

		$ops = \IPS\Db::i()->getOperations( 'createTable' );
		$this->assertNotEmpty( $ops, 'Install step1 must create a table' );
	}

	public function testInstalledTableHasCorrectName(): void
	{
		require_once __DIR__ . '/../../applications/moneymotion/setup/install.php';
		call_user_func( '\\IPS\\moneymotion\\setup\\install\\step1' );

		$ops = \IPS\Db::i()->getOperations( 'createTable' );
		$this->assertSame( 'moneymotion_sessions', $ops[0]['schema']['name'] );
	}

	public function testInstalledTableHasAllColumns(): void
	{
		require_once __DIR__ . '/../../applications/moneymotion/setup/install.php';
		call_user_func( '\\IPS\\moneymotion\\setup\\install\\step1' );

		$ops = \IPS\Db::i()->getOperations( 'createTable' );
		$schema = $ops[0]['schema'];
		$columnNames = array_column( $schema['columns'], 'name' );

		$required = array( 'session_id', 'transaction_id', 'invoice_id', 'amount_cents', 'currency', 'status', 'created_at', 'updated_at' );
		foreach ( $required as $col )
		{
			$this->assertContains( $col, $columnNames, "Column missing: {$col}" );
		}
	}

	public function testInstalledTableHasPrimaryKey(): void
	{
		require_once __DIR__ . '/../../applications/moneymotion/setup/install.php';
		call_user_func( '\\IPS\\moneymotion\\setup\\install\\step1' );

		$ops = \IPS\Db::i()->getOperations( 'createTable' );
		$schema = $ops[0]['schema'];

		$primaryFound = false;
		foreach ( $schema['indexes'] as $idx )
		{
			if ( $idx['type'] === 'primary' )
			{
				$primaryFound = true;
				$this->assertContains( 'session_id', $idx['columns'] );
			}
		}
		$this->assertTrue( $primaryFound, 'Primary key must be defined' );
	}

	public function testInstallIsIdempotentWhenTableExists(): void
	{
		require_once __DIR__ . '/../../applications/moneymotion/setup/install.php';

		// Mark table as already existing
		\IPS\Db::i()->mockData['moneymotion_sessions'] = array();

		$result = call_user_func( '\\IPS\\moneymotion\\setup\\install\\step1' );
		$this->assertTrue( $result );

		// Should NOT create the table again
		$ops = \IPS\Db::i()->getOperations( 'createTable' );
		$this->assertEmpty( $ops, 'Install should be idempotent' );
	}
}
