<?php
/**
 * @package		moneymotion Payment Gateway
 * @author		moneymotion
 * @copyright	(c) 2024 moneymotion
 */

namespace IPS\moneymotion\Api;

/**
 * moneymotion API Client
 */
class _Client
{
	/**
	 * @var string moneymotion API base URL
	 */
	const API_BASE_URL = 'https://api.moneymotion.io';

	/**
	 * @var string API Key
	 */
	protected $apiKey;

	/**
	 * Constructor
	 *
	 * @param	string	$apiKey		moneymotion API key
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
	 * @param	string	$description	Description
	 * @param	array	$urls			URLs (success, cancel, failure)
	 * @param	string	$email			Customer email
	 * @param	array	$lineItems		Line items
	 * @param	array	$metadata		Metadata
	 * @param	string	$currency		Currency (default: BRL)
	 * @return	string	Session ID
	 */
	public function createCheckoutSession( $description, $urls, $email, $lineItems, $metadata = array(), $currency = 'BRL' )
	{
		$body = array(
			'json' => array(
				'description'	=> $description,
				'urls'			=> $urls,
				'userInfo'		=> array( 'email' => $email ),
				'lineItems'		=> $lineItems,
			)
		);

		if ( ! empty( $metadata ) )
		{
			$body['json']['metadata'] = (object) $metadata;
		}
		else
		{
			$body['json']['metadata'] = (object) array();
		}

		$response = $this->request( 'checkoutSessions.createCheckoutSession', $body, 'POST', array( 'x-currency' => $currency ) );
		return $response['json']['id'];
	}

	/**
	 * Send request
	 *
	 * @param	string	$endpoint		Endpoint
	 * @param	array	$data			Data
	 * @param	string	$method			Method
	 * @param	array	$extraHeaders	Extra headers
	 * @return	array
	 * @throws	\IPS\Http\Request\Exception
	 * @throws	\RuntimeException
	 */
	protected function request( $endpoint, array $data = array(), $method = 'POST', array $extraHeaders = array() )
	{
		$url = \IPS\Http\Url::external( static::API_BASE_URL . '/' . $endpoint );

		$headers = array(
			'Content-Type'	=> 'application/json',
			'X-API-Key'	=> $this->apiKey,
			'User-Agent'	=> 'MoneyMotion IPS Plugin/3.0.6 (PHP ' . PHP_VERSION . ')',
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
