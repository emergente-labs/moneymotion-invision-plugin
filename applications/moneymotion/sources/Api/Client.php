<?php
/**
 * @package		moneymotion Payment Gateway
 * @author		moneymotion
 * @copyright	(c) 2024 moneymotion
 */

namespace IPS\moneymotion\Api;

/**
 * moneymotion API Client
 *
 * Talks to the backend over the Effect RPC wire format (NDJSON) at POST /rpc.
 * The legacy tRPC path (/trpc/...) went through a compatibility proxy that
 * converted tRPC → Effect RPC on the server; this client now calls Effect RPC
 * directly so the plugin no longer depends on that proxy.
 */
class _Client
{
	/**
	 * @var string moneymotion API base URL
	 */
	const API_BASE_URL = 'https://api.moneymotion.io';

	/**
	 * @var string Effect RPC endpoint path
	 */
	const RPC_ENDPOINT = '/rpc';

	/**
	 * @var string Fallback version used only if the installed application row cannot be read
	 */
	const PLUGIN_VERSION_FALLBACK = '0.0.0';

	/**
	 * @var string|null Cached plugin version
	 */
	protected static $cachedPluginVersion = NULL;

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
		$payload = array(
			'description'	=> $description,
			'urls'			=> $urls,
			'userInfo'		=> array( 'email' => $email ),
			'lineItems'		=> $lineItems,
		);

		if ( ! empty( $metadata ) )
		{
			$payload['metadata'] = (object) $metadata;
		}

		$result = $this->rpcCall(
			'CheckoutSessionsCreateCheckoutSession',
			$payload,
			array( 'x-currency' => $currency )
		);

		if ( ! \is_array( $result ) || ! isset( $result['checkoutSessionId'] ) )
		{
			throw new \RuntimeException( 'moneymotion RPC response missing checkoutSessionId.' );
		}

		$sessionId = $result['checkoutSessionId'];

		/* Guard against a 200 Success whose value contains an empty id — that
		   would propagate into the redirect URL as `https://moneymotion.io/checkout/`
		   and land the customer on a broken checkout page. */
		if ( !\is_string( $sessionId ) || trim( $sessionId ) === '' )
		{
			throw new \RuntimeException( 'moneymotion RPC returned an empty checkoutSessionId.' );
		}

