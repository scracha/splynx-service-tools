<?php
/**
 * Splynx Ticket Attribute Debugger
 * Usage: php ticket_verify_service.php [ticket_id]
 */

require_once 'config.php';
require_once 'SplynxApiClient.php';

global $splynxBaseUrl, $apiKey, $apiSecret;
$apiClient = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret);

// 1. Determine Ticket ID from command line or default
$ticketId = isset($argv[1]) ? (int)$argv[1] : 6849;

if (!$ticketId) {
    die("Usage: php ticket_verify_service.php <ticket_id>\n");
}

echo "--- Looking up Ticket #$ticketId ---\n";

// 2. Fetch the specific ticket
$endpoint = "admin/support/tickets/$ticketId";
$ticket = $apiClient->get($endpoint, []);

if ($ticket && is_array($ticket)) {
    echo "Subject: " . ($ticket['subject'] ?? 'N/A') . "\n";
    echo "Status ID: " . ($ticket['status_id'] ?? 'N/A') . "\n";
    echo "Closed: " . (isset($ticket['closed']) ? ($ticket['closed'] ? 'True' : 'False') : 'N/A') . "\n";
    
    // Check for service_id in additional_attributes
    $additional = $ticket['additional_attributes'] ?? [];
    echo "Service ID: " . ($additional['service_id'] ?? 'NOT FOUND') . "\n";
    
    echo "------------------------------------------------------\n";
    echo "FULL RAW DATA:\n";
    print_r($ticket);
} else {
    echo "Error: Could not retrieve Ticket #$ticketId. Check ID or API connectivity.\n";
}