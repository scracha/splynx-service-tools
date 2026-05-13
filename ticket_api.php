<?php
/**
 * Splynx Ticket API Endpoint
 *
 * Serves the pre-generated ticket data from shared memory for instant lookup.
 */

require_once 'config.php';

header('Content-Type: application/json');

// Path used by the exporter
const TICKET_STORE_PATH = '/dev/shm/splynx_open_tickets.json';

// 1. Check if the data file exists
if (!file_exists(TICKET_STORE_PATH)) {
    http_response_code(503);
    echo json_encode(['error' => 'Ticket data not available. Exporter job has not run yet.']);
    exit;
}

// 2. Load and decode the data
$jsonData = file_get_contents(TICKET_STORE_PATH);
$tickets = json_decode($jsonData, true);

if ($tickets === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to parse ticket data file.']);
    exit;
}

// 3. Respond with the full list of open tickets
echo json_encode($tickets);
?>