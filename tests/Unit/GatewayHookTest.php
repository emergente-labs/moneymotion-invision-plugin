<?php
/**
 * Tests for the Gateway hook (hooks/Gateway.php)
 *
 * Verifies that the hook properly injects moneymotion into the
 * gateways() list while preserving existing gateways.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Gateway hook (hooks/Gateway.php)
 *
 * IPS at runtime replaces _HOOK_CLASS_ with the real parent class at
 * autoload time. For testing we simulate that replacement by reading
 * the hook source, substituting _HOOK_CLASS_ with \IPS\nexus\Gateway,
 * and eval'ing the result.
 */
class GatewayHookTest extends TestCase
{
	protected function setUp(): void
	{
		if ( !class_exists( 'moneymotion_hook_Gateway_Test', false ) )
		{
			$src = file_get_contents( __DIR__ . '/../../applications/moneymotion/hooks/Gateway.php' );

			// Strip PHP open tag, remove the SUITE_UNIQUE_KEY guard and file-open comment
			$src = preg_replace( '/^\/\/\<\?php/', '', $src );
			$src = preg_replace( '/<\?php/', '', $src );

			// Replace _HOOK_CLASS_ with actual parent + rename class to avoid collision
			$src = str_replace( '_HOOK_CLASS_', '\\IPS\\nexus\\Gateway', $src );
			$src = str_replace( 'moneymotion_hook_Gateway', 'moneymotion_hook_Gateway_Test', $src );

			// Remove the SUITE_UNIQUE_KEY exit guard so eval() can run
			$src = preg_replace(
				'/if\s*\(\s*!\\\\defined\([^)]+\)\s*\)\s*\{[^}]+\}/s',
				'',
				$src
			);

			eval( $src );
		}
	}

	public function testHookAddsMoneymotionToGateways(): void
	{
		$gateways = \moneymotion_hook_Gateway_Test::gateways();

		$this->assertArrayHasKey( 'moneymotion', $gateways );
		$this->assertSame(
			'IPS\moneymotion\extensions\nexus\Gateway\moneymotion',
			$gateways['moneymotion']
		);
	}

	public function testHookPreservesParentGateways(): void
	{
		$gateways = \moneymotion_hook_Gateway_Test::gateways();
		$this->assertNotEmpty( $gateways );
	}

	public function testHookClassExtendsNexusGateway(): void
	{
		$this->assertTrue(
			is_subclass_of( 'moneymotion_hook_Gateway_Test', '\\IPS\\nexus\\Gateway' ),
			'Hook class must extend \\IPS\\nexus\\Gateway'
		);
	}

	public function testHookUsesCorrectClassSource(): void
	{
		$src = file_get_contents( __DIR__ . '/../../applications/moneymotion/hooks/Gateway.php' );

		// Must use the magic placeholder
		$this->assertStringContainsString( '_HOOK_CLASS_', $src );

		// Must follow IPS hook naming convention: {app}_hook_{name}
		$this->assertStringContainsString( 'moneymotion_hook_Gateway', $src );

		// Must have the SUITE_UNIQUE_KEY guard
		$this->assertStringContainsString( 'SUITE_UNIQUE_KEY', $src );

		// Must call parent::gateways() to preserve existing gateways
		$this->assertStringContainsString( 'parent::gateways()', $src );
	}
}
