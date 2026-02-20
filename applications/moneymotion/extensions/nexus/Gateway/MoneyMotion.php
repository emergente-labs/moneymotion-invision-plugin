<?php
/**
 * @package		moneymotion Payment Gateway
 * @author		moneymotion
 * @copyright	(c) 2024 moneymotion
 */

namespace IPS\moneymotion\extensions\nexus\Gateway;

/**
 * moneymotion Payment Gateway
 */
class _moneymotion extends \IPS\nexus\Gateway
{
	/* !Payment Gateway */

	/**
	 * Supports anything?
	 *
	 * @param string $feature Feature to check (e.g. 'refund')
	 * @return bool
	 */
	public function supports( $feature )
	{
		$supported = array( 'auth', 'capture' );
		return \in_array( $feature, $supported );
	}

	/**
	 * Can store cards?
	 *
	 * @param bool $adminCreatableOnly If TRUE, will only return gateways where the admin (opposed to the user) can create a new option
	 * @return bool
	 */
	public function canStoreCards( $adminCreatableOnly = FALSE )
	{
		return FALSE;
	}

	/**
	 * Admin can manually charge using this gateway?
	 *
	 * @param \IPS\nexus\Customer $customer The customer we're wanting to charge
	 * @return bool
	 */
	public function canAdminCharge( \IPS\nexus\Customer $customer )
	{
		return FALSE;
	}

	/* !Settings */

	/**
	 * Settings
	 *
	 * @param \IPS\Helpers\Form $form The form
	 * @return void
	 */
	public function settings( &$form )
	{
		$settings = json_decode( $this->settings, TRUE );

		$form->add( new \IPS\Helpers\Form\Text( 'moneymotion_api_key', isset( $settings['api_key'] ) ? $settings['api_key'] : '', TRUE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'moneymotion_webhook_secret', isset( $settings['webhook_secret'] ) ? $settings['webhook_secret'] : '', TRUE ) );
	}

	/**
	 * Test Settings
	 *
	 * @param array $settings Settings
	 * @return array
	 * @throws \InvalidArgumentException
	 */
	public function testSettings( $settings )
	{
		if ( empty( $settings['api_key'] ) )
		{
			throw new \InvalidArgumentException( 'moneymotion API key is required.' );
		}

		if ( empty( $settings['webhook_secret'] ) )
		{
			throw new \InvalidArgumentException( 'moneymotion Webhook Secret is required for security.' );
		}

		return $settings;
	}

	/* !Payment Screen */

	/**
	 * Authorization/Payment Screen
	 *
	 * @param \IPS\nexus\Invoice $invoice The invoice
	 * @param \IPS\nexus\Money $amount The amount to pay
	 * @param \IPS\nexus\Customer $member The customer
	 * @param array $recurrings Any recurring payments
	 * @param string $type 'auth' or 'pay'
	 * @return string
	 */
	public function paymentScreen( \IPS\nexus\Invoice $invoice, \IPS\nexus\Money $amount, \IPS\nexus\Customer $member = NULL, $recurrings = array(), $type = 'pay' )
	{
		return \IPS\Theme::i()->getTemplate( 'gateway', 'moneymotion', 'front' )->paymentScreen( $this, $invoice, $amount );
	}

