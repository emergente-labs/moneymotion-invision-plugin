//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	exit;
}

class moneymotion_hook_Gateway extends _HOOK_CLASS_
{
	public static function gateways()
	{
		$gateways = parent::gateways();
		$gateways['moneymotion'] = 'IPS\moneymotion\extensions\nexus\Gateway\moneymotion';
		return $gateways;
	}
}
