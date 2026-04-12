<?php
/**
 * Tests for findGateway()
 *
 * The findGateway() method has a two-tier lookup:
 * 1. Try constructFromData() (needs hook active)
 * 2. Fall back to stdClass with raw settings (when hook not active)
 * 3. Return NULL if gateway row not in DB at all
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Stubs\TestableWebhookController;

class FindGatewayTest extends TestCase
{
	private TestableWebhookController $controller;

	protected function setUp(): void
	{
		\IPS\Db::reset();
		\IPS\Log::reset();
		$this->controller = new TestableWebhookController;
	}

	public function testReturnsNullWhenNoGatewayInDb(): void
	{
		// No mock data — empty nexus_paymethods
		$result = $this->controller->testFindGateway();
		$this->assertNull( $result );
	}

	public function testReturnsGatewayWhenFoundInDb(): void
	{
		\IPS\Db::i()->mockData['nexus_paymethods'] = array(
			array(
				'm_id' => 1,
				'm_gateway' => 'moneymotion',
				'm_settings' => json_encode( array(
					'api_key' => 'mk_live_abc',
					'webhook_secret' => 'whsec_xyz',
				)),
				'm_active' => 1,
			),
		);

		$result = $this->controller->testFindGateway();
		$this->assertNotNull( $result );
	}

	public function testGatewayHasSettingsAccessible(): void
	{
		$settings = json_encode( array(
			'api_key' => 'mk_live_test',
			'webhook_secret' => 'whsec_test',
		));

		\IPS\Db::i()->mockData['nexus_paymethods'] = array(
			array(
				'm_id' => 1,
				'm_gateway' => 'moneymotion',
				'm_settings' => $settings,
				'm_active' => 1,
			),
		);

		$result = $this->controller->testFindGateway();
		$decoded = json_decode( $result->settings, true );

		$this->assertSame( 'mk_live_test', $decoded['api_key'] );
		$this->assertSame( 'whsec_test', $decoded['webhook_secret'] );
	}

	public function testFallbackStubHasSettings(): void
	{
		// The stub's constructFromData won't fail (it returns a Gateway),
		// but we can verify the settings are accessible on the returned object.
		$settings = json_encode( array( 'webhook_secret' => 'whsec_fallback' ) );

		\IPS\Db::i()->mockData['nexus_paymethods'] = array(
			array(
				'm_id' => 1,
				'm_gateway' => 'moneymotion',
				'm_settings' => $settings,
				'm_active' => 1,
			),
		);

		$result = $this->controller->testFindGateway();
		$this->assertNotNull( $result );
		$this->assertNotNull( $result->settings );
	}

	public function testStubClassExists(): void
	{
		/* The typed fallback stub must be defined — its existence is what
		   turns the old stdClass-based fallback into a documented contract. */
		$this->assertTrue(
			class_exists( '\\IPS\\moneymotion\\modules\\front\\gateway\\_MoneymotionGatewayStub' ),
			'Typed gateway stub class must be defined for the hook-unavailable fallback'
		);
	}

	public function testStubOnlyExposesSettings(): void
	{
		$stub = new \IPS\moneymotion\modules\front\gateway\_MoneymotionGatewayStub( '{"api_key":"mk_test"}' );

		/* Only property that's part of the documented contract */
		$this->assertSame( '{"api_key":"mk_test"}', $stub->settings );

		/* Anything else should NOT exist on the stub — future developers
		   who rely on e.g. $gateway->id will get an undefined-property
		   warning instead of silently getting null. */
		$ref = new \ReflectionClass( $stub );
		$publicProps = array_map(
			fn( $p ) => $p->getName(),
			$ref->getProperties( \ReflectionProperty::IS_PUBLIC )
		);
		$this->assertSame( array( 'settings' ), $publicProps, 'Stub must expose only the documented contract' );
	}

	public function testStubStoresRawJsonString(): void
	{
		$stub = new \IPS\moneymotion\modules\front\gateway\_MoneymotionGatewayStub( '{"webhook_secret":"whsec_xyz"}' );
		$decoded = json_decode( $stub->settings, true );
		$this->assertSame( 'whsec_xyz', $decoded['webhook_secret'] );
	}
}
