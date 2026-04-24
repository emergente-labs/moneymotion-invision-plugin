<?php
/**
 * PHPUnit Bootstrap
 *
 * Loads IPS stubs first so the plugin classes can resolve their
 * parent classes, then loads Composer autoloader.
 */

require_once __DIR__ . '/Stubs/IPS.php';
require_once __DIR__ . '/../vendor/autoload.php';

/*
 * IPS-style class aliasing
 *
 * IPS uses an "underscore prefix" convention: classes are defined as
 * `_ClassName` and then dynamically aliased to `ClassName` after hook
 * processing. For testing we load the plugin classes with their real
 * `_` prefix and create aliases so runtime references like
 * `\IPS\moneymotion\Api\Client::fromGateway()` resolve.
 */
require_once __DIR__ . '/../applications/moneymotion/sources/Api/Client.php';

if ( !class_exists( '\\IPS\\moneymotion\\Api\\Client', false ) )
{
	class_alias( '\\IPS\\moneymotion\\Api\\_Client', '\\IPS\\moneymotion\\Api\\Client' );
}
