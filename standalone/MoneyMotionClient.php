<?php
/**
 * moneymotion API Client
 */
class MoneyMotionClient
{
    private $apiKey;
    private $baseUrl;

    public function __construct($apiKey, $baseUrl = 'https://api.moneymotion.io')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Create a checkout session
     *
     * @param string $description
     * @param array  $urls        ['success' => ..., 'cancel' => ..., 'failure' => ...]
     * @param string $email
     * @param array  $lineItems   [['name' => ..., 'description' => ..., 'pricePerItemInCents' => ..., 'quantity' => ...]]
     * @param array  $metadata
     * @return string Checkout session ID
     */
    public function createCheckoutSession($description, array $urls, $email, array $lineItems, array $metadata = array(), $currency = 'USD')
    {
        $body = array(
            'json' => array(
                'description'   => $description,
                'urls'          => array(
                    'success'   => $urls['success'],
                    'cancel'    => $urls['cancel'],
                    'failure'   => $urls['failure'],
                ),
                'userInfo'      => array(
                    'email'     => $email,
                ),
                'lineItems'     => $lineItems,
            ),
        );

        if (!empty($metadata)) {
            $body['json']['metadata'] = $metadata;
        }

        $extraHeaders = array('x-currency: ' . $currency);
        $response = $this->request('checkoutSessions.createCheckoutSession', $body, $extraHeaders);

        if (isset($response['result']['data']['json']['checkoutSessionId'])) {
            return $response['result']['data']['json']['checkoutSessionId'];
        }

        throw new RuntimeException('moneymotion did not return a checkout session ID. Response: ' . json_encode($response));
    }

    /**
     * Verify webhook signature (HMAC-SHA512)
     */
    public static function verifySignature($rawBody, $signature, $secret)
    {
        $computed = base64_encode(hash_hmac('sha512', $rawBody, $secret, true));
        return hash_equals($computed, $signature);
    }

    /**
     * Make API request
     */
    private function request($endpoint, array $data = array(), array $extraHeaders = array())
    {
        $url = $this->baseUrl . '/' . $endpoint;

        $headers = array(
            'Content-Type: application/json',
            'X-API-Key: ' . $this->apiKey,
        );
        $headers = array_merge($headers, $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => json_encode($data),
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_CAINFO          => __DIR__ . '/cacert.pem',
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('moneymotion API cURL error: ' . $error);
        }

        $decoded = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $response;
            if (isset($decoded['error'])) {
                $msg = is_array($decoded['error']) ? json_encode($decoded['error']) : $decoded['error'];
            }
            throw new RuntimeException("moneymotion API error ({$httpCode}): {$msg}");
        }

        return $decoded;
    }
}
