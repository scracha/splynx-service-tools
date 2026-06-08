<?php

/**
 * Splynx API Client Script
 *
 * This script retrieves a list of all Splynx customers and their
 * internet services, along with their total upload and download traffic counters.
 * It generates a CSV file of the output data.
 *
 * This version of the script calculates the date range dynamically for each
 * service based on its billing start date, and includes services that were
 * active during the report period.
 */

// Include the configuration file
// IMPORTANT: Make a file named 'config.php' in the same directory.
// It should contain:
// $splynxBaseUrl = 'https://your.splynx.url/api/2.0';
// $apiKey = 'your_splynx_api_key';
// $apiSecret = 'your_splynx_api_secret';
require_once 'config.php';

/**
 * Splynx API Client Class
 *
 * This class provides a basic wrapper for interacting with the Splynx API.
 * It uses Basic Authentication for API requests.
 */
class SplynxApiClient
{
    private $apiUrl;
    private $apiKey;
    private $apiSecret;

    /**
     * Constructor
     *
     * @param string $apiUrl The base URL of your Splynx instance.
     * @param string $apiKey Your Splynx API Key.
     * @param string $apiSecret Your Splynx API Secret.
     */
    public function __construct($apiUrl, $apiKey, $apiSecret)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    /**
     * Makes a GET request to the Splynx API using Basic Authentication.
     *
     * @param string $path The API endpoint path.
     * @param array $params Optional query parameters.
     * @return array|null The decoded JSON response, or null on error.
     */
    public function get($path, $params = [])
    {
        $queryString = http_build_query($params);

        $fullUrl = $this->apiUrl . '/' . ltrim($path, '/') . ($queryString ? '?' . $queryString : '');

        // Construct the Basic Authentication header
        $authHeader = 'Basic ' . base64_encode($this->apiKey . ':' . $this->apiSecret);

        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $authHeader,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Splynx-API-Client');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        } else if (($httpCode === 404 && strpos($path, 'customer-traffic-counter') !== false) || strpos($path, 'tariffs/internet') !== false) {
            // Special case for the traffic counter and tariff endpoints, which may return 404.
            // We want to handle this gracefully by returning an empty array or null, and not displaying an error.
            return [];
        } else {
            // Echoing errors for CLI output for all other non-200 responses
            return null;
        }
    }
}

// Parse command line arguments
$options = getopt('', ['end:', 'limit:']);

$endDate = null;
if (isset($options['end'])) {
    try {
        $endDate = DateTime::createFromFormat('d/m/Y', $options['end']);
        if (!$endDate) {
            throw new Exception("Invalid date format. Please use DD/MM/YYYY.");
        }
    } catch (Exception $e) {
        die("Error: " . $e->getMessage() . "\n");
    }
} else {
    // No end date specified, default to yesterday
    $endDate = new DateTime('yesterday');
}

$customerLimit = isset($options['limit']) ? (int)$options['limit'] : null;

// Define the name of the output CSV file with the end date appended
$csvFileName = 'splynx_customers_traffic_data_' . $endDate->format('Y-m-d') . '.csv';

echo "Splynx Whole Month Traffic Report\n";
echo "End date: " . $endDate->format('Y-m-d') . "\n";
if ($customerLimit) {
    echo "Customer limit: {$customerLimit}\n";
}
echo "\n";

$splynx = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret);

// Fetch all customers, without filtering by status
$customers = $splynx->get('admin/customers/customer');

if ($customers !== null && !empty($customers)) {
    if ($customerLimit) {
        $customers = array_slice($customers, 0, $customerLimit);
    }
    echo "Processing " . count($customers) . " customer(s)...\n\n";
} 

// Open the CSV file for writing
$csvFile = fopen($csvFileName, 'w');
if ($csvFile === false) {
    die("Error: Could not open the CSV file '{$csvFileName}' for writing.\n");
}

