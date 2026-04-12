<?php
/**
 * @package		moneymotion Payment Gateway
 * @author		moneymotion
 * @copyright	(c) 2024 moneymotion
 */

namespace IPS\moneymotion\modules\front\gateway;


/**
 * Webhook & Return URL Controller
 */
class _webhook extends \IPS\Dispatcher\Controller
{
	/**
	 * Route incoming requests
	 *
	 * @return void
	 */
	protected function manage()
	{
		$this->webhook();
	}

	/**
	 * Handle moneymotion webhook
	 *
	 * @return void
	 */
	protected function webhook()
	{
		/* Read raw POST body */
		$rawBody = file_get_contents( 'php://input' );

		if ( empty( $rawBody ) )
		{
			\IPS\Log::log( "moneymotion webhook: empty body rejected", 'moneymotion' );
			\IPS\Output::i()->sendOutput( json_encode( array( 'error' => 'Empty body' ) ), 400, 'application/json' );
			return;
		}

		/* Parse the payload */
		$payload = json_decode( $rawBody, TRUE );

		if ( !$payload || !isset( $payload['event'] ) )
		{
			\IPS\Log::log( "moneymotion webhook: invalid payload rejected", 'moneymotion' );
			\IPS\Output::i()->sendOutput( json_encode( array( 'error' => 'Invalid payload' ) ), 400, 'application/json' );
			return;
		}

		/* Optional replay protection via payload timestamp.
		   moneymotion's current webhook schema does NOT include a timestamp
		   field, so this check is currently dormant. If a future payload adds
		   one (unix seconds or milliseconds), we'll reject events older than
		   5 minutes to prevent replay attacks. */
		$timestamp = isset( $payload['timestamp'] ) ? (int) $payload['timestamp'] : 0;

		if ( $timestamp > 2000000000 )
		{
			/* Looks like milliseconds — normalize to seconds */
			$timestamp = (int) floor( $timestamp / 1000 );
		}

		if ( $timestamp )
		{
			$currentTime = time();
			if ( abs( $currentTime - $timestamp ) > 300 )
			{
				\IPS\Log::log( "moneymotion webhook: timestamp validation failed (event timestamp: {$timestamp}, current: {$currentTime})", 'moneymotion' );
				/* 400 Bad Request — the payload is semantically invalid (stale).
				   Not 401: this is not an authentication problem. Webhook senders
				   should stop retrying on 4xx, which is the behavior we want here. */
				\IPS\Output::i()->sendOutput( json_encode( array( 'error' => 'Webhook timestamp too old' ) ), 400, 'application/json' );
				return;
			}
		}


		/* Find the gateway to get the webhook secret */
		$gateway = $this->findGateway();

		if ( !$gateway )
		{
			\IPS\Log::log( "moneymotion webhook: gateway not configured", 'moneymotion' );
			\IPS\Output::i()->sendOutput( json_encode( array( 'error' => 'Gateway not configured' ) ), 500, 'application/json' );
			return;
		}

		$settings = json_decode( $gateway->settings, TRUE );
		$webhookSecret = isset( $settings['webhook_secret'] ) ? $settings['webhook_secret'] : '';

		/* Webhook secret is MANDATORY - reject unsigned webhooks */
		if ( empty( $webhookSecret ) )
		{
			\IPS\Log::log( "moneymotion webhook: webhook secret not configured in gateway settings - SECURITY RISK", 'moneymotion' );
			\IPS\Output::i()->sendOutput( json_encode( array( 'error' => 'Webhook secret not configured' ) ), 500, 'application/json' );
			return;
		}

		/* Verify signature */
		$signature = isset( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) ? trim( (string) $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) : '';

		if ( empty( $signature ) )
		{
			$signature = isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? trim( (string) $_SERVER['HTTP_X_SIGNATURE'] ) : '';
		}

		$clientIp = $this->getClientIp();

		if ( empty( $signature ) )
		{
			\IPS\Log::log( "moneymotion webhook: signature missing from request headers for IP {$clientIp}", 'moneymotion' );
			\IPS\Output::i()->sendOutput( json_encode( array( 'error' => 'Signature missing' ) ), 401, 'application/json' );
			return;
		}

		if ( !$this->verifyWebhookSignature( $rawBody, $signature, $webhookSecret ) )
		{
			\IPS\Log::log( "moneymotion webhook: signature verification failed for IP {$clientIp}, event: {$payload['event']}", 'moneymotion' );
			\IPS\Output::i()->sendOutput( json_encode( array( 'error' => 'Invalid signature' ) ), 401, 'application/json' );
			return;
		}

		/* Log the webhook */
		\IPS\Log::log( "moneymotion webhook received and verified: {$payload['event']} from IP {$clientIp}", 'moneymotion' );

		/* Handle the event */
		switch ( $payload['event'] )
		{
			case 'checkout_session:complete':
				$this->handleCheckoutComplete( $payload );
				break;

			case 'checkout_session:refunded':
				$this->handleCheckoutRefunded( $payload );
				break;

			case 'checkout_session:expired':
			case 'checkout_session:disputed':
				$this->handleCheckoutFailed( $payload );
				break;

			default:
				/* Unknown event - log and acknowledge */
				\IPS\Log::log( "moneymotion webhook: unhandled event '{$payload['event']}'", 'moneymotion' );
				break;
		}

		/* Return 200 OK */
		\IPS\Output::i()->sendOutput( json_encode( array( 'status' => 'ok' ) ), 200, 'application/json' );
	}

