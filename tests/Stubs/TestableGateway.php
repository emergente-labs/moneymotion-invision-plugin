<?php
/**
 * Testable Gateway Extension
 *
 * Exposes the gateway class methods for direct testing without
 * going through the full IPS checkout flow.
 */

namespace Tests\Stubs;

require_once __DIR__ . '/../../applications/moneymotion/extensions/nexus/Gateway/moneymotion.php';

class TestableGateway extends \IPS\moneymotion\extensions\nexus\Gateway\_moneymotion
{
	/**
	 * Override settings for testing
	 */
	public function setTestSettings( array $settings )
	{
		$this->settings = json_encode( $settings );
	}

	/**
	 * Expose generateCsrfToken for testing
	 */
	public function testGenerateCsrfToken( $transactionId, $action )
	{
		return $this->generateCsrfToken( $transactionId, $action );
	}
}
