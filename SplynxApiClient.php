<?php
// SplynxApiClient.php

/**
 * Enhanced Splynx API Client Class for interacting with the Splynx API.
 * Includes timeout protection, enhanced date filtering, and improved error handling.
 */
class SplynxApiClient
{
    private $apiUrl;
    private $apiKey;
    private $apiSecret;
    private $requestTimeout = 30; // Default 30 second timeout
    private $connectTimeout = 10; // Default 10 second connection timeout

    public function __construct($apiUrl, $apiKey, $apiSecret)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    /**
     * Set the request timeout in seconds.
     *
     * @param int $seconds Timeout in seconds.
     */
    public function setRequestTimeout($seconds)
    {
        $this->requestTimeout = $seconds;
    }

    /**
     * Set the connection timeout in seconds.
     *
     * @param int $seconds Connection timeout in seconds.
     */
    public function setConnectTimeout($seconds)
    {
        $this->connectTimeout = $seconds;
    }

    /**
     * Enhanced GET request with date filtering support and timeout protection.
     * Supports BETWEEN date filtering for traffic counter endpoints.
     *
     * @param string $endpoint The API endpoint (e.g., 'customers/customer-service/service-list').
     * @param array $params Query parameters.
     * @return array|bool Decoded JSON response array on success, false on failure.
     */
    public function get($endpoint, $params = [])
    {
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');
        
        // Handle special BETWEEN date filtering
        $queryString = $this->buildQueryString($params);
        if ($queryString) {
            $url .= '?' . $queryString;
        }

        return $this->makeRequest($url, 'GET');
    }

    /**
     * Enhanced GET request with explicit date filtering support.
     * This method provides the same functionality as get() but with a more explicit name
     * for backward compatibility with existing traffic-reports code.
     *
     * @param string $endpoint The API endpoint.
     * @param array $params Query parameters.
     * @return array|bool Decoded JSON response array on success, false on failure.
     */
    public function getWithDateFilter($endpoint, $params = [])
    {
        return $this->get($endpoint, $params);
    }

    /**
     * Build query string with support for BETWEEN date filtering.
     *
     * @param array $params Query parameters.
     * @return string Built query string.
     */
    private function buildQueryString($params)
    {
        // Handle special BETWEEN date filtering for traffic counter endpoints
        if (isset($params['main_attributes']['date']) && is_array($params['main_attributes']['date']) && 
            isset($params['main_attributes']['date'][0]) && $params['main_attributes']['date'][0] === 'BETWEEN') {
            
            $dates = array_slice($params['main_attributes']['date'], 1); // Get dates after 'BETWEEN'
            unset($params['main_attributes']['date']); // Remove the BETWEEN array
            
            // Manually build the query string for the BETWEEN filter
            $filterString = sprintf(
                'filter[attribute]=date&filter[operator]=BETWEEN&filter[value][0]=%s&filter[value][1]=%s',
                urlencode($dates[0]),
                urlencode($dates[1])
            );
            
            // Build the rest of the query string as normal
            $remainingParams = http_build_query($params);
            return $filterString . ($remainingParams ? '&' . $remainingParams : '');
        }
        
        return http_build_query($params);
    }

