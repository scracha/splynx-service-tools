<?php

/**
 * Splynx Background Data Exporter (CLI Script)
 *
 * This script runs via cron/timer, fetches ALL customers and their services from Splynx,
 * and compiles services with status 'active' or 'stopped' into a single JSON file
 * indexed by IPv4 address for fast lookup.
 */

require_once 'config.php';
// Load the Splynx API Client class from its dedicated file
require_once 'SplynxApiClient.php'; 

// Silence all output during background operation
$isSilent = !in_array('--verbose', $argv ?? []); 

// 🚨 DEBUG CODE: Set to 0 for no limit.
$SERVICE_EXPORT_LIMIT = 0; 
$serviceCount = 0;
// --- End Debug Code ---

/**
 * Fetch data from Splynx API with retry logic for timeout errors
 * 
 * @param SplynxApiClient $apiClient The API client instance
 * @param string $endpoint The API endpoint to call
 * @param array $params Query parameters
 * @param int $maxRetries Maximum number of retry attempts (default: 3)
 * @param int $retryDelay Delay in seconds between retries (default: 60)
 * @return array|bool The API response or false on failure
 */
function fetchWithRetry($apiClient, $endpoint, $params = [], $maxRetries = 3, $retryDelay = 60) {
    $attempt = 0;
    
    while ($attempt < $maxRetries) {
        try {
            $attempt++;
            $result = $apiClient->get($endpoint, $params);
            
            // Success - return the result (no logging on success)
            return $result;
            
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            
            // Check if this is a timeout error
            $isTimeout = (strpos($errorMsg, 'timed out') !== false || strpos($errorMsg, 'timeout') !== false);
            
            if ($isTimeout && $attempt < $maxRetries) {
                // Wait before retrying (only log on failure)
                error_log("CRON WARNING: Attempt {$attempt}/{$maxRetries} timed out for {$endpoint}. Waiting {$retryDelay} seconds before retry...");
                sleep($retryDelay);
            } else if ($attempt >= $maxRetries) {
                // Max retries reached
                error_log("CRON ERROR: Max retries ({$maxRetries}) reached for {$endpoint}. Giving up. Last error: {$errorMsg}");
                return false;
            } else {
                // Non-timeout error - don't retry
                error_log("CRON ERROR: Non-timeout error for {$endpoint}: {$errorMsg}");
                return false;
            }
        }
    }
    
    return false;
}

// --- Main Execution ---

// 1. Initialize API Client
// Access the API configuration variables globally from config.php
global $splynxBaseUrl, $apiKey, $apiSecret; 
$apiClient = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret);

// Use a longer timeout for the large customer fetch (50k records)
$apiClient->setRequestTimeout(120);

// --- Fetch all routers for name lookup ---
$routerMap = [];
$routersRaw = $apiClient->get('admin/networking/routers', ['limit' => 5000]);
if (is_array($routersRaw) && !isset($routersRaw['error'])) {
    foreach ($routersRaw as $router) {
        if (isset($router['id'])) {
            $routerMap[$router['id']] = $router['title'] ?? ('Router ' . $router['id']);
        }
    }
}
if (!$isSilent) echo "Loaded " . count($routerMap) . " routers.\n";

// 2. Define search parameters. No filtering to fetch ALL customers.
$customerSearchParams = [
    // Request all required customer-level fields
    'fields' => 'id,name,status,email,phone,address,lat,lng,additional_attributes', 
    'with_additional_attributes' => 1,
    'limit' => 50000,
];

// --- STEP 1: Fetch all customers (up to limit) with retry logic ---
$customers = fetchWithRetry($apiClient, 'admin/customers/customer', $customerSearchParams, 3, 60);
$activeServicesData = [];

