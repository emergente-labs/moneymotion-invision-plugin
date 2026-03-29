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
		$this->apiKey = trim( (string) $apiKey );
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

		if ( !\is_array( $settings ) )
		{
			\IPS\Log::log( "moneymotion API: fromGateway - gateway settings could not be decoded (gateway_id: {$gateway->id}, raw: " . mb_substr( (string) $gateway->settings, 0, 200 ) . ")", 'moneymotion' );
		}

		$apiKey = ( \is_array( $settings ) && isset( $settings['api_key'] ) ) ? trim( (string) $settings['api_key'] ) : '';

		if ( $apiKey === '' )
		{
			\IPS\Log::log( "moneymotion API: fromGateway - API key is empty or missing (gateway_id: {$gateway->id})", 'moneymotion' );
			throw new \InvalidArgumentException( 'moneymotion API key is not configured.' );
		}

		$maskedKey = mb_substr( $apiKey, 0, 10 ) . '***';
		\IPS\Log::log( "moneymotion API: fromGateway - client created (gateway_id: {$gateway->id}, key: {$maskedKey})", 'moneymotion' );

		return new static( $apiKey );
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

		\IPS\Log::log( "moneymotion API: createCheckoutSession called - email: {$email}, currency: {$currency}, description: {$description}, lineItems: " . json_encode( $lineItems ) . ", metadata: " . json_encode( $metadata ), 'moneymotion' );

		$response = $this->request( 'checkoutSessions.createCheckoutSession', $body, 'POST', array( 'x-currency' => $currency ) );

		if ( !isset( $response['result']['data']['json']['checkoutSessionId'] ) )
		{
			\IPS\Log::log( "moneymotion API: createCheckoutSession response missing checkoutSessionId - full response: " . json_encode( $response ), 'moneymotion' );
			throw new \RuntimeException( 'moneymotion API did not return a checkout session ID.' );
		}

		$sessionId = $response['result']['data']['json']['checkoutSessionId'];
		\IPS\Log::log( "moneymotion API: createCheckoutSession success - sessionId: {$sessionId}", 'moneymotion' );

		return $sessionId;
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
		$apiKey = trim( (string) $this->apiKey );

		if ( $apiKey === '' )
		{
			throw new \RuntimeException( 'moneymotion API key is empty.' );
		}

		$headers = array(
			'Content-Type'	=> 'application/json',
			'x-api-key'	=> $apiKey,
			'User-Agent'	=> 'moneymotion IPS Plugin/3.0.16 (PHP ' . PHP_VERSION . ')',
		);
		$headers = array_merge( $headers, $extraHeaders );

		\IPS\Log::log( "moneymotion API: {$method} {$endpoint} - sending request", 'moneymotion' );

		$request = $url->request()
			->setHeaders( $headers );

		try
		{
			if ( $method === 'POST' )
			{
				$payload = json_encode( $data );

				if ( $payload === FALSE )
				{
					\IPS\Log::log( "moneymotion API: {$endpoint} - failed to encode JSON payload", 'moneymotion' );
					throw new \RuntimeException( 'Unable to encode request payload.' );
				}

				$response = $request->post( $payload );
			}
			else
			{
				$response = $request->get();
			}
		}
		catch ( \IPS\Http\Request\Exception $e )
		{
			\IPS\Log::log( "moneymotion API: {$endpoint} - HTTP request exception: " . $e->getMessage() . " (code: " . $e->getCode() . ")", 'moneymotion' );
			throw $e;
		}

		$httpCode = $response->httpResponseCode;
		$responseBody = $response->content;
		$decoded = json_decode( $responseBody, TRUE );

		\IPS\Log::log( "moneymotion API: {$endpoint} - response HTTP {$httpCode}, body length: " . \strlen( $responseBody ), 'moneymotion' );

		if ( !\is_array( $decoded ) )
		{
			\IPS\Log::log( "moneymotion API: {$endpoint} - invalid JSON response, raw body: " . mb_substr( $responseBody, 0, 500 ), 'moneymotion' );
			throw new \RuntimeException( 'Invalid JSON response from moneymotion API.' );
		}

		if ( $httpCode < 200 || $httpCode >= 300 )
		{
			$errorMessage = isset( $decoded['error'] ) ? ( \is_array( $decoded['error'] ) ? json_encode( $decoded['error'] ) : $decoded['error'] ) : 'Unknown API error';
			\IPS\Log::log( "moneymotion API: {$endpoint} - API error HTTP {$httpCode}: {$errorMessage}, full response: " . json_encode( $decoded ), 'moneymotion' );
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
