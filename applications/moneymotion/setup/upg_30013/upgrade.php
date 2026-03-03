<?php
/**
 * @package		moneymotion Payment Gateway
 */

namespace IPS\moneymotion\setup\upg_30013;

/**
 * Upgrade Code
 */
class _Upgrade
{
	/**
	 * Ensure required language keys exist
	 *
	 * @return bool
	 */
	public function step1()
	{
		try
		{
			\IPS\Lang::saveCustom( 'moneymotion', '__app_moneymotion', 'moneymotion' );
			\IPS\Lang::saveCustom( 'moneymotion', 'module__moneymotion_gateway', 'moneymotion Gateway' );
		}
		catch ( \Throwable $e )
		{
			// Ignore to avoid blocking upgrade flow
		}

		return TRUE;
	}
}
