//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
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
