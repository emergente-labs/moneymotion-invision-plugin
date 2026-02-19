<?php

$lang = array(
	'__app_moneymotion'					=> "MoneyMotion",
	'module__moneymotion_gateway'			=> "MoneyMotion Gateway",

	// Gateway
	'gateway__MoneyMotion'				=> "MoneyMotion",
	'moneymotion_api_key'				=> "MoneyMotion API Key",
	'moneymotion_api_key_desc'			=> "Enter your MoneyMotion API key (starts with mk_live_ or mk_test_).",
	'moneymotion_webhook_secret'			=> "Webhook Signing Secret",
	'moneymotion_webhook_secret_desc'		=> "Enter the webhook signing secret from your MoneyMotion dashboard. Used to verify webhook payloads.",

	// Payment screen
	'moneymotion_pay_button'				=> "Pay with MoneyMotion",
	'moneymotion_redirect_message'		=> "You will be redirected to MoneyMotion to complete your payment.",

	// Status messages
	'moneymotion_payment_processing'		=> "Your payment is being processed. You will be redirected shortly.",
	'moneymotion_payment_cancelled'		=> "Payment was cancelled. Please try again.",
	'moneymotion_payment_failed'			=> "Payment failed. Please try again or use a different payment method.",
	'moneymotion_payment_success'			=> "Payment successful! Your order is being processed.",

	// Errors
	'moneymotion_error_api'				=> "Could not connect to MoneyMotion. Please try again later.",
	'moneymotion_error_invalid_signature'	=> "Invalid webhook signature.",
	'moneymotion_error_session_not_found'	=> "Checkout session not found.",

	// Admin
	'moneymotion_settings'				=> "MoneyMotion Settings",
);