	/**
	 * Handle checkout_session:complete event
	 *
	 * @param array $payload Webhook payload
	 * @return void
	 */
	protected function handleCheckoutComplete( array $payload )
	{
		$checkoutSession = isset( $payload['checkoutSession'] ) ? $payload['checkoutSession'] : array();
		$sessionId = isset( $checkoutSession['id'] ) ? $checkoutSession['id'] : '';

		if ( empty( $sessionId ) )
		{
			\IPS\Log::log( "moneymotion webhook: checkout_session:complete missing session ID", 'moneymotion' );
			return;
		}

		/* Look up our stored session */
		try
		{
			$session = \IPS\Db::i()->select( '*', 'moneymotion_sessions', array( 'session_id=?', $sessionId ) )->first();
		}
		catch ( \UnderflowException $e )
		{
			\IPS\Log::log( "moneymotion webhook: session not found for ID {$sessionId}", 'moneymotion' );
			return;
		}

		/* Already processed? */
		if ( $session['status'] === 'complete' )
		{
			\IPS\Log::log( "moneymotion webhook: session {$sessionId} already complete, skipping", 'moneymotion' );
			return;
		}

		/* Load the IPS transaction */
		try
		{
			$transaction = \IPS\nexus\Transaction::load( $session['transaction_id'] );
		}
		catch ( \OutOfRangeException $e )
		{
			\IPS\Log::log( "moneymotion webhook: transaction {$session['transaction_id']} not found", 'moneymotion' );
			return;
		}

		/* Validate paid amount before approving.
		   moneymotion webhooks do not include currency in the checkoutSession
		   payload — only totalInCents. Currency is locked at checkout creation
		   time and stored in moneymotion_sessions, so we trust what we stored
		   and only verify the amount matches. If the webhook does include a
		   currency field (legacy or expanded payload), we verify it matches. */
		$paidAmountCents = $this->extractPaidAmountCents( $checkoutSession );

		if ( $paidAmountCents === NULL )
		{
			\IPS\Log::log( "moneymotion webhook: missing paid amount for session {$sessionId}; approval blocked", 'moneymotion' );
			\IPS\Db::i()->update( 'moneymotion_sessions', array(
				'status' => 'failed',
				'updated_at' => time(),
			), array( 'session_id=?', $sessionId ) );
			return;
		}

		$expectedAmountCents = (int) $session['amount_cents'];

		if ( $paidAmountCents !== $expectedAmountCents )
		{
			\IPS\Log::log( "moneymotion webhook: amount mismatch for session {$sessionId}; expected {$expectedAmountCents}, got {$paidAmountCents}; approval blocked", 'moneymotion' );
			\IPS\Db::i()->update( 'moneymotion_sessions', array(
				'status' => 'failed',
				'updated_at' => time(),
			), array( 'session_id=?', $sessionId ) );
			return;
		}

		/* Optional currency check: if the webhook carries a currency field,
		   verify it matches. moneymotion's current webhook schema does not
		   include currency, so this is a no-op for them but protects against
		   future payload changes or misconfigured test environments. */
		$paidCurrency = $this->extractPaidCurrency( $checkoutSession );
		if ( $paidCurrency !== NULL )
		{
			$expectedCurrency = mb_strtoupper( (string) $session['currency'] );
			$paidCurrency = mb_strtoupper( (string) $paidCurrency );

			if ( $paidCurrency !== $expectedCurrency )
			{
				\IPS\Log::log( "moneymotion webhook: currency mismatch for session {$sessionId}; expected {$expectedCurrency}, got {$paidCurrency}; approval blocked", 'moneymotion' );
				\IPS\Db::i()->update( 'moneymotion_sessions', array(
					'status' => 'failed',
					'updated_at' => time(),
				), array( 'session_id=?', $sessionId ) );
				return;
			}
		}

		/* Approve the transaction */
		try
		{
			$transaction->approve();

			/* Update local session */
			\IPS\Db::i()->update( 'moneymotion_sessions', array(
				'status'		=> 'complete',
				'updated_at'	=> time(),
			), array( 'session_id=?', $sessionId ) );

			\IPS\Log::log( "moneymotion: transaction {$transaction->id} approved for session {$sessionId} - amount: {$session['amount_cents']} cents", 'moneymotion' );
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( "moneymotion: failed to approve transaction {$transaction->id}: " . $e->getMessage(), 'moneymotion' );
		}
	}

