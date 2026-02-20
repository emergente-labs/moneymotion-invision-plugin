<?php

$lang = array(
	'__app_moneymotion'					=> "moneymotion",
	'module__moneymotion_gateway'			=> "moneymotion Gateway",

	// Gateway
	'gateway__moneymotion'				=> "moneymotion",
	'moneymotion_api_key'				=> "moneymotion API Key",
	'moneymotion_api_key_desc'			=> "Enter your moneymotion API key (starts with mk_live_ or mk_test_).",
	'moneymotion_webhook_secret'			=> "Webhook Signing Secret",
	'moneymotion_webhook_secret_desc'		=> "Enter the webhook signing secret from your moneymotion dashboard. Used to verify webhook payloads.",

	// Payment screen
	'moneymotion_pay_button'				=> "Pay with moneymotion",
	'moneymotion_redirect_message'		=> "You will be redirected to moneymotion to complete your payment.",

	// Status messages
	'moneymotion_payment_processing'		=> "Your payment is being processed. You will be redirected shortly.",
	'moneymotion_payment_cancelled'		=> "Payment was cancelled. Please try again.",
	'moneymotion_payment_failed'			=> "Payment failed. Please try again or use a different payment method.",
	'moneymotion_payment_success'			=> "Payment successful! Your order is being processed.",

	// Errors
	'moneymotion_error_api'				=> "Could not connect to moneymotion. Please try again later.",
	'moneymotion_error_invalid_signature'	=> "Invalid webhook signature.",
	'moneymotion_error_session_not_found'	=> "Checkout session not found.",

	// Admin
	'moneymotion_settings'				=> "moneymotion Settings",
);
