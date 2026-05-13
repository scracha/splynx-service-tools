<?php
/**
 * Splynx Support Ticket Exporter
 * Optimized for: Correct Status Mapping and Navigation Links
 */

require_once 'config.php';
require_once 'SplynxApiClient.php';
require_once 'customer_cache.php';

const TICKET_STORE_PATH = '/dev/shm/splynx_open_tickets.json';

global $splynxBaseUrl, $apiKey, $apiSecret;
global $splynxCustomerURL;
global $defaultLat, $defaultLng, $geoBoundary, $googleApiKey, $geocodeRegion;


$apiClient = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret);

// --- 1. LOAD SERVICE LOCATION DATA ---
if (!file_exists(DATA_STORE_PATH)) {
    die("Error: Service data store not found. Run your service exporter first.\n");
}
$rawServiceData = json_decode(file_get_contents(DATA_STORE_PATH), true);
$servicesById = [];
$servicesByCustomer = [];

foreach ($rawServiceData as $service) {
    if (isset($service['service_id'])) { 
        $servicesById[$service['service_id']] = $service; 
    }
    $cId = $service['customer_id'] ?? null;
    if ($cId && !isset($servicesByCustomer[$cId])) { 
        $servicesByCustomer[$cId] = $service; 
    }
}

// --- 2. FETCH REFERENCE DATA ---

// A. Administrators
echo "Fetching Administrators...\n";
$adminsRaw = $apiClient->get('admin/administration/administrators', []);
$adminMap = [0 => 'Unassigned']; 
if (is_array($adminsRaw) && !isset($adminsRaw['error'])) {
    foreach ($adminsRaw as $admin) {
        if (isset($admin['id'])) {
            $adminMap[$admin['id']] = !empty($admin['name']) ? $admin['name'] : $admin['login'];
        }
    }
}

// B. Ticket Statuses (Using title_for_agent for NOC clarity)
echo "Fetching Statuses...\n";
$statusesRaw = $apiClient->get('admin/support/tickets-statuses', []);
$statusMap = [];
if (is_array($statusesRaw) && !isset($statusesRaw['error'])) {
    foreach ($statusesRaw as $status) {
        if (isset($status['id'])) {
            $label = $status['title_for_agent'] ?? ($status['title'] ?? ($status['name'] ?? 'Status ' . $status['id']));
            $statusMap[$status['id']] = $label;
        }
    }
}

// C. Ticket Types
echo "Fetching Types...\n";
$typesRaw = $apiClient->get('admin/support/tickets-types', []);
$typeMap = [];
if (is_array($typesRaw) && !isset($typesRaw['error'])) {
    foreach ($typesRaw as $type) {
        if (isset($type['id'])) {
            $typeMap[$type['id']] = $type['title'] ?? ($type['name'] ?? 'General');
        }
    }
}

// --- 3. FETCH AND ENRICH TICKETS ---
$openTickets = [];
$seenTicketIds = []; 
$pageSize = 500; 
$fetching = true;
$currentOffset = 0; 

echo "Processing Active Tickets...\n";

while ($fetching) {
    $tickets = $apiClient->get('admin/support/tickets', ['limit' => $pageSize, 'offset' => $currentOffset]);

    if ($tickets && is_array($tickets) && !isset($tickets['error']) && count($tickets) > 0) {
        foreach ($tickets as $ticket) {
            $id = $ticket['id'] ?? null;
            if ($id === null || in_array($id, $seenTicketIds)) {
                $fetching = false; break; 
            }
            $seenTicketIds[] = $id;

            // Exclusion Filter: Skip Trash/Closed
            if (($ticket['trash'] ?? 0) != 1 && ($ticket['closed'] ?? 0) != 1) {
                
                $assignedId = $ticket['assign_to'] ?? 0;
                $assignedName = $adminMap[$assignedId] ?? 'Unassigned';

                $tServiceId = $ticket['additional_attributes']['service_id'] ?? null;
                $tCustomerId = $ticket['customer_id'] ?? null;
                
                $match = null;
                if ($tServiceId && isset($servicesById[$tServiceId])) {
                    $match = $servicesById[$tServiceId];
                } elseif ($tCustomerId && isset($servicesByCustomer[$tCustomerId])) {
                    $match = $servicesByCustomer[$tCustomerId];
                }

                // Fallback: if no match (e.g. inactive customer), check cached customer data
                if (!$match && $tCustomerId) {
                    $match = getCachedCustomer($tCustomerId, $apiClient);
                }

                $lat = $match['service_latitude'] ?? ($match['customer_lat_fallback'] ?? 0);
                $lng = $match['service_longitude'] ?? ($match['customer_lng_fallback'] ?? 0);
                $addr = $match['service_address'] ?? ($match['customer_address_fallback'] ?? 'No address');

                // Clamp out-of-bounds coordinates to default location
                $clamped = false;
                if ((float)$lat < $geoBoundary['lat_min'] || (float)$lat > $geoBoundary['lat_max'] ||
                    (float)$lng < $geoBoundary['lng_min'] || (float)$lng > $geoBoundary['lng_max']) {
                    $lat = $defaultLat;
                    $lng = $defaultLng;
                    $clamped = true;
                }

                // Geocode fallback: if clamped and we have an address, try to resolve it
                if ($clamped && !empty($addr) && $addr !== 'No address') {
                    $geo = geocodeAddress($addr, $geocodeRegion ?? 'nz');
                    if ($geo) {
                        if ($geo['lat'] >= $geoBoundary['lat_min'] && $geo['lat'] <= $geoBoundary['lat_max'] &&
                            $geo['lng'] >= $geoBoundary['lng_min'] && $geo['lng'] <= $geoBoundary['lng_max']) {
                            $lat = $geo['lat'];
                            $lng = $geo['lng'];
                            $clamped = false;
                        }
                    }
                }

                // Pass extra ID and URL fields for the Map frontend
                $openTickets[] = [
                    'ticket_id'      => $id,
                    'customer_id'    => $tCustomerId,
                    'subject'        => $ticket['subject'] ?? 'No Subject',
                    'customer_name'  => $match['customer_name'] ?? 'Unknown',
                    'priority'       => $ticket['priority'] ?? 'normal',
                    'status_label'   => $statusMap[$ticket['status_id']] ?? 'Status ' . $ticket['status_id'],
                    'type_label'     => $typeMap[$ticket['type_id']] ?? 'Type ' . $ticket['type_id'],
                    'assigned_to'    => $assignedName,
                    'service_address'=> $addr,
                    'customer_phone' => $match['customer_phone'] ?? 'N/A',
                    'router_name'    => $match['router_name'] ?? 'Unknown',
                    'lat'            => (float)$lat,
                    'lng'            => (float)$lng,
                    'no_address'     => $clamped,
                    'ui_url' 		 => rtrim($splynxCustomerURL, '/'),
					'created_at'     => $ticket['created_at'] ?? null
                ];
            }
        }
        if (count($tickets) < $pageSize) $fetching = false;
        $currentOffset += $pageSize;
    } else { $fetching = false; }
}

file_put_contents(TICKET_STORE_PATH, json_encode($openTickets, JSON_PRETTY_PRINT));
@chmod(TICKET_STORE_PATH, 0666); // Add this line
echo "SUCCESS: Stored " . count($openTickets) . " tickets with linking data.\n";

