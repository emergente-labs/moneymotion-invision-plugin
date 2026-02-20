<?php
/**
 * @package		moneymotion Payment Gateway
 * @author		moneymotion
 * @copyright	(c) 2024 moneymotion
 */

namespace IPS\moneymotion;

/**
 * moneymotion Application Class
 */
class _Application extends \IPS\Application
{
	/**
	 * [Node] Get Icon for tree
	 *
	 * @note	Return the class for the icon (e.g. 'globe')
	 * @return	string|null
	 */
	protected function get__icon()
	{
		return 'credit-card';
	}

	/**
	 * Default front navigation
	 *
	 * @code
	 * // No default front navigation
	 * return array();
	 * @endcode
	 * @return array
	 */
	public function defaultFrontNavigation()
	{
		return array();
	}
}