// Define the CSV header row
$csvHeader = [
    'Customer ID', 'Name', 'Login', 'Email', 'Status',
    'Internet Plan Name', 'Internet Plan Status',
    'Service ID', 'Service Start Date', 'Service End Date',
    'IPv4', 'Router', 'Street', 'Town',
    'Total Upload (GB)', 'Total Download (GB)',
    'Calculated Stats Start Date', 'Calculated Stats End Date'
];
fputcsv($csvFile, $csvHeader);

if ($customers !== null) {
    if (!empty($customers)) {
        $totalCustomers = count($customers);
        $customersProcessed = 0;
        
        foreach ($customers as $customer) {
            if (isset($customer['id'])) {
                $customersProcessed++;
                echo "  [{$customersProcessed}/{$totalCustomers}] Customer #{$customer['id']}: " . ($customer['name'] ?? 'Unknown') . "\n";
                $serviceEndpoint = 'admin/customers/customer/' . $customer['id'] . '/internet-services';
                $internetServices = $splynx->get($serviceEndpoint);

                if ($internetServices !== null && !empty($internetServices)) {
                    foreach ($internetServices as $service) {
                        $serviceStartDate = isset($service['start_date']) ? new DateTime($service['start_date']) : null;
                        
                        // Handle the case where the end_date is an empty string or the invalid dates from the API
                        $serviceEndDate = null;
                        if (isset($service['end_date']) && !empty($service['end_date']) && $service['end_date'] !== '-0001-11-30' && $service['end_date'] !== '0000-00-00') {
                            $serviceEndDate = new DateTime($service['end_date']);
                        }
                        
                        if ($serviceStartDate !== null) {
                            $billingDay = (int) $serviceStartDate->format('d');
                            
                            // Calculate the billing period start date for the current month
                            $currentBillingPeriodStart = clone $endDate;
                            $currentBillingPeriodStart->setDate($currentBillingPeriodStart->format('Y'), $currentBillingPeriodStart->format('m'), $billingDay);
                            
                            // If the end date is before the billing day of the current month,
                            // the current billing period must have started in the previous month.
                            if ($endDate < $currentBillingPeriodStart) {
                                $currentBillingPeriodStart->modify('-1 month');
                                $currentBillingPeriodStart->setDate($currentBillingPeriodStart->format('Y'), $currentBillingPeriodStart->format('m'), $billingDay);
                            }
                            
                            // Calculate the previous full billing cycle for traffic stats
                            $statsPeriodEnd = clone $currentBillingPeriodStart;
                            $statsPeriodEnd->modify('-1 day');
                            
                            $statsPeriodStart = clone $statsPeriodEnd;
                            $statsPeriodStart->modify('-1 month +1 day');

                            // Check if the service's lifetime overlaps with the period we are gathering stats for
                            $serviceIsRelevant = false;
                            // Case 1: Service has no end date (ongoing)
                            if ($serviceEndDate === null) {
                                // Overlap if service started before or on the end of the stats period
                                if ($serviceStartDate <= $statsPeriodEnd) {
                                    $serviceIsRelevant = true;
                                }
                            } else {
                                // Case 2: Service has an end date. Check for overlap of [serviceStartDate, serviceEndDate] and [statsPeriodStart, statsPeriodEnd]
                                if ($serviceEndDate >= $statsPeriodStart && $serviceStartDate <= $statsPeriodEnd) {
                                    $serviceIsRelevant = true;
                                }
                            }
                            
                            if ($serviceIsRelevant) {

                                // Initialize variables for the CSV row
                                $planName = 'N/A';
                                $routerTitle = 'N/A';

                                // Fetch Internet Plan details
                                if (isset($service['tariff_id'])) {
                                    $tariffEndpoint = 'admin/tariffs/internet/' . $service['tariff_id'];
                                    $tariffDetails = $splynx->get($tariffEndpoint);
                                    if ($tariffDetails !== null && !empty($tariffDetails)) {
                                        $planName = $tariffDetails['title'] ?? 'Plan no longer available';
                                    } else {
                                        $planName = 'Plan no longer available';
                                    }
                                }
                                
                                // Fetch Router details
                                if (isset($service['router_id'])) {
                                    $routerEndpoint = 'admin/networking/routers/' . $service['router_id'];
                                    $routerDetails = $splynx->get($routerEndpoint);
                                    if ($routerDetails !== null && !empty($routerDetails)) {
                                        $routerTitle = $routerDetails[ 'title'] ?? 'N/A';
                                    }
                                }

                                // Fetch address information from the geo-internet-service endpoint
                                $geoServiceEndpoint = 'admin/customers/customer/' . $customer['id'] . '/geo-internet-service--' . $service['id'];
                                $geoService = $splynx->get($geoServiceEndpoint);

                                $installStreetDisplay = 'N/A';
                                $installTownDisplay = 'N/A';

                                if ($geoService && isset($geoService['address'])) {
                                    $address = $geoService['address'];
                                    $addressParts = explode(',', $address);
                                    
                                    if (count($addressParts) > 1) {
                                        $installStreetDisplay = trim($addressParts[0]);
                                        $installTownDisplay = trim($addressParts[1]);
                                    } else {
                                        $installStreetDisplay = trim($address);
                                        // Fallback to customer city if no town in address
                                        $installTownDisplay = $customer['city'] ?? 'N/A';
                                    }
                                } else {
                                    // Fallback to customer address if geo data is not available
                                    $installStreetDisplay = $customer['street_1'] ?? 'N/A';
                                    $installTownDisplay = $customer['city'] ?? 'N/A';
                                }
                                
                                // Initialize byte counters for the date range
                                $totalUploadBytes = 0;
                                $totalDownloadBytes = 0;
                                
                                // Use API BETWEEN date filter to only fetch relevant traffic data
                                $trafficParams = [
                                    'main_attributes' => [
                                        'service_id' => $service['id'],
                                        'date' => ['BETWEEN', $statsPeriodStart->format('Y-m-d'), $statsPeriodEnd->format('Y-m-d')],
                                    ]
                                ];
                                $trafficCounters = $splynx->get('admin/customers/customer-traffic-counter', $trafficParams);
                                
                                if ($trafficCounters !== null && !empty($trafficCounters)) {
                                    foreach ($trafficCounters as $counter) {
                                        $totalUploadBytes += $counter['up'] ?? 0;
                                        $totalDownloadBytes += $counter['down'] ?? 0;
                                    }
                                    echo "    Service #{$service['id']}: " . count($trafficCounters) . " counters for period " . $statsPeriodStart->format('Y-m-d') . " to " . $statsPeriodEnd->format('Y-m-d') . "\n";
                                } else {
                                    echo "    Service #{$service['id']}: No traffic data for period\n";
                                }

                                // Convert bytes to gigabytes and format to 2 decimal places
                                $totalUploadGB = number_format($totalUploadBytes / 1073741824, 2);
                                $totalDownloadGB = number_format($totalDownloadBytes / 1073741824, 2);

                                // Prepare data for the CSV row
                                $rowData = [
                                    $customer['id'] ?? '',
                                    $customer['name'] ?? '',
                                    $customer['login'] ?? '',
                                    $customer['email'] ?? '',
                                    $customer['status'] ?? '',
                                    $planName,
                                    $service['status'] ?? '',
                                    $service['id'] ?? '',
                                    ($serviceStartDate ? $serviceStartDate->format('Y-m-d') : 'N/A'),
                                    ($serviceEndDate ? $serviceEndDate->format('Y-m-d') : 'N/A'),
                                    $service['ipv4'] ?? '',
                                    $routerTitle,
                                    $installStreetDisplay,
                                    $installTownDisplay,
                                    $totalUploadGB,
                                    $totalDownloadGB,
                                    $statsPeriodStart->format('Y-m-d'),
                                    $statsPeriodEnd->format('Y-m-d')
                                ];
                                
                                // Write the row to the CSV file
                                fputcsv($csvFile, $rowData);
                            }
                        }
                    }
                }
            }
        }
    } else {
        echo "No customers found.\n";
    }
} else {
    echo "Failed to retrieve customer data. Please check your API Key, Secret, and Splynx API URL.\n";
}

// Close the CSV file
fclose($csvFile);
echo "\nData successfully written to '{$csvFileName}'.\n";

?>