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

		/* Validate timestamp to prevent replay attacks (5 minute window) */
		$timestamp = isset( $payload['timestamp'] ) ? $payload['timestamp'] : 0;
		if ( $timestamp )
		{
			$currentTime = time();
			if ( abs( $currentTime - $timestamp ) > 300 )
			{
				\IPS\Log::log( "moneymotion webhook: timestamp validation failed (event timestamp: {$timestamp}, current: {$currentTime})", 'moneymotion' );
				\IPS\Output::i()->sendOutput( json_encode( array( 'error' => 'Webhook timestamp too old' ) ), 401, 'application/json' );
				return;
			}
		}

		/* Rate limiting: check if IP has sent too many webhooks recently (max 10 per minute) */
		$clientIp = $this->getClientIp();
		$rateLimitKey = "moneymotion_webhook_rate_{$clientIp}";
		$cache = \IPS\Data\Store::i();
		$attemptCount = (int) $cache->$rateLimitKey;

		if ( $attemptCount >= 10 )
		{
			\IPS\Log::log( "moneymotion webhook: rate limit exceeded for IP {$clientIp}", 'moneymotion' );
			\IPS\Output::i()->sendOutput( json_encode( array( 'error' => 'Rate limit exceeded' ) ), 429, 'application/json' );
			return;
		}

		$cache->setWithExpiration( $rateLimitKey, $attemptCount + 1, \IPS\DateTime::create()->add( new \DateInterval( 'PT1M' ) ) );

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
			return;
		}

		/* Verify signature */
		$signature = isset( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) ? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] : '';

		if ( empty( $signature ) )
		{
			$signature = isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? $_SERVER['HTTP_X_SIGNATURE'] : '';
		}

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
			/* Try finding by metadata */
			$metadata = isset( $checkoutSession['metadata'] ) ? $checkoutSession['metadata'] : array();
			$transactionId = isset( $metadata['transaction_id'] ) ? $metadata['transaction_id'] : 0;

			if ( $transactionId )
			{
				try
				{
					$session = \IPS\Db::i()->select( '*', 'moneymotion_sessions', array( 'transaction_id=?', $transactionId ) )->first();
				}
				catch ( \UnderflowException $e )
				{
					\IPS\Log::log( "moneymotion webhook: session not found for ID {$sessionId}", 'moneymotion' );
					return;
				}
			}
			else
			{
				\IPS\Log::log( "moneymotion webhook: session not found for ID {$sessionId}", 'moneymotion' );
				return;
			}
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
	 * Find the moneymotion gateway record
	 *
	 * @return \IPS\nexus\Gateway|NULL
	 */
	protected function findGateway()
	{
		try
		{
			$gatewayRow = \IPS\Db::i()->select( '*', 'nexus_paymethods', array( 'pm_gateway=?', 'moneymotion' ) )->first();
			return \IPS\nexus\Gateway::constructFromData( $gatewayRow );
		}
		catch ( \Exception $e )
		{
			return NULL;
		}
	}

	/**
	 * Get client IP address
	 *
	 * @return string
	 */
	protected function getClientIp()
	{
		$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

		/* Check for proxied IP */
		if ( !empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
		{
			$ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$ip = trim( $ips[0] );
		}
		elseif ( !empty( $_SERVER['HTTP_CLIENT_IP'] ) )
		{
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
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
		$expectedToken = $this->generateCsrfToken( $transactionId, $action );
		return hash_equals( $expectedToken, $token );
	}

	/**
	 * Verify webhook signature
	 *
	 * @param string $rawBody Raw request body
	 * @param string $signature Signature from request header
	 * @param string $secret Webhook signing secret
	 * @return bool
	 */
	protected function verifyWebhookSignature( $rawBody, $signature, $secret )
	{
		$computed = base64_encode( hash_hmac( 'sha512', $rawBody, $secret, TRUE ) );
		return hash_equals( $computed, $signature );
	}
}
