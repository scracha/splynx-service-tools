<?php
/**
 * Simple customer data cache for inactive/missing customers.
 * Stores in /dev/shm for fast access with a configurable TTL.
 * Avoids repeated API calls for customers not in the service data store.
 */

const CUSTOMER_CACHE_PATH = '/dev/shm/splynx_customer_cache.json';
const CUSTOMER_CACHE_TTL  = 2592000; // 30 days in seconds

function loadCustomerCache() {
    if (!file_exists(CUSTOMER_CACHE_PATH)) return [];
    $data = json_decode(file_get_contents(CUSTOMER_CACHE_PATH), true);
    return is_array($data) ? $data : [];
}

function saveCustomerCache($cache) {
    file_put_contents(CUSTOMER_CACHE_PATH, json_encode($cache, JSON_PRETTY_PRINT));
	// Set permissions so both root and www-data can read/write it
    @chmod(CUSTOMER_CACHE_PATH, 0666);
}

/**
 * Look up a customer by ID, using cache first, then API fallback.
 * Returns a match-compatible array or null if not resolvable.
 */
function getCachedCustomer($customerId, $apiClient) {
    $cache = loadCustomerCache();
    $key = (string)$customerId;
    $now = time();

    // Check cache and TTL
    if (isset($cache[$key]) && ($now - ($cache[$key]['_cached_at'] ?? 0)) < CUSTOMER_CACHE_TTL) {
        return $cache[$key]['data'];
    }

    // Cache miss or expired — fetch from API
    $custData = $apiClient->get('admin/customers/customer/' . $customerId, []);
    if (!$custData || !is_array($custData) || isset($custData['error'])) {
        return null;
    }

    $cStreet = trim($custData['street_1'] ?? '');
    $cCity   = trim($custData['city'] ?? '');
    $cAddr   = trim($cStreet . ($cCity ? ', ' . $cCity : ''));
    $cGps    = $custData['gps'] ?? '';
    $cLat    = 0;
    $cLng    = 0;

    if (!empty($cGps)) {
        $parts = array_map('trim', explode(',', $cGps));
        if (count($parts) === 2) {
            $cLat = (float)$parts[0];
            $cLng = (float)$parts[1];
        }
    }

    $match = [
        'customer_name'             => $custData['name'] ?? 'Unknown',
        'customer_phone'            => $custData['phone'] ?? 'N/A',
        'service_latitude'          => $cLat,
        'service_longitude'         => $cLng,
        'service_address'           => $cAddr ?: 'No address',
        'customer_address_fallback' => $cAddr ?: 'No address',
        'customer_lat_fallback'     => $cLat,
        'customer_lng_fallback'     => $cLng,
    ];

    // Store in cache
    $cache[$key] = ['_cached_at' => $now, 'data' => $match];
    saveCustomerCache($cache);

    return $match;
}

// --- SHARED GEOCODING HELPER ---
$geocodeCache = [];
$geocodeCount = 0;

function geocodeAddress($address, $region = 'nz') {
    global $googleApiKey, $geocodeCache, $geocodeCount;

    if (empty($address) || $address === 'No address') return null;
    if (isset($geocodeCache[$address])) return $geocodeCache[$address];

    // Attempt 1: Nominatim (free, rate-limited to 1/sec)
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $address . ', ' . strtoupper($region),
        'format' => 'json',
        'limit' => 1,
    ]);
    $opts = ['http' => ['header' => "User-Agent: SplynxExporter/1.0\r\n", 'timeout' => 5]];
    $ctx = stream_context_create($opts);
    $json = @file_get_contents($url, false, $ctx);
    if ($json) {
        $results = json_decode($json, true);
        if (!empty($results[0]['lat']) && !empty($results[0]['lon'])) {
            $result = ['lat' => (float)$results[0]['lat'], 'lng' => (float)$results[0]['lon']];
            $geocodeCache[$address] = $result;
            $geocodeCount++;
            usleep(1100000); // Nominatim rate limit: 1 req/sec
            return $result;
        }
    }

    // Attempt 2: Google Geocoding API
    if (!empty($googleApiKey)) {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
            'address' => $address,
            'region' => $region,
            'key' => $googleApiKey,
        ]);
        $json = @file_get_contents($url);
        if ($json) {
            $data = json_decode($json, true);
            if (($data['status'] ?? '') === 'OK' && !empty($data['results'][0]['geometry']['location'])) {
                $loc = $data['results'][0]['geometry']['location'];
                $result = ['lat' => (float)$loc['lat'], 'lng' => (float)$loc['lng']];
                $geocodeCache[$address] = $result;
                $geocodeCount++;
                return $result;
            }
        }
    }

    $geocodeCache[$address] = null;
    return null;
}
