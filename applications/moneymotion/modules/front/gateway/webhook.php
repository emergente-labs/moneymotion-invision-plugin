<?php
/**
 * @package		MoneyMotion Payment Gateway
 * @author		MoneyMotion
 * @copyright	(c) 2024 MoneyMotion
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
	 * @return	void
	 */
	protected function manage()
	{
		$this->webhook();
	}

	/**
	 * Handle MoneyMotion webhook
	 *
	 * @return	void
	 */
	protected function webhook()
	{
		/* Read raw POST body */
		$rawBody = file_get_contents( 'php://input' );

		if ( empty( $rawBody ) )
		{
			\IPS\Output::i()->sendOutput( json_encode( array( 'error' => 'Empty body' ) ), 400, 'application/json' );
			return;
		}

		/* Parse the payload */
		$payload = json_decode( $rawBody, TRUE );

		if ( !$payload || !isset( $payload['event'] ) )
		{
			\IPS\Output::i()->sendOutput( json_encode( array( 'error' => 'Invalid payload' ) ), 400, 'application/json' );
			return;
		}

		/* Find the gateway to get the webhook secret */
		$gateway = $this->findGateway();

		if ( $gateway )
		{
			$settings = json_decode( $gateway->settings, TRUE );
			$webhookSecret = isset( $settings['webhook_secret'] ) ? $settings['webhook_secret'] : '';

			/* Verify signature if we have a secret */
			if ( !empty( $webhookSecret ) )
			{
				$signature = isset( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) ? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] : '';

				if ( empty( $signature ) )
				{
					$signature = isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? $_SERVER['HTTP_X_SIGNATURE'] : '';
				}

				if ( !empty( $signature ) && !\IPS\moneymotion\Api\Client::verifyWebhookSignature( $rawBody, $signature, $webhookSecret ) )
				{
					\IPS\Log::log( "MoneyMotion webhook signature verification failed", 'moneymotion' );
					\IPS\Output::i()->sendOutput( json_encode( array( 'error' => 'Invalid signature' ) ), 401, 'application/json' );
					return;
				}
			}
		}

		/* Log the webhook */
		\IPS\Log::log( "MoneyMotion webhook received: {$payload['event']}", 'moneymotion' );

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
				\IPS\Log::log( "MoneyMotion webhook: unhandled event '{$payload['event']}'", 'moneymotion' );
				break;
		}

		/* Return 200 OK */
		\IPS\Output::i()->sendOutput( json_encode( array( 'status' => 'ok' ) ), 200, 'application/json' );
	}

	/**
	 * Handle checkout_session:complete event
	 *
	 * @param	array	$payload	Webhook payload
	 * @return	void
	 */
	protected function handleCheckoutComplete( array $payload )
	{
		$checkoutSession = isset( $payload['checkoutSession'] ) ? $payload['checkoutSession'] : array();
		$sessionId = isset( $checkoutSession['id'] ) ? $checkoutSession['id'] : '';

		if ( empty( $sessionId ) )
		{
			\IPS\Log::log( "MoneyMotion webhook: checkout_session:complete missing session ID", 'moneymotion' );
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
					\IPS\Log::log( "MoneyMotion webhook: session not found for ID {$sessionId}", 'moneymotion' );
					return;
				}
			}
			else
			{
				\IPS\Log::log( "MoneyMotion webhook: session not found for ID {$sessionId}", 'moneymotion' );
				return;
			}
		}

		/* Already processed? */
		if ( $session['status'] === 'complete' )
		{
			\IPS\Log::log( "MoneyMotion webhook: session {$sessionId} already complete, skipping", 'moneymotion' );
			return;
		}

		/* Load the IPS transaction */
		try
		{
			$transaction = \IPS\nexus\Transaction::load( $session['transaction_id'] );
		}
		catch ( \OutOfRangeException $e )
		{
			\IPS\Log::log( "MoneyMotion webhook: transaction {$session['transaction_id']} not found", 'moneymotion' );
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

			\IPS\Log::log( "MoneyMotion: transaction {$transaction->id} approved for session {$sessionId}", 'moneymotion' );
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( "MoneyMotion: failed to approve transaction {$transaction->id}: " . $e->getMessage(), 'moneymotion' );
		}
	}

	/**
	 * Handle checkout_session:refunded event
	 *
	 * @param	array	$payload	Webhook payload
	 * @return	void
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

			\IPS\Log::log( "MoneyMotion: transaction {$transaction->id} refunded for session {$sessionId}", 'moneymotion' );
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( "MoneyMotion refund webhook error: " . $e->getMessage(), 'moneymotion' );
		}
	}

	/**
	 * Handle failed/expired/disputed checkout events
	 *
	 * @param	array	$payload	Webhook payload
	 * @return	void
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
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( "MoneyMotion failed webhook error: " . $e->getMessage(), 'moneymotion' );
		}
	}

	/**
	 * Success return URL handler
	 *
	 * @return	void
	 */
	protected function success()
	{
		$transactionId = \IPS\Request::i()->t;

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
	 * @return	void
	 */
	protected function cancel()
	{
		$transactionId = \IPS\Request::i()->t;

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
	 * @return	void
	 */
	protected function failure()
	{
		$transactionId = \IPS\Request::i()->t;

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
	 * Find the MoneyMotion gateway record
	 *
	 * @return	\IPS\nexus\Gateway|NULL
	 */
	protected function findGateway()
	{
		try
		{
			$gatewayRow = \IPS\Db::i()->select( '*', 'nexus_paymethods', array( 'pm_gateway=?', 'MoneyMotion' ) )->first();
			return \IPS\nexus\Gateway::constructFromData( $gatewayRow );
		}
		catch ( \Exception $e )
		{
			return NULL;
		}
	}
}