		return $sessionId;
	}

	/**
	 * Execute a single Effect RPC call over HTTP.
	 *
	 * Wire format:
	 *   POST /rpc  Content-Type: application/ndjson
	 *   Body: one JSON object per line, starting with:
	 *     {"_tag":"Request","id":"0","tag":"<RpcName>","payload":<payload>,"headers":[]}\n
	 *
	 *   Response is NDJSON. The terminal message we care about is:
	 *     {"_tag":"Exit","exit":{"_tag":"Success","value":<value>}}
	 *   On failure:
	 *     {"_tag":"Exit","exit":{"_tag":"Failure","cause":<cause>}}
	 *     {"_tag":"Defect",...}
	 *
	 * @param	string	$tag			PascalCase RPC name (e.g. "CheckoutSessionsCreateCheckoutSession")
	 * @param	mixed	$payload		RPC input (already deserialized — no superjson envelope)
	 * @param	array	$extraHeaders	Additional HTTP headers (e.g. x-currency)
	 * @return	mixed	The decoded Success value
	 * @throws	\IPS\Http\Request\Exception
	 * @throws	\RuntimeException
	 */
	protected function rpcCall( $tag, $payload, array $extraHeaders = array() )
	{
		$apiKey = trim( (string) $this->apiKey );
		if ( $apiKey === '' )
		{
			throw new \RuntimeException( 'moneymotion API key is empty.' );
		}

		$url = \IPS\Http\Url::external( static::API_BASE_URL . static::RPC_ENDPOINT );

		$headers = array(
			'Content-Type'	=> 'application/ndjson',
			'Accept'		=> 'application/ndjson',
			'x-api-key'		=> $apiKey,
			'User-Agent'	=> 'moneymotion IPS Plugin/' . static::pluginVersion() . ' (PHP ' . PHP_VERSION . ')',
		);
		$headers = array_merge( $headers, $extraHeaders );

		$envelope = array(
			'_tag'		=> 'Request',
			'id'		=> '0',
			'tag'		=> (string) $tag,
			'payload'	=> $payload,
			'headers'	=> array(),
		);

		$body = json_encode( $envelope );
		if ( $body === FALSE )
		{
			throw new \RuntimeException( 'Unable to encode RPC request envelope: ' . json_last_error_msg() );
		}
		$body .= "\n";

		$response = $url->request()
			->setHeaders( $headers )
			->post( $body );

		$httpCode = (int) $response->httpResponseCode;
		$content = (string) $response->content;

		if ( $httpCode < 200 || $httpCode >= 300 )
		{
			throw new \RuntimeException( sprintf(
				'moneymotion RPC %s returned HTTP %d: %s',
				$tag,
				$httpCode,
				mb_substr( $content, 0, 500 )
			) );
		}

		return static::parseRpcExit( $tag, $content );
	}

	/**
	 * Parse an Effect RPC NDJSON response and return the Success value.
	 *
	 * @param	string	$tag		RPC name, for error messages
	 * @param	string	$content	Full response body
	 * @return	mixed	The Success value (typically an associative array)
	 * @throws	\RuntimeException
	 */
	protected static function parseRpcExit( $tag, $content )
	{
		$lines = preg_split( '/\r?\n/', trim( $content ) );
		if ( ! \is_array( $lines ) )
		{
			throw new \RuntimeException( sprintf(
				'moneymotion RPC %s: unable to split response into NDJSON lines.',
				$tag
			) );
		}

		foreach ( $lines as $line )
		{
			$line = trim( (string) $line );
			if ( $line === '' )
			{
				continue;
			}

			$message = json_decode( $line, TRUE );
			if ( ! \is_array( $message ) || ! isset( $message['_tag'] ) )
			{
				continue;
			}

			$msgTag = (string) $message['_tag'];

			if ( $msgTag === 'Defect' )
			{
				throw new \RuntimeException( sprintf(
					'moneymotion RPC %s failed with defect: %s',
					$tag,
					json_encode( $message )
				) );
			}

			if ( $msgTag !== 'Exit' || ! isset( $message['exit'] ) || ! \is_array( $message['exit'] ) )
			{
				continue;
			}

			$exit = $message['exit'];
			$exitTag = isset( $exit['_tag'] ) ? (string) $exit['_tag'] : '';

			if ( $exitTag === 'Success' )
			{
				return isset( $exit['value'] ) ? $exit['value'] : NULL;
			}

			$cause = isset( $exit['cause'] ) ? $exit['cause'] : NULL;
			throw new \RuntimeException( sprintf(
				'moneymotion RPC %s failed: %s',
				$tag,
				static::describeRpcCause( $cause )
			) );
		}

		throw new \RuntimeException( sprintf(
			'moneymotion RPC %s: response contained no Exit message. Body: %s',
			$tag,
			mb_substr( $content, 0, 500 )
		) );
	}

	/**
	 * Walk an Effect Cause tree and produce a short human-readable description.
	 *
	 * Cause shapes (simplified):
	 *   { _tag: "Fail",       error:   <RpcError|any> }
	 *   { _tag: "Die",        defect:  <unknown> }
	 *   { _tag: "Interrupt" }
	 *   { _tag: "Sequential"|"Parallel", left: <Cause>, right: <Cause> }
	 *   { _tag: "Empty" }
	 *
	 * @param	mixed	$cause
	 * @return	string
	 */
	protected static function describeRpcCause( $cause )
	{
		if ( ! \is_array( $cause ) )
		{
			if ( \is_string( $cause ) && $cause !== '' )
			{
				return $cause;
			}
			return json_encode( $cause );
		}

		$message = static::extractCauseMessage( $cause );
		if ( $message !== NULL && $message !== '' )
		{
			return $message;
		}

		return json_encode( $cause );
	}

	/**
	 * Recursive helper for describeRpcCause().
	 *
	 * @param	array	$node
	 * @return	string|null
	 */
	protected static function extractCauseMessage( array $node )
	{
		$tag = isset( $node['_tag'] ) ? (string) $node['_tag'] : '';

		if ( $tag === 'Fail' && isset( $node['error'] ) )
		{
			$error = $node['error'];
			if ( \is_array( $error ) )
			{
				if ( isset( $error['message'] ) && \is_string( $error['message'] ) )
				{
					return $error['message'];
				}
				if ( isset( $error['_tag'] ) && \is_string( $error['_tag'] ) )
				{
					return $error['_tag'];
				}
				return json_encode( $error );
			}
			return \is_string( $error ) ? $error : json_encode( $error );
		}

		if ( $tag === 'Die' && isset( $node['defect'] ) )
		{
			$defect = $node['defect'];
			if ( \is_array( $defect ) && isset( $defect['message'] ) && \is_string( $defect['message'] ) )
			{
				return $defect['message'];
			}
			return \is_string( $defect ) ? $defect : json_encode( $defect );
		}

		if ( $tag === 'Interrupt' )
		{
			return 'Operation was interrupted';
		}

		if ( $tag === 'Sequential' || $tag === 'Parallel' )
		{
			if ( isset( $node['left'] ) && \is_array( $node['left'] ) )
			{
				$left = static::extractCauseMessage( $node['left'] );
				if ( $left !== NULL && $left !== '' )
				{
					return $left;
				}
			}
			if ( isset( $node['right'] ) && \is_array( $node['right'] ) )
			{
				return static::extractCauseMessage( $node['right'] );
			}
		}

		return NULL;
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

	/**
	 * Resolve the installed plugin version from the IPS application row.
	 *
	 * Falls back to PLUGIN_VERSION_FALLBACK if IPS isn't loaded or the row is
	 * unreadable — this code path runs in a PHP request handler so
	 * `\IPS\Application::load()` is normally safe to call, but we don't want a
	 * User-Agent lookup to take down a checkout create.
	 *
	 * @return	string
	 */
	protected static function pluginVersion()
	{
		if ( static::$cachedPluginVersion !== NULL )
		{
			return static::$cachedPluginVersion;
		}

		$version = static::PLUGIN_VERSION_FALLBACK;
		try
		{
			if ( \class_exists( '\\IPS\\Application' ) )
			{
				$app = \IPS\Application::load( 'moneymotion' );
				if ( \is_object( $app ) && isset( $app->version ) && \is_string( $app->version ) && $app->version !== '' )
				{
					$version = $app->version;
				}
			}
		}
		catch ( \Exception $e )
		{
			// swallow — `$version` keeps the fallback
		}

		static::$cachedPluginVersion = $version;
		return $version;
	}
}
