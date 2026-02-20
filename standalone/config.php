<?php
/**
 * moneymotion + Invision Community Standalone Payment Gateway
 * Configuration
 */

return array(
    /*
    |--------------------------------------------------------------------------
    | moneymotion Settings
    |--------------------------------------------------------------------------
    */
    'moneymotion' => array(
        'api_key'           => 'mk_live_oJwAu8PI3LCgtdtUSgVyyNWRHYYC8JgV',
        'api_base_url'      => 'https://api.moneymotion.io',
        'webhook_secret'    => 'eb80a4f9427db425b5e3dcec721197a4053ca9e2688a06536b16253e218ead36',  // Get this from your moneymotion dashboard
    ),

    /*
    |--------------------------------------------------------------------------
    | Invision Community Settings
    |--------------------------------------------------------------------------
    */
    'ips' => array(
        'base_url'  => 'https://r336463.invisionservice.com/',
        'api_key'   => '88041044f4286f24bf7112a94f2c0b1c',
    ),

    /*
    |--------------------------------------------------------------------------
    | This App's URL
    |--------------------------------------------------------------------------
    | The public URL where this standalone app is hosted
    */
    'app_url' => 'http://localhost:8000',  // Local testing

    /*
    |--------------------------------------------------------------------------
    | Database (SQLite - no MySQL needed)
    |--------------------------------------------------------------------------
    */
    'db_path' => __DIR__ . '/data/sessions.sqlite',
);
