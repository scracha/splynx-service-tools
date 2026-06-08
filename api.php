<?php

/**
 * Splynx IP Lookup API Endpoint
 *
 * This script serves as a fast, low-latency API endpoint. It reads the pre-generated
 * data from the shared memory file, applies client-side filters, and returns the 
 * service details for a given IP.
 */

require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- 1. Validate Input & Get Filters ---
$targetIp = $_GET['ipv4'] ?? null;

// Get filter states, default to true if not set or invalid
// FILTER_NULL_ON_FAILURE ensures non-boolean strings default to the fallback (true)
$includeStopped = filter_var($_GET['includeStopped'] ?? 'true', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
$includeBlocked = filter_var($_GET['includeBlocked'] ?? 'true', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;


if (empty($targetIp) || !filter_var($targetIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing IPv4 parameter.']);
    exit;
}

// --- 2. Load Data from Shared Memory ---
// The data file contains all customers and all 'active' or 'stopped' services.
if (!file_exists(DATA_STORE_PATH)) {
    http_response_code(503);
    echo json_encode(['error' => 'Service data not available. Exporter job may not have run yet.']);
    exit;
}

$jsonData = file_get_contents(DATA_STORE_PATH);
$servicesIndex = json_decode($jsonData, true);

if ($servicesIndex === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to parse service data file.']);
    exit;
}

// --- 3. Apply Filters ---
$filteredServices = $servicesIndex;

if (!$includeStopped || !$includeBlocked) {
    $filteredServices = array_filter($servicesIndex, function (array $service) use ($includeStopped, $includeBlocked) {
        $serviceStatus = strtolower($service['service_status'] ?? '');
        $customerStatus = strtolower($service['customer_status'] ?? '');

        // 1. Service Status Check (If includeStopped is false, exclude 'stopped' services)
        if (!$includeStopped && $serviceStatus === 'stopped') {
            return false;
        }

        // 2. Customer Status Check (If includeBlocked is false, exclude 'blocked' customers)
        if (!$includeBlocked && $customerStatus === 'blocked') {
            return false;
        }
        
        // Include service if it passed all exclusions
        return true;
    });
}

// --- 4. Get Last Updated Timestamp ---
$lastUpdated = null;
if (file_exists(DATA_STORE_PATH)) {
    $lastUpdated = date('Y-m-d H:i:s', filemtime(DATA_STORE_PATH));
}

// --- 5. Lookup and Respond ---
if (isset($filteredServices[$targetIp])) {
    http_response_code(200);
    $response = $filteredServices[$targetIp];
    $response['last_updated'] = $lastUpdated;

    // --- 5b. Attach open tickets & tasks for this customer (active services only) ---
    $serviceStatus = strtolower($response['service_status'] ?? '');
    $customerId = $response['customer_id'] ?? null;

    if ($customerId && $serviceStatus === 'active') {
        // Tickets
        $ticketStorePath = '/dev/shm/splynx_open_tickets.json';
        if (file_exists($ticketStorePath)) {
            $allTickets = json_decode(file_get_contents($ticketStorePath), true) ?? [];
            $custTickets = array_values(array_filter($allTickets, fn($t) => ($t['customer_id'] ?? null) == $customerId));
            // Sort newest first by created_at
            usort($custTickets, function($a, $b) {
                return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
            });
            $response['open_tickets'] = $custTickets;
        } else {
            $response['open_tickets'] = [];
        }

        // Tasks
        $taskStorePath = '/dev/shm/splynx_open_tasks.json';
        if (file_exists($taskStorePath)) {
            $allTasks = json_decode(file_get_contents($taskStorePath), true) ?? [];
            $custTasks = array_values(array_filter($allTasks, fn($t) => ($t['related_customer_id'] ?? null) == $customerId));
            // Sort newest first by created_at
            usort($custTasks, function($a, $b) {
                return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
            });
            $response['open_tasks'] = $custTasks;
        } else {
            $response['open_tasks'] = [];
        }
    } else {
        $response['open_tickets'] = [];
        $response['open_tasks'] = [];
    }

    echo json_encode($response);
} else {
    http_response_code(404);
    echo json_encode([
        'error' => 'No service found for this IPv4 address with current filter settings.',
        'last_updated' => $lastUpdated
    ]);
}

?>