// 3. Process data
if ($customers) {
    if (empty($customers)) {
          error_log("CRON WARNING: Splynx returned zero customers. Data file not updated.");
    } else {
        $customerTotal = count($customers);
        $customerProcessed = 0;
        if (!$isSilent) echo "Processing $customerTotal customers...\n";
        
        foreach ($customers as $customer) {
            $customerProcessed++;
            if (!$isSilent && $customerProcessed % 100 === 0) {
                echo "  Progress: $customerProcessed / $customerTotal customers ($serviceCount services)\n";
            }
            
            // --- STEP 1: Define Customer-level data once (includes customer_id and fallbacks) ---

			// 1. Construct the address fallback from individual address components (street_1 and city only)
			$street = trim($customer['street_1'] ?? '');
			$city = trim($customer['city'] ?? '');
			$fullAddress = trim($street . ($city ? ', ' . $city : ''));

			// 2. Parse the GPS string (e.g., "34.0522,-118.2437")
			$gpsString = $customer['gps'] ?? null;
			$customerCoords = [];

			if (!empty($gpsString)) {
				$customerCoords = array_map('trim', explode(',', $gpsString));
			}
			$fallbackLat = (float)($customerCoords[0] ?? 0.0);
			$fallbackLng = (float)($customerCoords[1] ?? 0.0);

			// 3. Define the final customer data array
			$customerData = [
				'customer_id'       => $customer['id'] ?? '',
				'customer_name'     => $customer['name'] ?? 'N/A',
				'customer_status'   => $customer['status'] ?? 'N/A',
				'customer_phone'    => $customer['phone'] ?? '',
				'customer_email'    => $customer['email'] ?? '',
				
				// **FIXED FALLBACK EXTRACTION:**
				'customer_address_fallback' => $fullAddress, 
				'customer_lat_fallback'     => $fallbackLat,
				'customer_lng_fallback'     => $fallbackLng,
			];
			// --- End STEP 1 ---
           // --- Extract Custom Attributes (Customer-level) ---
			$customAttrs = [];
			if (defined('CUSTOM_ATTRIBUTES')) { 
				// Splynx has returned the raw attributes in a simple associative array
				$customerAdditionalAttrs = $customer['additional_attributes'] ?? [];
				
				foreach (CUSTOM_ATTRIBUTES as $splynxKey => $outputKey) { 
					// Directly check if the Splynx key exists in the raw array
					if (isset($customerAdditionalAttrs[$splynxKey]) && $customerAdditionalAttrs[$splynxKey] !== '') {
						$customAttrs[$outputKey] = $customerAdditionalAttrs[$splynxKey]; 
					}
				}
			}
			// --- End Custom Attribute Extraction ---
            
            // --- STEP 2: Retrieve services for the specific customer with retry logic ---
            if (isset($customer['id'])) {
                $services = fetchWithRetry($apiClient, 'admin/customers/customer/' . $customer['id'] . '/internet-services', [], 3, 60);
                
                foreach ($services ?? [] as $service) {
                    $ipv4 = trim($service['ipv4'] ?? ''); 
                    $serviceStatus = $service['status'] ?? '';
                    
                    if (($serviceStatus === 'active' || $serviceStatus === 'stopped') && !empty($ipv4)) { 

                        // Get location string: Service Marker is preferred
                        $latLngString = $service['geo']['marker'] ?? null;
                        $coordinates = [];

                        if (!empty($latLngString)) {
                            $coordinates = array_map('trim', explode(',', $latLngString));
                        }
                        
                        // Assign location values (Service location preferred, fallback to Customer data)
                        $serviceAddress   = $service['geo']['address'] ?? $customerData['customer_address_fallback'];
                        $serviceLatitude  = (float)($coordinates[0] ?? $customerData['customer_lat_fallback']); 
                        $serviceLongitude = (float)($coordinates[1] ?? $customerData['customer_lng_fallback']);
                        
                        if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            
                            $serviceCount++;

                            $serviceData = [
                                'service_status'    => $serviceStatus,
                                'service_id'        => $service['id'] ?? '',
                                'service_ipv4'      => $ipv4,
                                'service_address'   => $serviceAddress, 
                                'service_latitude'  => $serviceLatitude,  
                                'service_longitude' => $serviceLongitude, 
                                'service_description' => $service['description'] ?? '',
                                'router_name'       => $routerMap[$service['router_id'] ?? 0] ?? 'Unknown',
                            ];
                            
                            // Merge Customer Data, Service Data, and Custom Attributes
                            $activeServicesData[$ipv4] = array_merge($customerData, $serviceData, $customAttrs);
                            
                            // Stop exporting if limit is reached (for debugging)
                            if ($SERVICE_EXPORT_LIMIT > 0 && $serviceCount >= $SERVICE_EXPORT_LIMIT) {
                                break 2; // Exit the service loop and the customer loop
                            }
                        }
                    }
                }
            }
        } // End customer loop 
    }
} else {
    error_log("CRON ERROR: Failed to retrieve customer data from Splynx. Check API key/secret and URL in config.php.");
}

// ======================================================
// DEBUG BLOCK: Output details for the first processed customer/service and EXIT.
// REMOVE THIS BLOCK BEFORE DEPLOYING!
// ======================================================
//if (!empty($activeServicesData)) {
    // Get the data for the very first IP found
    //$firstServiceData = reset($activeServicesData);
    
    // Find the raw customer data object for the raw attributes dump
	/*
    &$customerID = $firstServiceData['customer_id'] ?? null;
    $rawCustomer = current(array_filter($customers, fn($c) => ($c['id'] ?? '') == $customerID));
    $rawAttributes = $rawCustomer['additional_attributes'] ?? [];

    echo "\n======================================================\n";
    echo "DEBUG OUTPUT: FIRST SERVICE DATA (NO FILE SAVED)\n";
    echo "======================================================\n";
    echo "Total Services Processed: " . count($activeServicesData) . "\n";
    echo "--- FINAL MERGED DATA for IP: " . ($firstServiceData['service_ipv4'] ?? 'N/A') . " ---\n";
    print_r($firstServiceData);
    
    echo "\n--- RAW SPYLNX ATTRIBUTES FOR CUSTOMER ID " . ($customerID ?? 'N/A') . " ---\n";
    print_r($rawAttributes);
    echo "======================================================\n\n";
    exit(0); 
	}
	*/
// ======================================================

// 4. Save the compiled IP data as JSON to shared memory
if (!empty($activeServicesData)) {
    $finalCount = count($activeServicesData);
    $logMessage = "Splynx data updated at " . DATA_STORE_PATH . " with " . $finalCount . " services.";
    
    $json = json_encode($activeServicesData, JSON_PRETTY_PRINT);

    if (file_put_contents(DATA_STORE_PATH, $json) === false) {
        error_log("CRON ERROR: Could not write data to " . DATA_STORE_PATH);
    } else {
        if (!$isSilent) {
             echo "SUCCESS: $logMessage\n";
        }
        error_log("CRON SUCCESS: $logMessage");
    }
} else {
    error_log("CRON WARNING: Zero services found for the filtered criteria. Data file not updated."); 
}

?>