	/**
	 * Handle checkout_session:refunded event
	 *
	 * @param array $payload Webhook payload
	 * @return void
	 */
	protected function handleCheckoutRefunded( array $payload )
	{
		$checkoutSession = isset( $payload['checkoutSession'] ) ? $payload['checkoutSession'] : array();
		$sessionId = isset( $checkoutSession['id'] ) ? $checkoutSession['id'] : '';

		if ( empty( $sessionId ) )
		{
			return;
		}

		try
		{
			$session = \IPS\Db::i()->select( '*', 'moneymotion_sessions', array( 'session_id=?', $sessionId ) )->first();
			$transaction = \IPS\nexus\Transaction::load( $session['transaction_id'] );

			$transaction->status = $transaction::STATUS_REFUNDED;
			$transaction->save();

			\IPS\Db::i()->update( 'moneymotion_sessions', array(
				'status'		=> 'refunded',
				'updated_at'	=> time(),
			), array( 'session_id=?', $sessionId ) );

			\IPS\Log::log( "moneymotion: transaction {$transaction->id} refunded for session {$sessionId}", 'moneymotion' );
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( "moneymotion refund webhook error: " . $e->getMessage(), 'moneymotion' );
		}
	}

	/**
	 * Handle failed/expired/disputed checkout events
	 *
	 * @param array $payload Webhook payload
	 * @return void
	 */
	protected function handleCheckoutFailed( array $payload )
	{
		$checkoutSession = isset( $payload['checkoutSession'] ) ? $payload['checkoutSession'] : array();
		$sessionId = isset( $checkoutSession['id'] ) ? $checkoutSession['id'] : '';

		if ( empty( $sessionId ) )
		{
			return;
		}

		try
		{
			\IPS\Db::i()->update( 'moneymotion_sessions', array(
				'status'		=> 'failed',
				'updated_at'	=> time(),
			), array( 'session_id=?', $sessionId ) );

			\IPS\Log::log( "moneymotion: session {$sessionId} marked as failed", 'moneymotion' );
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( "moneymotion failed webhook error: " . $e->getMessage(), 'moneymotion' );
		}
	}

