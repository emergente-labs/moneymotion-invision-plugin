<?php
/**
 * Tests for the payment screen template (paymentScreen.phtml)
 *
 * Validates that the template file exists and references the correct
 * language keys and form structure.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PaymentScreenTemplateTest extends TestCase
{
	private string $templatePath;

	protected function setUp(): void
	{
		$this->templatePath = __DIR__ . '/../../applications/moneymotion/dev/html/front/gateway/paymentScreen.phtml';
	}

	public function testTemplateFileExists(): void
	{
		$this->assertFileExists( $this->templatePath );
	}

	public function testTemplateHasIpsParametersDirective(): void
	{
		$content = file_get_contents( $this->templatePath );
		$this->assertStringContainsString( '<ips:template', $content );
		$this->assertStringContainsString( 'parameters=', $content );
	}

	public function testTemplateReferencesGatewayAmount(): void
	{
		$content = file_get_contents( $this->templatePath );
		$this->assertStringContainsString( '$gateway', $content );
		$this->assertStringContainsString( '$amount', $content );
	}

	public function testTemplateUsesRequiredLangKeys(): void
	{
		$content = file_get_contents( $this->templatePath );
		// These must exist in lang.php too
		$this->assertStringContainsString( 'moneymotion_redirect_message', $content );
		$this->assertStringContainsString( 'moneymotion_pay_button', $content );
	}

	public function testTemplateHasHiddenPaymentMethodInput(): void
	{
		$content = file_get_contents( $this->templatePath );
		$this->assertStringContainsString( 'name="paymentMethod"', $content );
		$this->assertStringContainsString( '$gateway->id', $content );
	}

	public function testTemplateHasSubmitButton(): void
	{
		$content = file_get_contents( $this->templatePath );
		$this->assertStringContainsString( 'type="submit"', $content );
	}

	public function testTemplateUsesIpsClassConventions(): void
	{
		$content = file_get_contents( $this->templatePath );
		$this->assertStringContainsString( 'ipsButton', $content, 'Should use IPS CSS classes' );
	}

	/**
	 * Verify all language keys referenced in template exist in lang.php
	 */
	public function testAllLangKeysInTemplateExistInLangFile(): void
	{
		$content = file_get_contents( $this->templatePath );

		// Extract all {lang="key"} references
		preg_match_all( '/\{lang="([^"]+)"\}/', $content, $matches );
		$usedKeys = $matches[1];

		$lang = array();
		include __DIR__ . '/../../applications/moneymotion/dev/lang.php';

		foreach ( $usedKeys as $key )
		{
			$this->assertArrayHasKey(
				$key,
				$lang,
				"Template references {lang=\"{$key}\"} but it's not in lang.php"
			);
		}
	}
}
