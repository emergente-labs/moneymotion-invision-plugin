<?php
/**
 * Webhook Payload Fixtures
 *
 * Based on official moneymotion API docs:
 * https://docs.moneymotion.io/webhooks
 * https://docs.moneymotion.io/api/checkoutsessions/getCompletedOrPendingCheckoutSessionInfo
 *
 * The webhook payload structure per docs is:
 * {
 *   "checkoutSession": {
 *     "id": "uuid",
 *     "createdByCustomerId": "uuid",
 *     "createdOnCustomerSessionId": "uuid",
 *     "status": "completed",
 *     "totalInCents": 5000,
 *     "metadata": {},
 *     "storeId": "uuid"
 *   },
 *   "event": "checkout_session:complete",
 *   "customer": {
 *     "firstName": "John",
 *     "lastName": "Doe",
 *     "address": "123 Main St",
 *     "city": "Lisbon",
 *     "region": "LI",
 *     "postalCode": "1000-001",
 *     "country": "PT",
 *     "email": "john@example.com",
 *     "paymentMethodInfo": {
 *       "type": "card",
 *       "lastFourDigits": "4242",
 *       "cardBrand": "visa"
 *     }
 *   }
 * }
 *
 * IMPORTANT: The webhook payload does NOT include:
 * - timestamp field (plugin's replay protection is dead code)
 * - currency field in checkoutSession (only totalInCents)
 * - amountInCents, amount_cents, amountCents (uses totalInCents)
 * - lineItems in the webhook (only in getCheckoutSessionInfo response)
 */

namespace Tests\Fixtures;

class WebhookPayloads
{
	/**
	 * Real moneymotion webhook: checkout_session:complete
	 * This is what moneymotion actually sends per their docs.
	 */
	public static function completeReal( $sessionId = 'cs_test_123', $totalCents = 5000 )
	{
		return array(
			'event' => 'checkout_session:complete',
			'checkoutSession' => array(
				'id' => $sessionId,
				'createdByCustomerId' => 'cust_abc123',
				'createdOnCustomerSessionId' => 'sess_xyz789',
				'status' => 'completed',
				'totalInCents' => $totalCents,
				'metadata' => (object) array(
					'invoice_id' => 100,
					'transaction_id' => 200,
					'gateway_id' => 1,
				),
				'storeId' => 'store_001',
			),
			'customer' => array(
				'firstName' => 'John',
				'lastName' => 'Doe',
				'address' => '123 Main St',
				'city' => 'Lisbon',
				'region' => 'LI',
				'postalCode' => '1000-001',
				'country' => 'PT',
				'email' => 'john@example.com',
				'paymentMethodInfo' => array(
					'type' => 'card',
					'lastFourDigits' => '4242',
					'cardBrand' => 'visa',
				),
			),
		);
	}

	/**
	 * Real moneymotion webhook: checkout_session:new
	 */
	public static function newSession( $sessionId = 'cs_test_new' )
	{
		return array(
			'event' => 'checkout_session:new',
			'checkoutSession' => array(
				'id' => $sessionId,
				'createdByCustomerId' => 'cust_abc123',
				'createdOnCustomerSessionId' => 'sess_xyz789',
				'status' => 'pending',
				'totalInCents' => 5000,
				'metadata' => (object) array(),
				'storeId' => 'store_001',
			),
			'customer' => array(
				'email' => 'john@example.com',
			),
		);
	}

	/**
	 * Real moneymotion webhook: checkout_session:refunded
	 */
	public static function refunded( $sessionId = 'cs_test_123', $totalCents = 5000 )
	{
		return array(
			'event' => 'checkout_session:refunded',
			'checkoutSession' => array(
				'id' => $sessionId,
				'createdByCustomerId' => 'cust_abc123',
				'createdOnCustomerSessionId' => 'sess_xyz789',
				'status' => 'refunded',
				'totalInCents' => $totalCents,
				'metadata' => (object) array(),
				'storeId' => 'store_001',
			),
			'customer' => array(
				'firstName' => 'John',
				'lastName' => 'Doe',
				'email' => 'john@example.com',
			),
		);
	}

	/**
	 * Real moneymotion webhook: checkout_session:expired
	 */
	public static function expired( $sessionId = 'cs_test_123' )
	{
		return array(
			'event' => 'checkout_session:expired',
			'checkoutSession' => array(
				'id' => $sessionId,
				'createdByCustomerId' => 'cust_abc123',
				'createdOnCustomerSessionId' => 'sess_xyz789',
				'status' => 'expired',
				'totalInCents' => 5000,
				'metadata' => (object) array(),
				'storeId' => 'store_001',
			),
			'customer' => array(
				'email' => 'john@example.com',
			),
		);
	}

	/**
	 * Real moneymotion webhook: checkout_session:disputed
	 */
	public static function disputed( $sessionId = 'cs_test_123' )
	{
		return array(
			'event' => 'checkout_session:disputed',
			'checkoutSession' => array(
				'id' => $sessionId,
				'createdByCustomerId' => 'cust_abc123',
				'createdOnCustomerSessionId' => 'sess_xyz789',
				'status' => 'disputed',
				'totalInCents' => 5000,
				'metadata' => (object) array(),
				'storeId' => 'store_001',
			),
			'customer' => array(
				'firstName' => 'John',
				'lastName' => 'Doe',
				'email' => 'john@example.com',
			),
		);
	}

	/**
	 * Sign a payload with HMAC-SHA512 (how moneymotion signs webhooks)
	 */
	public static function sign( array $payload, $secret )
	{
		$rawBody = json_encode( $payload );
		return base64_encode( hash_hmac( 'sha512', $rawBody, $secret, true ) );
	}
}
