<?php
/**
 * Tests for webhook.php::manage() and the main webhook() dispatcher
 *
 * manage() is the IPS entry point — it just calls webhook().
 * This test verifies the dispatch works and the controller can be instantiated.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WebhookManageDispatchTest extends TestCase
{
	public function testWebhookControllerClassExists(): void
	{
		require_once __DIR__ . '/../../applications/moneymotion/modules/front/gateway/webhook.php';
		$this->assertTrue( class_exists( '\\IPS\\moneymotion\\modules\\front\\gateway\\_webhook' ) );
	}

	public function testWebhookControllerExtendsDispatcherController(): void
	{
		require_once __DIR__ . '/../../applications/moneymotion/modules/front/gateway/webhook.php';
		$this->assertTrue(
			is_subclass_of(
				'\\IPS\\moneymotion\\modules\\front\\gateway\\_webhook',
				'\\IPS\\Dispatcher\\Controller'
			),
			'Webhook controller must extend IPS Dispatcher Controller'
		);
	}

	public function testWebhookControllerHasRequiredMethods(): void
	{
		require_once __DIR__ . '/../../applications/moneymotion/modules/front/gateway/webhook.php';

		$class = new \ReflectionClass( '\\IPS\\moneymotion\\modules\\front\\gateway\\_webhook' );

		// IPS expects manage() as entry point
		$this->assertTrue( $class->hasMethod( 'manage' ) );

		// Return URL handlers (IPS calls via do=X)
		$this->assertTrue( $class->hasMethod( 'success' ) );
		$this->assertTrue( $class->hasMethod( 'cancel' ) );
		$this->assertTrue( $class->hasMethod( 'failure' ) );
	}

	public function testManageMethodDispatchesToWebhook(): void
	{
		require_once __DIR__ . '/../../applications/moneymotion/modules/front/gateway/webhook.php';

		// Read the source to verify manage() calls webhook()
		$src = file_get_contents( __DIR__ . '/../../applications/moneymotion/modules/front/gateway/webhook.php' );

		// Extract manage() method body
		$this->assertMatchesRegularExpression(
			'/function\s+manage\(\)\s*\{[^}]*\$this->webhook\(\)/s',
			$src,
			'manage() must call $this->webhook()'
		);
	}
}
