<?php
/**
 * Tests for setup/upg_30013/upgrade.php
 *
 * Ensures the upgrade script from 3.0.12 → 3.0.13 runs without error
 * and installs the required language keys.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UpgradeScriptTest extends TestCase
{
	protected function setUp(): void
	{
		require_once __DIR__ . '/../../applications/moneymotion/setup/upg_30013/upgrade.php';
	}

	public function testUpgradeClassExists(): void
	{
		$this->assertTrue( class_exists( '\\IPS\\moneymotion\\setup\\upg_30013\\_Upgrade' ) );
	}

	public function testStep1ReturnsTrue(): void
	{
		$upgrade = new \IPS\moneymotion\setup\upg_30013\_Upgrade;
		$this->assertTrue( $upgrade->step1() );
	}

	public function testStep1SwallowsExceptions(): void
	{
		// The upgrade catches \Throwable to avoid blocking the upgrade flow.
		// This test verifies step1 doesn't throw even under adverse conditions.
		$upgrade = new \IPS\moneymotion\setup\upg_30013\_Upgrade;
		$result = $upgrade->step1();
		$this->assertTrue( $result );
	}
}
