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
	 * @var int Per-request timeout in seconds. IPS defaults to 10s
	 * (\IPS\DEFAULT_REQUEST_TIMEOUT); a payment API doing fraud checks
	 * can occasionally exceed that, so we bump it slightly.
	 */
	const HTTP_TIMEOUT_SECONDS = 15;

	/**
	 * @var int Total attempts for a single API call (1 initial + retries).
	 * We only retry on cURL-level failures (DNS / connect refused /
	 * timeout-before-bytes), where the server has not seen the request and
	 * a retry cannot create a duplicate session.
	 */
	const MAX_ATTEMPTS = 2;

	/**
	 * @var int Sleep between attempts. Long enough that a transient blip
	 * has time to clear; short enough that the customer doesn't bail.
	 */
	const RETRY_BACKOFF_MICROSECONDS = 250000;

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

		$apiKey = ( \is_array( $settings ) && isset( $settings['api_key'] ) ) ? trim( (string) $settings['api_key'] ) : '';

		if ( $apiKey === '' )
		{
			throw new \InvalidArgumentException( 'moneymotion API key is not configured.' );
		}

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

		$response = $this->request( 'checkoutSessions.createCheckoutSession', $body, 'POST', array( 'x-currency' => $currency ) );

		/* Strict response-shape validation. The docs shape is
		   { result: { data: { json: { checkoutSessionId: "..." } } } }
		   but a misbehaving edge (WAF / CDN / proxy) could return 200 with
		   a different body. Check every level rather than relying on PHP's
		   silent null-coalescence, which would let an empty sessionId
		   propagate into the redirect URL as `https://moneymotion.io/checkout/`
		   — a broken checkout page for the customer. */
		if ( !isset( $response['result']['data']['json'] ) || !\is_array( $response['result']['data']['json'] ) )
		{
			throw new \RuntimeException( 'moneymotion API returned an unexpected response shape — missing result.data.json envelope.' );
		}

		$sessionId = $response['result']['data']['json']['checkoutSessionId'] ?? null;

		if ( !\is_string( $sessionId ) || trim( $sessionId ) === '' )
		{
			throw new \RuntimeException( 'moneymotion API returned an empty checkoutSessionId.' );
		}

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
			'User-Agent'	=> 'moneymotion IPS Plugin/3.0.18 (PHP ' . PHP_VERSION . ')',
		);
		$headers = array_merge( $headers, $extraHeaders );

		$payload = NULL;
		if ( $method === 'POST' )
		{
			$payload = json_encode( $data );

			if ( $payload === FALSE )
			{
				throw new \RuntimeException( 'Unable to encode request payload.' );
			}
		}

		/* Network-level retry loop. We retry only on \IPS\Http\Request\Exception
		   — cURL-level failures (DNS, connect refused, timeout before any bytes
		   reach the wire). In those cases the server provably hasn't processed
		   anything, so a retry cannot create a duplicate checkout session.
		   We deliberately do NOT retry HTTP 5xx responses: the server received
		   the request and may have side-effected on it. */
		$attempt = 0;
		while ( TRUE )
		{
			try
			{
				$request = $url->request( self::HTTP_TIMEOUT_SECONDS )->setHeaders( $headers );
				$response = ( $method === 'POST' ) ? $request->post( $payload ) : $request->get();
				break;
			}
			catch ( \IPS\Http\Request\Exception $e )
			{
				$attempt++;
				if ( $attempt >= self::MAX_ATTEMPTS )
				{
					throw $e;
				}
				usleep( self::RETRY_BACKOFF_MICROSECONDS );
			}
		}

		$httpCode = $response->httpResponseCode;
		$decoded = json_decode( $response->content, TRUE );

		if ( !\is_array( $decoded ) )
		{
			throw new \RuntimeException( 'Invalid JSON response from moneymotion API.' );
		}

		if ( $httpCode < 200 || $httpCode >= 300 )
		{
			$errorMessage = isset( $decoded['error'] ) ? ( \is_array( $decoded['error'] ) ? json_encode( $decoded['error'] ) : $decoded['error'] ) : 'Unknown API error';
			throw new \RuntimeException( $errorMessage );
		}

		/* Some gateways return 200 OK with an `error` envelope (and no
		   success data) — e.g. when the body parsed but business logic
		   rejected the call. Treat that as a failure instead of letting
		   downstream code hit undefined keys. */
		if ( isset( $decoded['error'] ) && !isset( $decoded['result'] ) )
		{
			$errorMessage = \is_array( $decoded['error'] ) ? json_encode( $decoded['error'] ) : (string) $decoded['error'];
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
