<?php
/**
 * @package		MoneyMotion Payment Gateway
 * @author		MoneyMotion
 * @copyright	(c) 2024 MoneyMotion
 */

namespace IPS\moneymotion\Api;

/**
 * MoneyMotion API Client
 */
class _Client
{
	/**
	 * @var string MoneyMotion API base URL
	 */
	const API_BASE_URL = 'https://api.moneymotion.io';

	/**
	 * @var string API Key
	 */
	protected $apiKey;

	/**
	 * Constructor
	 *
	 * @param	string	$apiKey		MoneyMotion API key
	 * @return	void
	 */
	public function __construct( $apiKey )
	{
		$this->apiKey = $apiKey;
	}

	/**
	 * Create a new instance using the configured API key from gateway settings
	 *
	 * @param	\IPS\nexus\Gateway	$gateway	The gateway object
	 * @return	static
	 */
	public static function fromGateway( \IPS\nexus\Gateway $gateway )
	{
		$settings = json_decode( $gateway->settings, TRUE );
		return new static( $settings['api_key'] );
	}

	/**
	 * Create a checkout session
	 *
	 * @param	string	$description		Description of the checkout
	 * @param	array	$urls				Array with 'success', 'cancel', 'failure' URLs
	 * @param	string	$email				Customer email address
	 * @param	array	$lineItems			Array of line items, each with 'name', 'description', 'pricePerItemInCents', 'quantity'
	 * @param	array	$metadata			Optional metadata
	 * @return	string						Checkout session ID
	 * @throws	\IPS\Http\Request\Exception
	 * @throws	\RuntimeException
	 */
	public function createCheckoutSession( $description, array $urls, $email, array $lineItems, array $metadata = array(), $currency = 'USD' )
	{
		$body = array(
			'json' => array(
				'description'	=> $description,
				'urls'			=> array(
					'success'	=> $urls['success'],
					'cancel'	=> $urls['cancel'],
					'failure'	=> $urls['failure'],
				),
				'userInfo'		=> array(
					'email'		=> $email,
				),
				'lineItems'		=> $lineItems,
			),
		);

		if ( !empty( $metadata ) )
		{
			$body['json']['metadata'] = $metadata;
		}

		$extraHeaders = array( 'x-currency' => $currency );
		$response = $this->request( 'checkoutSessions.createCheckoutSession', $body, 'POST', $extraHeaders );

		if ( isset( $response['result']['data']['json']['checkoutSessionId'] ) )
		{
			return $response['result']['data']['json']['checkoutSessionId'];
		}

		throw new \RuntimeException( 'MoneyMotion API did not return a checkout session ID' );
	}

	/**
	 * Make an API request
	 *
	 * @param	string	$endpoint	API endpoint (appended to base URL)
	 * @param	array	$data		Request body data
	 * @param	string	$method		HTTP method (POST, GET, etc.)
	 * @return	array				Decoded JSON response
	 * @throws	\IPS\Http\Request\Exception
	 * @throws	\RuntimeException
	 */
	protected function request( $endpoint, array $data = array(), $method = 'POST', array $extraHeaders = array() )
	{
		$url = \IPS\Http\Url::external( static::API_BASE_URL . '/' . $endpoint );

		$headers = array(
			'Content-Type'	=> 'application/json',
			'X-API-Key'	=> $this->apiKey,
		);
		$headers = array_merge( $headers, $extraHeaders );

		$request = $url->request()
			->setHeaders( $headers );

		if ( $method === 'POST' )
		{
			$response = $request->post( json_encode( $data ) );
		}
		else
		{
			$response = $request->get();
		}

		$httpCode = $response->httpResponseCode;
		$decoded = json_decode( $response->content, TRUE );

		if ( $httpCode < 200 || $httpCode >= 300 )
		{
			$errorMessage = isset( $decoded['error'] ) ? ( \is_array( $decoded['error'] ) ? json_encode( $decoded['error'] ) : $decoded['error'] ) : 'Unknown API error';
			\IPS\Log::log( "MoneyMotion API error ({$httpCode}): {$errorMessage}", 'moneymotion' );
			throw new \RuntimeException( $errorMessage );
		}

		return $decoded;
	}

	/**
	 * Verify a webhook signature
	 *
	 * @param	string	$rawBody		Raw request body
	 * @param	string	$signature		Signature from request header
	 * @param	string	$secret			Webhook signing secret
	 * @return	bool
	 */
	public static function verifyWebhookSignature( $rawBody, $signature, $secret )
	{
		$computed = base64_encode( hash_hmac( 'sha512', $rawBody, $secret, TRUE ) );
		return hash_equals( $computed, $signature );
	}
}
