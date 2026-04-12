<?php
/**
 * Testable Webhook Controller
 *
 * The real webhook controller reads from php://input and $_SERVER.
 * This subclass exposes protected methods for direct unit/integration
 * testing without HTTP machinery.
 */

namespace Tests\Stubs;

require_once __DIR__ . '/../../applications/moneymotion/modules/front/gateway/webhook.php';

class TestableWebhookController extends \IPS\moneymotion\modules\front\gateway\_webhook
{
	/**
	 * Expose handleCheckoutComplete for testing
	 */
	public function testHandleCheckoutComplete( array $payload )
	{
		return $this->handleCheckoutComplete( $payload );
	}

	/**
	 * Expose handleCheckoutRefunded for testing
	 */
	public function testHandleCheckoutRefunded( array $payload )
	{
		return $this->handleCheckoutRefunded( $payload );
	}

	/**
	 * Expose handleCheckoutFailed for testing
	 */
	public function testHandleCheckoutFailed( array $payload )
	{
		return $this->handleCheckoutFailed( $payload );
	}

	/**
	 * Expose extractPaidAmountCents for testing
	 */
	public function testExtractPaidAmountCents( array $checkoutSession )
	{
		return $this->extractPaidAmountCents( $checkoutSession );
	}

	/**
	 * Expose extractPaidCurrency for testing
	 */
	public function testExtractPaidCurrency( array $checkoutSession )
	{
		return $this->extractPaidCurrency( $checkoutSession );
	}

	/**
	 * Expose verifyWebhookSignature for testing
	 */
	public function testVerifyWebhookSignature( $rawBody, $signature, $secret )
	{
		return $this->verifyWebhookSignature( $rawBody, $signature, $secret );
	}

	/**
	 * Expose generateCsrfToken for testing
	 */
	public function testGenerateCsrfToken( $transactionId, $action )
	{
		return $this->generateCsrfToken( $transactionId, $action );
	}

	/**
	 * Expose validateCsrfToken for testing
	 */
	public function testValidateCsrfToken( $transactionId, $token, $action )
	{
		return $this->validateCsrfToken( $transactionId, $token, $action );
	}

	/**
	 * Expose getClientIp for testing
	 */
	public function testGetClientIp()
	{
		return $this->getClientIp();
	}

	/**
	 * Expose findGateway for testing
	 */
	public function testFindGateway()
	{
		return $this->findGateway();
	}
}