	/**
	 * Authorize
	 *
	 * @param \IPS\nexus\Transaction $transaction Transaction
	 * @param array|\IPS\nexus\Customer\CreditCard $values Values from form OR a stored card object if a stored card is being used
	 * @param \IPS\nexus\Fraud\MaxMind\Request|NULL $maxMind *If* MaxMind is enabled, the request object will be passed here so gateway can additional data before request is made
	 * @param array $recurrings Details about recurring costs
	 * @param string|NULL $source 'checkout' if the customer is just checking out, 'renewal' is an automatic renewal
	 * @return \IPS\DateTime|NULL Auth is valid until or NULL for no auth support
	 * @throws \LogicException Message is displayed to the user
	 * @throws \RuntimeException Message is logged
	 */
	public function auth( \IPS\nexus\Transaction $transaction, $values, \IPS\nexus\Fraud\MaxMind\Request $maxMind = NULL, $recurrings = array(), $source = NULL )
	{
		/* Load invoice */
		$invoice = $transaction->invoice;
		$settings = json_decode( $this->settings, TRUE );
		$amount = $transaction->amount;

		/* Ensure transaction has an ID before we proceed */
		if ( ! $transaction->id )
		{
			$transaction->save();
		}

		/* Audit log: Payment attempt started */
		\IPS\Log::log( "moneymotion: payment attempt started - transaction_id: {$transaction->id}, invoice_id: {$invoice->id}, member_id: {$transaction->member->member_id}, amount: {$amount->amount} {$amount->currency}", 'moneymotion' );

		/* Build line items from invoice */
		$lineItems = array();
		$hasNonPositiveItem = FALSE;
		foreach ( $invoice->items as $item )
		{
			/* Convert price to cents - handle Math\Number objects properly */
			$priceInCents = (int) round( (float) (string) $item->price->amount * 100 );

			/* Skip items with 0 price (free items) */
			if ( $priceInCents === 0 )
			{
				continue;
			}

			/* If we have a negative price (discount), mark it so we can fallback to single line item */
			if ( $priceInCents < 0 )
			{
				$hasNonPositiveItem = TRUE;
				break;
			}

			$lineItems[] = array(
				'name'					=> $item->name,
				'description'			=> $item->name,
				'pricePerItemInCents'	=> $priceInCents,
				'quantity'				=> (int) $item->quantity,
			);
		}

		/* If we have discounts or no line items, create a single item for the total */
		if ( $hasNonPositiveItem || empty( $lineItems ) )
		{
			$lineItems = array(
				array(
					'name'					=> "Invoice #{$invoice->id}",
					'description'			=> "Payment for Invoice #{$invoice->id}",
					'pricePerItemInCents'	=> (int) round( (float) (string) $amount->amount * 100 ),
					'quantity'				=> 1,
				)
			);
		}

		/* Build callback URLs with CSRF tokens */
		$csrfTokenSuccess = $this->generateCsrfToken( $transaction->id, 'success' );
		$csrfTokenCancel = $this->generateCsrfToken( $transaction->id, 'cancel' );
		$csrfTokenFailure = $this->generateCsrfToken( $transaction->id, 'failure' );

		$baseUrl = \IPS\Http\Url::internal( "app=moneymotion&module=gateway&controller=webhook", 'front' );
		$urls = array(
			'success'	=> str_replace( 'http://', 'https://', (string) \IPS\Http\Url::internal( "app=moneymotion&module=gateway&controller=webhook&do=success&t={$transaction->id}&csrf_token={$csrfTokenSuccess}", 'front' ) ),
			'cancel'	=> str_replace( 'http://', 'https://', (string) \IPS\Http\Url::internal( "app=moneymotion&module=gateway&controller=webhook&do=cancel&t={$transaction->id}&csrf_token={$csrfTokenCancel}", 'front' ) ),
			'failure'	=> str_replace( 'http://', 'https://', (string) \IPS\Http\Url::internal( "app=moneymotion&module=gateway&controller=webhook&do=failure&t={$transaction->id}&csrf_token={$csrfTokenFailure}", 'front' ) ),
		);

		/* Get customer email */
		$email = $transaction->member->email;

		/* Metadata to link back to IPS */
		$metadata = array(
			'invoice_id'	=> $invoice->id,
			'transaction_id'	=> $transaction->id,
			'gateway_id'	=> $this->id,
		);

		/* Create checkout session */
		try
		{
			$client = \IPS\moneymotion\Api\Client::fromGateway( $this );
			$sessionId = $client->createCheckoutSession(
				"Invoice #{$invoice->id}",
				$urls,
				$email,
				$lineItems,
				$metadata,
				$amount->currency
			);

			\IPS\Log::log( "moneymotion: checkout session created - session_id: {$sessionId}, transaction_id: {$transaction->id}, amount_cents: " . (int) round( (float) (string) $amount->amount * 100 ), 'moneymotion' );
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( "moneymotion createCheckoutSession failed: " . $e->getMessage() . " | Member: {$transaction->member->member_id} | Invoice: {$invoice->id}", 'moneymotion' );
			throw new \LogicException( \IPS\Member::loggedIn()->language()->addToStack( 'moneymotion_error_api' ) );
		}

		/* Store session in database */
		\IPS\Db::i()->insert( 'moneymotion_sessions', array(
			'session_id'	=> $sessionId,
			'transaction_id'	=> (int) $transaction->id,
			'invoice_id'	=> $invoice->id,
			'amount_cents'	=> (int) round( (float) (string) $amount->amount * 100 ),
			'currency'		=> $amount->currency,
			'status'		=> 'pending',
			'created_at'	=> time(),
			'updated_at'	=> time(),
		) );

		/* Store the session ID on the transaction for reference */
		$transaction->gw_id = $sessionId;
		$transaction->save();

		/* Redirect customer to moneymotion checkout */
		$checkoutUrl = "https://moneymotion.io/checkout/{$sessionId}";
		\IPS\Output::i()->redirect( \IPS\Http\Url::external( $checkoutUrl ) );
	}

	/**
	 * Capture
	 *
	 * @param \IPS\nexus\Transaction $transaction Transaction
	 * @return void
	 * @throws \LogicException
	 */
	public function capture( \IPS\nexus\Transaction $transaction )
	{
		/* moneymotion handles auth + capture in one step via webhook */
		/* Nothing to do here - payment is captured automatically */
	}

	/**
	 * Void
	 *
	 * @param \IPS\nexus\Transaction $transaction Transaction
	 * @return void
	 */
	public function void( \IPS\nexus\Transaction $transaction )
	{
		/* Update local session status */
		\IPS\Db::i()->update( 'moneymotion_sessions', array(
			'status'		=> 'cancelled',
			'updated_at'	=> time(),
		), array( 'transaction_id=?', $transaction->id ) );

		\IPS\Log::log( "moneymotion: transaction {$transaction->id} voided by admin", 'moneymotion' );
	}

	/* !ACP */

	/**
	 * Extra data to show on the ACP transaction page
	 *
	 * @param \IPS\nexus\Transaction $transaction Transaction
	 * @return string
	 */
	public function extraData( \IPS\nexus\Transaction $transaction )
	{
		if ( $transaction->gw_id )
		{
			return "moneymotion Session: {$transaction->gw_id}";
		}
		return '';
	}

	/* !Security Helpers */

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
}