	/**
	 * Success return URL handler
	 *
	 * @return void
	 */
	protected function success()
	{
		$transactionId = \IPS\Request::i()->t;
		$csrf = \IPS\Request::i()->csrf_token;

		/* Validate CSRF token */
		if ( !$this->validateCsrfToken( $transactionId, $csrf, 'success' ) )
		{
			\IPS\Log::log( "moneymotion success URL: CSRF token validation failed for transaction {$transactionId}", 'moneymotion' );
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( '' ), \IPS\Member::loggedIn()->language()->addToStack( 'moneymotion_payment_failed' ) );
			return;
		}

		try
		{
			$transaction = \IPS\nexus\Transaction::load( $transactionId );
			$invoice = $transaction->invoice;

			\IPS\Output::i()->redirect( $invoice->url(), \IPS\Member::loggedIn()->language()->addToStack( 'moneymotion_payment_success' ) );
		}
		catch ( \OutOfRangeException $e )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( '' ), \IPS\Member::loggedIn()->language()->addToStack( 'moneymotion_payment_processing' ) );
		}
	}

	/**
	 * Cancel return URL handler
	 *
	 * @return void
	 */
	protected function cancel()
	{
		$transactionId = \IPS\Request::i()->t;
		$csrf = \IPS\Request::i()->csrf_token;

		/* Validate CSRF token */
		if ( !$this->validateCsrfToken( $transactionId, $csrf, 'cancel' ) )
		{
			\IPS\Log::log( "moneymotion cancel URL: CSRF token validation failed for transaction {$transactionId}", 'moneymotion' );
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( '' ), \IPS\Member::loggedIn()->language()->addToStack( 'moneymotion_payment_cancelled' ) );
			return;
		}

		try
		{
			$transaction = \IPS\nexus\Transaction::load( $transactionId );
			$invoice = $transaction->invoice;

			/* Mark as refused so the pending entry doesn't accumulate */
			if ( $transaction->status === $transaction::STATUS_PENDING )
			{
				$transaction->status = $transaction::STATUS_REFUSED;
				$transaction->save();
			}

			/* Mark the session as cancelled */
			\IPS\Db::i()->update( 'moneymotion_sessions', array(
				'status'		=> 'cancelled',
				'updated_at'	=> time(),
			), array( 'transaction_id=?', $transactionId ) );

			\IPS\Output::i()->redirect( $invoice->checkoutUrl(), \IPS\Member::loggedIn()->language()->addToStack( 'moneymotion_payment_cancelled' ) );
		}
		catch ( \OutOfRangeException $e )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( '' ), \IPS\Member::loggedIn()->language()->addToStack( 'moneymotion_payment_cancelled' ) );
		}
	}

	/**
	 * Failure return URL handler
	 *
	 * @return void
	 */
	protected function failure()
	{
		$transactionId = \IPS\Request::i()->t;
		$csrf = \IPS\Request::i()->csrf_token;

		/* Validate CSRF token */
		if ( !$this->validateCsrfToken( $transactionId, $csrf, 'failure' ) )
		{
			\IPS\Log::log( "moneymotion failure URL: CSRF token validation failed for transaction {$transactionId}", 'moneymotion' );
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( '' ), \IPS\Member::loggedIn()->language()->addToStack( 'moneymotion_payment_failed' ) );
			return;
		}

		try
		{
			$transaction = \IPS\nexus\Transaction::load( $transactionId );
			$invoice = $transaction->invoice;

			/* Mark as refused so the pending entry doesn't accumulate */
			if ( $transaction->status === $transaction::STATUS_PENDING )
			{
				$transaction->status = $transaction::STATUS_REFUSED;
				$transaction->save();
			}

			/* Update session status */
			\IPS\Db::i()->update( 'moneymotion_sessions', array(
				'status'		=> 'failed',
				'updated_at'	=> time(),
			), array( 'transaction_id=?', $transactionId ) );

			\IPS\Output::i()->redirect( $invoice->checkoutUrl(), \IPS\Member::loggedIn()->language()->addToStack( 'moneymotion_payment_failed' ) );
		}
		catch ( \OutOfRangeException $e )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( '' ), \IPS\Member::loggedIn()->language()->addToStack( 'moneymotion_payment_failed' ) );
		}
	}

	/**
	 * Find the moneymotion gateway record.
	 *
	 * Returns either a full `\IPS\nexus\Gateway` when the Gateway hook is
	 * loaded, or a `_MoneymotionGatewayStub` when it is not. Callers of this
	 * method must only rely on the `->settings` property — anything else may
	 * be absent on the stub path.
	 *
	 * @return \IPS\nexus\Gateway|_MoneymotionGatewayStub|NULL
	 */
	protected function findGateway()
	{
		/* First try the IPS-native approach with constructFromData.
		   This can fail if the Gateway hook is not active (hook cache issue,
		   disabled hooks, etc.) because constructFromData() calls gateways()
		   which only includes 'moneymotion' when the hook is loaded. */
		try
		{
			$gatewayRow = \IPS\Db::i()->select( '*', 'nexus_paymethods', array( 'm_gateway=?', 'moneymotion' ) )->first();

			try
			{
				return \IPS\nexus\Gateway::constructFromData( $gatewayRow );
			}
			catch ( \Exception $e )
			{
				/* constructFromData failed — the Gateway hook isn't loaded in
				   this request (hook cache issue, disabled hooks, or hook cache
				   regeneration in flight). Fall back to a typed stub that
				   exposes only the fields the webhook actually reads. */
				return new _MoneymotionGatewayStub(
					isset( $gatewayRow['m_settings'] ) ? (string) $gatewayRow['m_settings'] : '{}'
				);
			}
		}
		catch ( \UnderflowException $e )
		{
			return NULL;
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( "moneymotion webhook: findGateway error - " . get_class( $e ) . ": " . $e->getMessage(), 'moneymotion' );
			return NULL;
		}
	}

	/**
	 * Get client IP address for audit logging.
	 *
	 * Returns a string in the form:
	 *   "203.0.113.50 (via 10.0.0.1)"   — when a proxy header is present
	 *   "10.0.0.1"                      — when no proxy header is present
	 *
	 * Logging BOTH addresses (the forwarded-for claim AND the actual remote
	 * address) means a spoofed X-Forwarded-For header cannot hide the true
	 * TCP peer from the audit trail. This matters because X-Forwarded-For
	 * is trivially forgeable on requests that bypass your proxy.
	 *
	 * @return string
	 */
	protected function getClientIp()
	{
		$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		$remoteAddr = filter_var( $remoteAddr, FILTER_VALIDATE_IP ) ? $remoteAddr : '0.0.0.0';

		/* Check for proxied IP */
		$forwardedIp = '';
		if ( !empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
		{
			$ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$forwardedIp = trim( $ips[0] );
		}
		elseif ( !empty( $_SERVER['HTTP_CLIENT_IP'] ) )
		{
			$forwardedIp = $_SERVER['HTTP_CLIENT_IP'];
		}

		if ( $forwardedIp !== '' && filter_var( $forwardedIp, FILTER_VALIDATE_IP ) && $forwardedIp !== $remoteAddr )
		{
			return "{$forwardedIp} (via {$remoteAddr})";
		}

		return $remoteAddr;
	}

	/**
	 * Generate CSRF token for a transaction
	 *
	 * @param int $transactionId Transaction ID
	 * @param string $action Action (success/cancel/failure)
	 * @return string
	 */
	protected function generateCsrfToken( $transactionId, $action )
	{
		$member = \IPS\Member::loggedIn();
		$data = "{$transactionId}:{$action}:{$member->member_id}:" . \IPS\Settings::i()->cookie_login_key;
		return hash_hmac( 'sha256', $data, \IPS\Settings::i()->cookie_login_key );
	}

	/**
	 * Validate CSRF token
	 *
	 * @param int $transactionId Transaction ID
	 * @param string $token Token to validate
	 * @param string $action Action (success/cancel/failure)
	 * @return bool
	 */
	protected function validateCsrfToken( $transactionId, $token, $action )
	{
		if ( !\is_scalar( $token ) || $token === '' )
		{
			return FALSE;
		}

		$expectedToken = $this->generateCsrfToken( $transactionId, $action );
		return hash_equals( $expectedToken, (string) $token );
	}

	/**
	 * Verify webhook signature
	 *
	 * Delegates to the API Client's implementation so the signature algorithm
	 * has a single source of truth.
	 *
	 * @param string $rawBody Raw request body
	 * @param string $signature Signature from request header
	 * @param string $secret Webhook signing secret
	 * @return bool
	 */
	protected function verifyWebhookSignature( $rawBody, $signature, $secret )
	{
		return \IPS\moneymotion\Api\Client::verifyWebhookSignature( $rawBody, (string) $signature, $secret );
	}

	/**
	 * Extract paid amount in cents from checkout session payload
	 *
	 * @param array $checkoutSession Checkout session payload
	 * @return int|NULL
	 */
	protected function extractPaidAmountCents( array $checkoutSession )
	{
		/* 'totalInCents' is the actual field in moneymotion webhooks per
		   https://docs.moneymotion.io/webhooks — listed first so it wins
		   over any legacy aliases that older test payloads may include. */
		foreach ( array( 'totalInCents', 'amountInCents', 'amount_cents', 'amountCents', 'totalAmountInCents', 'total_amount_cents' ) as $key )
		{
			if ( isset( $checkoutSession[ $key ] ) && is_numeric( $checkoutSession[ $key ] ) )
			{
				return (int) $checkoutSession[ $key ];
			}
		}

		if ( isset( $checkoutSession['lineItems'] ) && is_array( $checkoutSession['lineItems'] ) )
		{
			$total = 0;
			$hasAny = FALSE;
			foreach ( $checkoutSession['lineItems'] as $item )
			{
				if ( !is_array( $item ) || !isset( $item['pricePerItemInCents'] ) || !is_numeric( $item['pricePerItemInCents'] ) )
				{
					continue;
				}

				$quantity = ( isset( $item['quantity'] ) && is_numeric( $item['quantity'] ) ) ? (int) $item['quantity'] : 1;
				$total += ( (int) $item['pricePerItemInCents'] ) * max( 1, $quantity );
				$hasAny = TRUE;
			}

			if ( $hasAny )
			{
				return $total;
			}
		}

		return NULL;
	}

	/**
	 * Extract paid currency from checkout session payload
	 *
	 * @param array $checkoutSession Checkout session payload
	 * @return string|NULL
	 */
	protected function extractPaidCurrency( array $checkoutSession )
	{
		foreach ( array( 'currency', 'currencyCode', 'currency_code' ) as $key )
		{
			if ( isset( $checkoutSession[ $key ] ) && is_scalar( $checkoutSession[ $key ] ) && $checkoutSession[ $key ] !== '' )
			{
				return (string) $checkoutSession[ $key ];
			}
		}

		return NULL;
	}
}

/**
 * Minimal gateway stand-in for use when the Gateway hook is not loaded.
 *
 * The webhook handler only needs one piece of data from the gateway: the
 * raw JSON settings string so it can read the webhook secret. When IPS's
 * hook cache is stale or the hook is otherwise disabled at request time,
 * `\IPS\nexus\Gateway::constructFromData()` throws because the hooked
 * `gateways()` table does not know about `moneymotion`. In that case we
 * build this stub directly from the DB row.
 *
 * Callers of `findGateway()` MUST stick to the documented contract: only
 * the `$settings` property is available. Anything else IPS would normally
 * expose on a Gateway object (`id`, `testSettings()`, etc.) is deliberately
 * absent so that misuse fails loudly instead of silently returning wrong
 * data in the hook-unavailable path.
 */
class _MoneymotionGatewayStub
{
	/**
	 * Raw JSON settings string (matches shape of nexus_paymethods.m_settings).
	 *
	 * @var string
	 */
	public $settings;

	public function __construct( $settings )
	{
		$this->settings = (string) $settings;
	}
}
