<?php
/**
 * Invision Community REST API Client
 */
class IPSClient
{
    private $baseUrl;
    private $apiKey;

    public function __construct($baseUrl, $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Get an invoice by ID
     *
     * @param int $invoiceId
     * @return array
     */
    public function getInvoice($invoiceId)
    {
        return $this->request('GET', "/nexus/invoices/{$invoiceId}");
    }

    /**
     * Get a transaction by ID
     *
     * @param int $transactionId
     * @return array
     */
    public function getTransaction($transactionId)
    {
        return $this->request('GET', "/nexus/transactions/{$transactionId}");
    }

    /**
     * Get list of invoices
     *
     * @param array $params Query parameters
     * @return array
     */
    public function getInvoices(array $params = array())
    {
        return $this->request('GET', '/nexus/invoices', $params);
    }

    /**
     * Get a member by ID
     *
     * @param int $memberId
     * @return array
     */
    public function getMember($memberId)
    {
        return $this->request('GET', "/core/members/{$memberId}");
    }

    /**
     * Make API request to IPS
     */
    private function request($method, $endpoint, array $params = array())
    {
        $url = $this->baseUrl . '/api' . $endpoint;

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPAUTH        => CURLAUTH_BASIC,
            CURLOPT_USERPWD         => $this->apiKey . ':',
            CURLOPT_USERAGENT       => 'moneymotionPlugin/1.0',
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_CAINFO          => __DIR__ . '/cacert.pem',
        ));

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($params)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('IPS API cURL error: ' . $error);
        }

        $decoded = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = isset($decoded['errorMessage']) ? $decoded['errorMessage'] : $response;
            throw new RuntimeException("IPS API error ({$httpCode}): {$msg}");
        }

        return $decoded;
    }
}
