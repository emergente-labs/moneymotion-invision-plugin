<?php
/**
 * Tests for the Application.php main class
 *
 * Covers:
 * - get__icon() — must return 'credit-card' for IPS tree rendering
 * - defaultFrontNavigation() — must return empty array (no menu items)
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Testable Application exposes protected methods
 */
class TestableApplication extends \IPS\moneymotion\_Application
{
	public function testGetIcon()
	{
		return $this->get__icon();
	}
}

class ApplicationTest extends TestCase
{
	protected function setUp(): void
	{
		require_once __DIR__ . '/../../applications/moneymotion/Application.php';
	}

	public function testApplicationClassExists(): void
	{
		$this->assertTrue( class_exists( '\\IPS\\moneymotion\\_Application' ) );
	}

	public function testIconIsCreditCard(): void
	{
		$app = new TestableApplication;
		$this->assertSame( 'credit-card', $app->testGetIcon() );
	}

	public function testDefaultFrontNavigationIsEmpty(): void
	{
		$app = new TestableApplication;
		$nav = $app->defaultFrontNavigation();
		$this->assertIsArray( $nav );
		$this->assertEmpty( $nav, 'Payment gateway should have no front navigation items' );
	}

	public function testApplicationExtendsIpsApplication(): void
	{
		$this->assertTrue(
			is_subclass_of( '\\IPS\\moneymotion\\_Application', '\\IPS\\Application' ),
			'App class must extend \\IPS\\Application'
		);
	}
}
