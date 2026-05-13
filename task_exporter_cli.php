<?php
/**
 * Splynx Scheduling Task Exporter
 * Fetches open tasks from Splynx and stores them for the map dashboard.
 */

require_once 'config.php';
require_once 'SplynxApiClient.php';
require_once 'customer_cache.php';

const TASK_STORE_PATH = '/dev/shm/splynx_open_tasks.json';

global $splynxBaseUrl, $apiKey, $apiSecret, $splynxCustomerURL;
global $defaultLat, $defaultLng, $geoBoundary, $googleApiKey, $geocodeRegion;

$apiClient = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret);

// --- 1. LOAD SERVICE LOCATION DATA (for customer address fallback) ---
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

// B. Projects
echo "Fetching Projects...\n";
$projectMap = [0 => 'No Project'];
try {
    $projectsRaw = $apiClient->get('admin/scheduling/projects', []);
    if (is_array($projectsRaw) && !isset($projectsRaw['error'])) {
        foreach ($projectsRaw as $proj) {
            if (isset($proj['id'])) {
                $projectMap[$proj['id']] = $proj['title'] ?? $proj['name'] ?? 'Project ' . $proj['id'];
            }
        }
    }
} catch (Exception $e) {
    echo "WARNING: Projects endpoint not accessible. Project IDs will be shown as-is.\n";
}

// C. Locations
echo "Fetching Locations...\n";
$locationMap = [0 => 'No Location'];
try {
    $locationsRaw = $apiClient->get('admin/administration/locations', []);
    if (is_array($locationsRaw) && !isset($locationsRaw['error'])) {
        foreach ($locationsRaw as $loc) {
            if (isset($loc['id'])) {
                $locationMap[$loc['id']] = $loc['name'] ?? $loc['title'] ?? 'Location ' . $loc['id'];
            }
        }
    }
} catch (Exception $e) {
    echo "WARNING: Locations endpoint not accessible.\n";
}

// --- 3. FETCH AND ENRICH TASKS ---
$openTasks = [];
$seenTaskIds = [];
$pageSize = 500;
$fetching = true;
$currentOffset = 0;

echo "Processing Tasks...\n";

while ($fetching) {
    $tasks = $apiClient->get('admin/scheduling/tasks', ['limit' => $pageSize, 'offset' => $currentOffset]);

    if ($tasks && is_array($tasks) && !isset($tasks['error']) && count($tasks) > 0) {
        foreach ($tasks as $task) {
            $id = $task['id'] ?? null;
            if ($id === null || in_array($id, $seenTaskIds)) {
                $fetching = false; break;
            }
            $seenTaskIds[] = $id;

            // Skip closed tasks
            if (($task['closed'] ?? 0) == 1) continue;

            $assigneeId = $task['assignee'] ?? 0;
            $assigneeName = $adminMap[$assigneeId] ?? 'Unassigned';
            $customerId = $task['related_customer_id'] ?? null;
            $serviceId  = $task['related_service_id'] ?? null;

            // GPS from task itself
            $gps = $task['gps'] ?? '';
            $lat = 0;
            $lng = 0;
            if (!empty($gps)) {
                $parts = explode(',', $gps);
                if (count($parts) === 2) {
                    $lat = (float)trim($parts[0]);
                    $lng = (float)trim($parts[1]);
                }
            }

            // Address from task
            $addr = $task['address'] ?? '';
            $customerName = 'No Customer';
            $customerPhone = 'N/A';

            // Fallback chain: linked service → customer's first service → customer via API
            $match = null;
            if ($serviceId && isset($servicesById[$serviceId])) {
                $match = $servicesById[$serviceId];
            } elseif ($customerId && isset($servicesByCustomer[$customerId])) {
                $match = $servicesByCustomer[$customerId];
            }

            // Fallback: if no match (e.g. inactive customer), check cached customer data
            if (!$match && $customerId) {
                $match = getCachedCustomer($customerId, $apiClient);
            }

            if ($match) {
                $customerName = $match['customer_name'] ?? 'Unknown';
                $customerPhone = $match['customer_phone'] ?? 'N/A';

                // Fallback GPS: service location → customer address location
                if ($lat == 0 && $lng == 0) {
                    $lat = (float)($match['service_latitude'] ?? 0);
                    $lng = (float)($match['service_longitude'] ?? 0);
                }
                if ($lat == 0 && $lng == 0) {
                    $lat = (float)($match['customer_lat_fallback'] ?? 0);
                    $lng = (float)($match['customer_lng_fallback'] ?? 0);
                }

                // Fallback address: service address → customer address
                if (empty($addr)) {
                    $addr = $match['service_address'] ?? '';
                }
                if (empty($addr)) {
                    $addr = $match['customer_address_fallback'] ?? 'No address';
                }
            }

            // Clamp out-of-bounds coordinates to default location
            $clamped = false;
            if ($lat < $geoBoundary['lat_min'] || $lat > $geoBoundary['lat_max'] ||
                $lng < $geoBoundary['lng_min'] || $lng > $geoBoundary['lng_max']) {
                $lat = $defaultLat;
                $lng = $defaultLng;
                $clamped = true;
            }

            // Geocode fallback: if still at default and we have an address, try to resolve it
            if ($clamped && !empty($addr) && $addr !== 'No address') {
                $geo = geocodeAddress($addr, $geocodeRegion ?? 'nz');
                if ($geo) {
                    if ($geo['lat'] >= $geoBoundary['lat_min'] && $geo['lat'] <= $geoBoundary['lat_max'] &&
                        $geo['lng'] >= $geoBoundary['lng_min'] && $geo['lng'] <= $geoBoundary['lng_max']) {
                        $lat = $geo['lat'];
                        $lng = $geo['lng'];
                        $clamped = false; // Successfully geocoded — no longer "no address"
                    }
                }
            }

            // Clean priority value (e.g. "priority_medium" -> "medium")
            $rawPriority = $task['priority'] ?? 'normal';
            $priority = preg_replace('/^priority_/', '', $rawPriority);

            $openTasks[] = [
                'task_id'           => $id,
                'title'             => $task['title'] ?? 'Untitled Task',
                'priority'          => $priority,
                'assignee'          => $assigneeName,
                'project'           => $projectMap[$task['project_id'] ?? 0] ?? ('Project ' . ($task['project_id'] ?? '?')),
                'location'          => $locationMap[$task['location_id'] ?? 0] ?? 'No Location',
                'scheduled_from'    => $task['scheduled_from'] ?? null,
                'is_scheduled'      => !empty($task['is_scheduled']),
                'created_at'        => $task['created_at'] ?? null,
                'last_status_changed' => $task['last_status_changed'] ?? null,
                'related_customer_id' => $customerId,
                'customer_name'     => $customerName,
                'customer_phone'    => $customerPhone,
                'address'           => $addr,
                'lat'               => $lat,
                'lng'               => $lng,
                'no_address'        => $clamped,
                'ui_url'            => rtrim($splynxCustomerURL, '/'),
            ];
        }
        if (count($tasks) < $pageSize) $fetching = false;
        $currentOffset += $pageSize;
    } else { $fetching = false; }
}

file_put_contents(TASK_STORE_PATH, json_encode($openTasks, JSON_PRETTY_PRINT));
@chmod(TASK_STORE_PATH, 0666); // Add this line
echo "SUCCESS: Stored " . count($openTasks) . " open tasks.";

if ($geocodeCount > 0) echo " Geocoded $geocodeCount addresses.";
echo "\n";
