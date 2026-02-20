<?php
/**
 * @package		moneymotion Payment Gateway
 * @author		moneymotion
 * @copyright	(c) 2024 moneymotion
 */

namespace IPS\moneymotion\setup\install;

/**
 * Installation routine
 */
function step1()
{
	/* Create the moneymotion_sessions table if it doesn't exist */
	if ( !\IPS\Db::i()->checkForTable( 'moneymotion_sessions' ) )
	{
		\IPS\Db::i()->createTable( array(
			'name'		=> 'moneymotion_sessions',
			'columns'	=> array(
				array(
					'name'			=> 'session_id',
					'type'			=> 'VARCHAR',
					'length'		=> 255,
					'allow_null'	=> FALSE,
					'default'		=> '',
				),
				array(
					'name'			=> 'transaction_id',
					'type'			=> 'BIGINT',
					'length'		=> 20,
					'unsigned'		=> TRUE,
					'allow_null'	=> FALSE,
					'default'		=> 0,
				),
				array(
					'name'			=> 'invoice_id',
					'type'			=> 'BIGINT',
					'length'		=> 20,
					'unsigned'		=> TRUE,
					'allow_null'	=> FALSE,
					'default'		=> 0,
				),
				array(
					'name'			=> 'amount_cents',
					'type'			=> 'INT',
					'length'		=> 11,
					'allow_null'	=> FALSE,
					'default'		=> 0,
				),
				array(
					'name'			=> 'currency',
					'type'			=> 'VARCHAR',
					'length'		=> 3,
					'allow_null'	=> FALSE,
					'default'		=> 'EUR',
				),
				array(
					'name'			=> 'status',
					'type'			=> 'VARCHAR',
					'length'		=> 32,
					'allow_null'	=> FALSE,
					'default'		=> 'pending',
				),
				array(
					'name'			=> 'created_at',
					'type'			=> 'INT',
					'length'		=> 10,
					'allow_null'	=> FALSE,
					'default'		=> 0,
				),
				array(
					'name'			=> 'updated_at',
					'type'			=> 'INT',
					'length'		=> 10,
					'allow_null'	=> FALSE,
					'default'		=> 0,
				),
			),
			'indexes'	=> array(
				array(
					'type'		=> 'primary',
					'name'		=> 'PRIMARY',
					'columns'	=> array( 'session_id' ),
				),
				array(
					'type'		=> 'key',
					'name'		=> 'transaction_id',
					'columns'	=> array( 'transaction_id' ),
				),
				array(
					'type'		=> 'key',
					'name'		=> 'invoice_id',
					'columns'	=> array( 'invoice_id' ),
				),
				array(
					'type'		=> 'key',
					'name'		=> 'status',
					'columns'	=> array( 'status' ),
				),
			),
		) );
	}

	/* Add MoneyMotion payment method if it doesn't exist */
	try
	{
		$hasMoneyMotion = \IPS\Db::i()->select( 'COUNT(*)', 'nexus_paymethods', array( 'pm_gateway=?', 'MoneyMotion' ) )->first();

		if ( !$hasMoneyMotion )
		{
			/* Get max order to append to the end */
			try
			{
				$maxOrder = \IPS\Db::i()->select( 'MAX(pm_order)', 'nexus_paymethods' )->first();
			}
			catch ( \UnderflowException $e )
			{
				$maxOrder = 0;
			}

			\IPS\Db::i()->insert( 'nexus_paymethods', array(
				'pm_name'		=> 'MoneyMotion',
				'pm_gateway'	=> 'MoneyMotion',
				'pm_settings'	=> json_encode( array( 'moneymotion_api_key' => '', 'moneymotion_webhook_secret' => '' ) ),
				'pm_active'		=> 0, // Disabled by default as it needs API key
				'pm_order'		=> $maxOrder + 1,
			) );
		}
	}
	catch ( \Exception $e ) {}

	return TRUE;
}