    /**
     * Enhanced request method with timeout protection and better error handling.
     *
     * @param string $url Full URL to request.
     * @param string $method HTTP method (GET, POST, PUT).
     * @param array|null $data Data for POST/PUT requests.
     * @return array|bool Decoded JSON response array on success, false on failure.
     * @throws Exception On timeout or critical errors.
     */
    private function makeRequest($url, $method = 'GET', $data = null)
    {
        $headers = [
            'Authorization: Basic ' . base64_encode($this->apiKey . ':' . $this->apiSecret),
            'Accept: application/json'
        ];

        if ($method === 'POST' || $method === 'PUT') {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Splynx-API-Client');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        // Add timeout protection
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->requestTimeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        // Handle curl errors (including timeouts)
        if ($response === false) {
            $errorMsg = "cURL Error for {$method} {$url}: [{$curlErrno}] {$curlError}";
            error_log($errorMsg);
            
            if (strpos($curlError, 'timeout') !== false || strpos($curlError, 'timed out') !== false) {
                throw new Exception("API request timed out after {$this->requestTimeout} seconds: $curlError");
            } else {
                throw new Exception("API request failed: $curlError");
            }
        }

        // Handle successful responses
        if ($httpCode >= 200 && $httpCode < 300) {
            $responseData = json_decode($response, true);
            return $responseData !== null ? $responseData : true;
        }

        // Handle 404 gracefully for traffic counter endpoints (common for missing data)
        if ($httpCode === 404) {
            error_log("INFO: {$method} {$url} returned HTTP 404 (no data found)");
            return [];
        }

        // Handle other HTTP errors
        $responseData = json_decode($response, true);
        $errorMsg = "API Request Error ({$method}): URL: {$url}, HTTP Code: {$httpCode}, Response: " . substr($response, 0, 500);
        error_log($errorMsg);
        
        // This is a global used in CLI scripts to suppress output
        global $isSilent;
        $shouldEcho = !isset($isSilent) || !$isSilent;
        if ($shouldEcho) {
            echo "ERROR: API Request failed with HTTP {$httpCode}. Response: " . substr($response, 0, 200) . "\n";
        }

        // For validation errors (422), return the response data so caller can inspect
        if ($httpCode === 422 && $responseData !== null) {
            return $responseData;
        }

        // For other errors, throw exception
        throw new Exception("API request failed with HTTP code {$httpCode}");
    }
    
    /**
     * Performs a POST request to the Splynx API with enhanced error handling.
     *
     * @param string $endpoint The API endpoint.
     * @param array $data Data to be sent in the request body.
     * @return array|bool Decoded JSON response array on success, false on failure.
     */
    public function post($endpoint, $data)
    {
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');
        
        // Debug logging
        error_log("DEBUG: Attempting POST to: $url");
        error_log("DEBUG: Payload: " . json_encode($data));

        try {
            return $this->makeRequest($url, 'POST', $data);
        } catch (Exception $e) {
            // This is a global used in CLI scripts to suppress output
            global $isSilent;
            $shouldEcho = !isset($isSilent) || !$isSilent;
            if ($shouldEcho) {
                echo "ERROR: POST request failed: " . $e->getMessage() . "\n";
            }
            return false;
        }
    }
    
    /**
     * Performs a PUT request to the Splynx API with enhanced error handling.
     *
     * @param string $endpoint The API endpoint.
     * @param array $data Data to be sent in the request body.
     * @return array|bool Decoded JSON response array on success, false on failure.
     */
    public function put($endpoint, $data)
    {
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');

        try {
            $result = $this->makeRequest($url, 'PUT', $data);
            
            // For PUT operations, treat successful HTTP codes as simple success
            if ($result === true || is_array($result)) {
                return true;
            }
            
            return $result;
        } catch (Exception $e) {
            // This is a global used in CLI scripts to suppress output
            global $isSilent;
            $shouldEcho = !isset($isSilent) || !$isSilent;
            if ($shouldEcho) {
                echo "ERROR: PUT request failed: " . $e->getMessage() . "\n";
            }
            return false;
        }
    }

    /**
     * Legacy method for backward compatibility.
     * Use makeRequest() directly for new code.
     *
     * @deprecated Use makeRequest() instead.
     */
    private function handleResponse($ch, $response, $endpoint, $method = 'GET')
    {
        // This method is kept for backward compatibility but is no longer used internally
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        // This is a global used in CLI scripts to suppress output
        global $isSilent;
        $shouldEcho = !isset($isSilent) || !$isSilent;

        if ($response === false) {
            $errorMsg = "cURL Error for {$method} {$endpoint}: [{$curlErrno}] {$curlError}";
            error_log($errorMsg);
            if ($shouldEcho) { echo "ERROR: {$errorMsg}\n"; }
            return false;
        }

        $responseData = json_decode($response, true);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("SUCCESS: {$method} {$endpoint} returned HTTP {$httpCode}");
            return $responseData;
        }

        // Log API Errors
        $errorMsg = "API Request Error ({$method}): Endpoint: {$endpoint}, HTTP Code: {$httpCode}, Response: " . substr($response, 0, 500);
        error_log($errorMsg);
        if ($shouldEcho) { echo "ERROR: API Request failed with HTTP {$httpCode}. Response: " . substr($response, 0, 200) . "\n"; }

        // Return the error response array for caller to inspect (especially 422 validation errors)
        return $responseData;
    }
}

?>
