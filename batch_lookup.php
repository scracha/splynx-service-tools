<?php
/**
 * Splynx Batch IP Lookup Client (PHP/HTML Wrapper) */
 
 require_once 'config.php';

// --- FIX: Use 'global' to access the variables defined in config.php ---
// Assuming config.php defines: $googleApiKey, $splynxAdminUrl (and others if used)
global $googleApiKey, $splynxAdminUrl;

// Ensure variables are set, providing a fallback empty string
$googleApiKey = $googleApiKey ?? '';
$splynxAdminUrl = $splynxAdminUrL ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Splynx Batch IP Lookup & Map</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f7f9fb; }
        .card { box-shadow: 0 0 20px rgba(0, 0, 0, 0.05); }
        /* Map now uses 100% of the available width */
        #map { height: 500px; width: 100%; border-radius: 0.5rem; } 
        .table-row-selected {
            background-color: #e6f0ff !important; /* Lighter blue for selected row */
            border-left: 4px solid #3b82f6;
        }
        .table-row:hover {
            background-color: #f3f4f6;
            cursor: pointer;
        }
        /* Status styling */
        .status-blocked { color: #dc2626; font-weight: 600; } /* Red */
        .status-stopped { color: #f59e0b; font-weight: 600; } /* Amber */
        .status-active { color: #10b981; font-weight: 600; } /* Green */

        /* Row background colors */
        .row-blocked { background-color: #fee2e2; } /* Light Red (Red-100) */
        .row-stopped { background-color: #fffbeb; } /* Light Yellow (Yellow-100) */

        /* Responsive table styling adjustments */
        .responsive-table th {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
            white-space: normal; /* Allow headers to wrap */
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .responsive-table td {
             padding-left: 0.5rem;
             padding-right: 0.5rem;
             white-space: nowrap; /* Keep cell content compact */
             overflow: hidden;
             text-overflow: ellipsis;
        }

        @media (max-width: 768px) {
            .hide-on-mobile {
                display: none;
            }
        }
    </style>
</head>
<body class="flex flex-col items-center min-h-screen p-4 sm:p-8">

    <div class="w-full mt-5"> 
        <div class="card bg-white p-6 sm:p-8 rounded-xl border border-gray-200">
            <h1 class="text-3xl font-bold text-gray-800 mb-6 border-b pb-3">Splynx Batch IP Lookup & Map</h1>
            
            <div id="message-container" class="mb-4"></div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="md:col-span-2">
                    <label for="ip-list" class="block text-sm font-medium text-gray-700 mb-2">Paste IPv4 Addresses (One per line or comma-separated)</label>
                    <textarea id="ip-list" rows="6" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-gray-800" placeholder="e.g., 192.168.1.1
10.0.0.5
192.168.1.0/24
172.16.0.1-172.16.0.50"></textarea>
                    
                    <div class="flex flex-col sm:flex-row sm:items-center space-y-2 sm:space-y-0 sm:space-x-6 mt-3">
                        <div class="flex items-center">
                            <input id="include-stopped" type="checkbox" checked class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <label for="include-stopped" class="ml-2 block text-sm text-gray-900">Include Stopped Services</label>
                        </div>
                        <div class="flex items-center">
                            <input id="include-blocked" type="checkbox" checked class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <label for="include-blocked" class="ml-2 block text-sm text-gray-900">Include Blocked Customers</label>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col space-y-3">
                    <button id="lookup-button" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition duration-150 ease-in-out shadow-md">
                        <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline-block hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" id="loading-spinner">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Batch Lookup
                    </button>
                    <button id="message-customers-button" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 rounded-lg transition duration-150 ease-in-out shadow-md" disabled>
                        <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 00-2 2z"></path></svg>
                        Message Customers
                    </button>
                    <button id="download-csv-button" class="w-full bg-green-500 hover:bg-green-600 text-white font-semibold py-3 rounded-lg transition duration-150 ease-in-out shadow-md" disabled>
                        Download CSV
                    </button>
                    <button id="download-kml-button" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white font-semibold py-3 rounded-lg transition duration-150 ease-in-out shadow-md" disabled>
                        Download KML
                    </button>
                    <button id="share-link-button" class="w-full bg-gray-500 hover:bg-gray-600 text-white font-semibold py-3 rounded-lg transition duration-150 ease-in-out shadow-md" disabled>
                        Copy Share Link
                    </button>
                    <p class="text-xs text-gray-500 text-center mt-2">Found <span id="ip-count" class="font-bold">0</span> IPs to process.</p>
                </div>
            </div>

            <div id="map-section" class="mt-8">
                <h2 class="text-2xl font-semibold text-gray-700 mb-4">Service Map View</h2>
                <div id="map"></div>
            </div>

            <div id="results-section" class="mt-8">
                <h2 class="text-2xl font-semibold text-gray-700 mb-4">Lookup Results (<span id="results-count">0</span> found)</h2>

                <div class="overflow-x-auto rounded-lg border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 responsive-table">
                        <thead id="results-table-head" class="bg-gray-50">
                            </thead>
                        <tbody id="results-table-body" class="bg-white divide-y divide-gray-200">
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <script>
        /**
         * Client-side JavaScript logic for batch lookups and map integration.
         */
        
        // PHP securely injects the Google API Key, API URL, and Splynx Admin URL
        const GOOGLE_API_KEY = "<?php echo htmlspecialchars($googleApiKey); ?>";
        const API_URL = 'api.php'; 
        const SPLYX_ADMIN_URL = "<?php echo htmlspecialchars($splynxAdminUrl); ?>"; // New Admin URL
        
        // --- State management ---
        let lookupResults = []; // Stores the successful lookup data (AFTER filtering)
        let map;
        let markers = [];
        let infoWindow;
        let kmlBlobUrl = null; 

        // --- DOM elements ---
        const ipListTextarea = document.getElementById('ip-list');
        const lookupButton = document.getElementById('lookup-button');
        const messageCustomersButton = document.getElementById('message-customers-button');
        const downloadCsvButton = document.getElementById('download-csv-button');
        const downloadKmlButton = document.getElementById('download-kml-button');
        const shareLinkButton = document.getElementById('share-link-button');
        const resultsTableHead = document.getElementById('results-table-head');
        const resultsTableBody = document.getElementById('results-table-body');
        const resultsCountSpan = document.getElementById('results-count');
        const messageContainer = document.getElementById('message-container');
        const ipCountSpan = document.getElementById('ip-count');
        const loadingSpinner = document.getElementById('loading-spinner');
        const includeStoppedCheckbox = document.getElementById('include-stopped');
        const includeBlockedCheckbox = document.getElementById('include-blocked');
        
        // --- Column Configuration (Master list for table and export) ---
        const COLUMNS_CONFIG = [
            // Core Data (Reordered)
            { key: 'service_ipv4', label: 'IP', isVisible: true, isMobileHidden: false },
            { key: 'service_id', label: 'Service ID', isVisible: true, isMobileHidden: false },
            { key: 'customer_name', label: 'Customer', isVisible: true, isMobileHidden: false },
            { key: 'service_address', label: 'Address', isVisible: true, isMobileHidden: false },
            { key: 'service_description', label: 'Service Description', isVisible: true, isMobileHidden: false },
            // Contact Info
            { key: 'customer_phone', label: 'Phone', isVisible: true, isMobileHidden: true },
            { key: 'customer_email', label: 'Email', isVisible: true, isMobileHidden: true },
            // Status (Conditional & Reordered)
            { key: 'customer_status', label: 'Customer Status', isVisible: false, isConditional: 'blocked', isMobileHidden: false }, 
            { key: 'service_status', label: 'Service Status', isVisible: false, isConditional: 'stopped', isMobileHidden: false }, 
            // Contact 2 (Renamed)
            { key: 'contact_2_name', label: 'Contact 2', isVisible: true, isMobileHidden: true },
            { key: 'contact_2_phone', label: 'Phone 2', isVisible: true, isMobileHidden: true },            
            // Map Data (Split and visible)
            { key: 'service_latitude', label: 'Latitude', isVisible: true, isMobileHidden: true },
            { key: 'service_longitude', label: 'Longitude', isVisible: true, isMobileHidden: true },
        ];
        
        /**
         * Filters the master column list based on checkbox states.
         */
        const getVisibleColumns = (forCsv = false) => {
            const isBlockedChecked = includeBlockedCheckbox.checked;
            const isStoppedChecked = includeStoppedCheckbox.checked;

            return COLUMNS_CONFIG.filter(col => {
                // Conditional columns: only show if the filter is active
                if (col.isConditional === 'blocked' && !isBlockedChecked) return false;
                if (col.isConditional === 'stopped' && !isStoppedChecked) return false;
                
                // All other columns are always visible in the table and CSV (based on the new config)
                return true;
            });
        };

        // --- Utility Functions ---

        /**
         * Displays a temporary message in the message container.
         */
        const displayMessage = (message, type = 'info', isFatal = false) => {
            let color = { info: 'bg-blue-100 text-blue-800', error: 'bg-red-100 text-red-800', success: 'bg-green-100 text-green-800' }[type];
            messageContainer.innerHTML = `
                <div class="p-3 rounded-lg font-medium ${color} ${isFatal ? 'shadow-xl' : ''}">
                    ${message}
                </div>
            `;
            if (!isFatal && type !== 'info') {
                setTimeout(() => messageContainer.innerHTML = '', 7000);
            }
        };

        /**
         * Clears all markers from the map.
         */
        const clearMarkers = () => {
            markers.forEach(marker => marker.setMap(null));
            markers = [];
        };

        /**
         * Determines the appropriate marker icon based on service/customer status.
         * 1: Red (Blocked), 3: Yellow/Orange (Stopped), 4: Green (Active/Other)
         */
        const getMarkerIcon = (result) => {
            const customerStatus = String(result.customer_status).toLowerCase();
            const serviceStatus = String(result.service_status).toLowerCase();

            if (customerStatus === 'blocked') {
                return 'http://maps.google.com/mapfiles/ms/icons/red-dot.png'; 
            }
            if (serviceStatus === 'stopped') {
                return 'http://maps.google.com/mapfiles/ms/icons/yellow-dot.png';
            }
            return 'http://maps.google.com/mapfiles/ms/icons/green-dot.png';
        };
        
        // --- Map Initialization ---

        /**
         * Google Maps API callback. Initializes the map.
         */
        window.initMap = function() {
            if (!GOOGLE_API_KEY || GOOGLE_API_KEY === '') {
                return;
            }

            const defaultLocation = { lat: -41.28889, lng: 174.77722 };
            
            map = new google.maps.Map(document.getElementById('map'), {
                zoom: 7,
                center: defaultLocation,
                mapTypeId: google.maps.MapTypeId.ROADMAP
            });

            infoWindow = new google.maps.InfoWindow();
            displayMessage('Map Initialized. Ready for IP lookups.', 'success');
            
            checkAndAutoLoadIps();
        };

        /**
         * Places markers on the map based on the lookup results.
         */
        const plotResultsOnMap = (results) => {
            if (!map) return;

            clearMarkers();
            
            const bounds = new google.maps.LatLngBounds();
            let plotCount = 0;

            results.forEach((result, index) => {
                const lat = parseFloat(result.service_latitude);
                const lng = parseFloat(result.service_longitude);

                if (!isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
                    plotCount++;
                    const location = { lat: lat, lng: lng };
                    bounds.extend(location);

                    const iconUrl = getMarkerIcon(result);
                    
                    const contentString = `
                        <div class="text-sm">
                            <h4 class="font-bold text-base mb-1">${result.customer_name}</h4>
                            <p><strong>IP:</strong> <a href="https://${result.service_ipv4}:8443" target="_blank" class="text-blue-600">${result.service_ipv4}</a></p>
                            <p><strong>Customer Status:</strong> ${result.customer_status || 'N/A'}</p>
                            <p><strong>Service Status:</strong> ${result.service_status || 'N/A'}</p>
                            <p><strong>Address:</strong> ${result.service_address}</p>
                            <p><strong>Service ID:</strong> ${result.service_id}</p>
                            <p><strong>Description:</strong> ${result.service_description || 'N/A'}</p>
                        </div>
                    `;

                    const marker = new google.maps.Marker({
                        position: location,
                        map: map,
                        title: result.customer_name,
                        icon: iconUrl,
                        dataIndex: index 
                    });

                    marker.addListener('click', () => {
                        infoWindow.setContent(contentString);
                        infoWindow.open(map, marker);
                        highlightTableRow(index);
                    });

                    markers.push(marker);
                }
            });

            if (plotCount > 0) {
                map.fitBounds(bounds);
                displayMessage(`Successfully plotted ${plotCount} services on the map.`, 'success');
            } else {
                 displayMessage('No geocoded data available to plot on the map.', 'info');
                 map.setCenter({ lat: -41.28889, lng: 174.77722 });
                 map.setZoom(7);
            }
        };

        /**
         * Highlights a row in the table and scrolls to it.
         */
        const highlightTableRow = (index) => {
            document.querySelectorAll('#results-table-body tr').forEach(row => {
                // Keep the row background color, only remove the blue highlight and border
                row.classList.remove('table-row-selected'); 
            });

            const selectedRow = document.getElementById(`row-${index}`);
            if (selectedRow) {
                selectedRow.classList.add('table-row-selected');
                selectedRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        };

        /**
         * Renders the results in the HTML table.
         */
        const renderTable = (results) => {
            const visibleColumns = getVisibleColumns(false); // Get columns for HTML table
            
            resultsCountSpan.textContent = results.length;
            resultsTableHead.innerHTML = '';
            resultsTableBody.innerHTML = ''; 

            if (results.length === 0) {
                 resultsTableBody.innerHTML = '<tr><td colspan="12" class="text-center py-4 text-gray-500">No services found matching the criteria.</td></tr>';
                 return;
            }

            // 1. Create Header Row
            const headerRow = document.createElement('tr');
            headerRow.innerHTML = visibleColumns.map(col => {
                const mobileClass = col.isMobileHidden ? 'hide-on-mobile' : '';
                return `<th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider ${mobileClass}">${col.label}</th>`;
            }).join('');
            resultsTableHead.appendChild(headerRow);
            
            // 2. Create Body Rows
            results.forEach((result, index) => {
                
                let rowClass = 'table-row';
                const customerStatus = String(result.customer_status || '').toLowerCase();
                const serviceStatus = String(result.service_status || '').toLowerCase();
                
                // Blocked row color takes precedence over stopped
                if (customerStatus === 'blocked') {
                    rowClass += ' row-blocked';
                } else if (serviceStatus === 'stopped') {
                    rowClass += ' row-stopped';
                }

                const row = document.createElement('tr');
                row.id = `row-${index}`;
                row.className = rowClass;
                row.onclick = () => {
                    const marker = markers.find(m => m.dataIndex === index);
                    if (marker && map) {
                        map.panTo(marker.getPosition());
                        google.maps.event.trigger(marker, 'click');
                    }
                };

                row.innerHTML = visibleColumns.map(col => {
                    let cellValue = result[col.key] || 'N/A';
                    let cellClass = '';
                    const mobileClass = col.isMobileHidden ? 'hide-on-mobile' : '';
                    let cellContent = cellValue; // Holds the final HTML/text content

                    // Handle latitude/longitude to show 4 decimal places if present
                    if (col.key === 'service_latitude' || col.key === 'service_longitude') {
                        const val = parseFloat(result[col.key]);
                        cellValue = isNaN(val) || val === 0 ? 'N/A' : val.toFixed(4);
                        cellContent = cellValue;
                    }
                    // Handle status fields (status text coloring)
                    else if (col.key === 'customer_status' || col.key === 'service_status') {
                        const status = String(cellValue).toLowerCase();
                        if (status === 'blocked') cellClass = 'status-blocked';
                        else if (status === 'stopped') cellClass = 'status-stopped';
                        else if (status === 'active') cellClass = 'status-active';
                        cellContent = cellValue;
                    }
                    // Handle IP hyperlink
                    else if (col.key === 'service_ipv4' && cellValue !== 'N/A') {
                        cellContent = `<a href="https://${cellValue}:8443" target="_blank" class="text-blue-600 hover:text-blue-800 font-medium">${cellValue}</a>`;
                    }
                    // Handle Customer Name hyperlink (Requires SPLYX_ADMIN_URL and result.customer_id)
					else if (col.key === 'customer_name' && cellValue !== 'N/A' && result.customer_id && SPLYX_ADMIN_URL) {
						cellContent = `<a href="${SPLYX_ADMIN_URL}/admin/customers/view?id=${result.customer_id}" target="_blank" class="text-blue-600 hover:text-blue-800 font-medium">${cellValue}</a>`;
					}else {
                        cellContent = cellValue;
                    }


                    return `<td class="px-3 py-3 text-sm text-gray-900 ${mobileClass}">
                                <span class="${cellClass}">${cellContent}</span>
                            </td>`;
                }).join('');

                resultsTableBody.appendChild(row);
            });
        };


        // --- Core Lookup Logic (maintained) ---

        /**
         * Filters the raw results based on the current checkbox states.
         */
        const filterResults = (rawResults) => {
            const includeStopped = includeStoppedCheckbox.checked;
            const includeBlocked = includeBlockedCheckbox.checked;

            return rawResults.filter(result => {
                const customerStatus = String(result.customer_status || '').toLowerCase();
                const serviceStatus = String(result.service_status || '').toLowerCase();

                // 1. Always include 'active' services
                if (customerStatus !== 'blocked' && serviceStatus !== 'stopped') {
                    return true;
                }
                
                // 2. Include 'blocked' customers if the checkbox is checked
                if (includeBlocked && customerStatus === 'blocked') {
                    return true;
                }

                // 3. Include 'stopped' services if the checkbox is checked, provided the customer isn't 'blocked'
                if (includeStopped && serviceStatus === 'stopped' && customerStatus !== 'blocked') {
                     return true;
                }

                return false;
            });
        };

        /**
         * Converts an IP address to a long integer.
         */
        const ip2long = (ip) => {
            const parts = ip.split('.');
            return (parseInt(parts[0]) * 16777216) + 
                   (parseInt(parts[1]) * 65536) + 
                   (parseInt(parts[2]) * 256) + 
                   parseInt(parts[3]);
        };

        /**
         * Converts a long integer to an IP address.
         */
        const long2ip = (long) => {
            return [
                (long >>> 24) & 0xFF,
                (long >>> 16) & 0xFF,
                (long >>> 8) & 0xFF,
                long & 0xFF
            ].join('.');
        };

        /**
         * Expands CIDR notation to individual IP addresses.
         */
        const expandCIDR = (cidr) => {
            const [ip, bits] = cidr.split('/');
            const mask = -1 << (32 - parseInt(bits));
            const base = ip2long(ip) & mask;
            const broadcast = base | ~mask;
            
            const ips = [];
            // Skip network and broadcast addresses for /31 and smaller
            const start = parseInt(bits) < 31 ? base + 1 : base;
            const end = parseInt(bits) < 31 ? broadcast - 1 : broadcast;
            
            for (let i = start; i <= end; i++) {
                ips.push(long2ip(i >>> 0));
            }
            return ips;
        };

        /**
         * Expands IP range (e.g., "192.168.1.1-192.168.1.10") to individual IPs.
         */
        const expandRange = (range) => {
            const [startIp, endIp] = range.split('-').map(ip => ip.trim());
            const start = ip2long(startIp);
            const end = ip2long(endIp);
            
            if (start > end) {
                return []; // Invalid range
            }
            
            const ips = [];
            for (let i = start; i <= end; i++) {
                ips.push(long2ip(i >>> 0));
            }
            return ips;
        };

        /**
         * Sanitizes IP list and initiates the batch lookup process.
         * Supports individual IPs, CIDR notation, and IP ranges.
         */
        const processIps = async () => {
            const rawInput = ipListTextarea.value.trim().replace(/,/g, '\n');
            
            const lines = rawInput.split('\n').map(line => line.trim()).filter(line => line !== '');
            
            let allIps = [];
            
            for (const line of lines) {
                // Check if it's CIDR notation (e.g., 192.168.1.0/24)
                if (line.includes('/')) {
                    const cidrMatch = line.match(/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/(\d{1,2})$/);
                    if (cidrMatch) {
                        const [, ip, bits] = cidrMatch;
                        if (parseInt(bits) >= 0 && parseInt(bits) <= 32) {
                            const expandedIps = expandCIDR(line);
                            allIps.push(...expandedIps);
                            continue;
                        }
                    }
                }
                
                // Check if it's an IP range (e.g., 192.168.1.1-192.168.1.10)
                if (line.includes('-')) {
                    const rangeMatch = line.match(/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\s*-\s*(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/);
                    if (rangeMatch) {
                        const expandedIps = expandRange(line);
                        if (expandedIps.length > 0) {
                            allIps.push(...expandedIps);
                            continue;
                        }
                    }
                }
                
                // Check if it's a single valid IP
                if (line.match(/^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/)) {
                    allIps.push(line);
                }
            }
            
            // Remove duplicates and validate IPs
            const ipList = [...new Set(allIps)].filter(ip => {
                const parts = ip.split('.');
                return parts.length === 4 && parts.every(part => {
                    const num = parseInt(part);
                    return num >= 0 && num <= 255;
                });
            });

            if (ipList.length === 0) {
                displayMessage('Please enter valid IPv4 addresses, CIDR notation (e.g., 192.168.1.0/24), or IP ranges (e.g., 192.168.1.1-192.168.1.10).', 'error');
                updateUrlWithIps([]);
                return;
            }
            
            // Warn if processing a large number of IPs
            if (ipList.length > 1000) {
                const confirmed = confirm(`You are about to process ${ipList.length} IP addresses. This may take some time. Continue?`);
                if (!confirmed) return;
            }
            
            // UI state updates
            lookupButton.disabled = true;
            loadingSpinner.classList.remove('hidden');
            lookupButton.textContent = 'Processing...';
            messageCustomersButton.disabled = true;
            downloadCsvButton.disabled = true;
            downloadKmlButton.disabled = true;
            shareLinkButton.disabled = true;
            resultsTableHead.innerHTML = '';
            resultsTableBody.innerHTML = '<tr><td colspan="12" class="text-center py-4 text-gray-500">Starting batch lookup...</td></tr>'; 
            clearMarkers();
            kmlBlobUrl = null; 
            
            // 1. Fetch ALL data (regardless of status, filtering happens client-side)
            const lookups = ipList.map(ip => lookupService(ip));
            const rawLookupResults = (await Promise.all(lookups)).filter(result => result && result.service_ipv4);
            
            // 2. Apply client-side filtering based on checkboxes
            lookupResults = filterResults(rawLookupResults);

            // Final UI updates
            lookupButton.disabled = false;
            loadingSpinner.classList.add('hidden');
            lookupButton.textContent = 'Batch Lookup';
            
            ipCountSpan.textContent = ipList.length;

            if (lookupResults.length > 0) {
                renderTable(lookupResults);
                plotResultsOnMap(lookupResults);
                messageCustomersButton.disabled = false;
                downloadCsvButton.disabled = false;
                
                const hasGeocoded = lookupResults.some(r => r.service_latitude && parseFloat(r.service_latitude) !== 0);
                downloadKmlButton.disabled = !hasGeocoded;

                shareLinkButton.disabled = false;
                
                updateUrlWithIps(ipList);

            } else {
                renderTable(lookupResults); // Renders empty table with correct message
                displayMessage(`Batch lookup complete. Zero services found matching your filter settings.`, 'error');
                updateUrlWithIps([]); 
            }
        };

        /**
         * Updates the browser URL with the current list of IPs and filter states.
         */
        const updateUrlWithIps = (ips) => {
            const currentUrl = new URL(window.location.href);
            
            const includeStopped = includeStoppedCheckbox.checked ? '1' : '0';
            const includeBlocked = includeBlockedCheckbox.checked ? '1' : '0';

            if (ips.length > 0) {
                const ipString = ips.join(',');
                currentUrl.searchParams.set('ips', ipString);
                currentUrl.searchParams.set('s', includeStopped);
                currentUrl.searchParams.set('b', includeBlocked);
            } else {
                currentUrl.searchParams.delete('ips');
                currentUrl.searchParams.delete('s');
                currentUrl.searchParams.delete('b');
            }
            history.pushState(null, '', currentUrl.toString());
        };


        /**
         * Checks the URL for IPs and automatically triggers the lookup.
         */
        const checkAndAutoLoadIps = () => {
            const urlParams = new URLSearchParams(window.location.search);
            const ipString = urlParams.get('ips');
            
            const stoppedParam = urlParams.get('s');
            const blockedParam = urlParams.get('b');
            
            if (stoppedParam !== null) {
                includeStoppedCheckbox.checked = stoppedParam === '1';
            }
            if (blockedParam !== null) {
                includeBlockedCheckbox.checked = blockedParam === '1';
            }

            if (ipString) {
                const ips = ipString.split(',')
                    .map(ip => ip.trim())
                    .filter(ip => ip.match(/^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/));
                
                if (ips.length > 0) {
                    ipListTextarea.value = ips.join('\n');
                    ipCountSpan.textContent = ips.length;
                    displayMessage(`Loading ${ips.length} IPs and filters from the URL...`, 'info');
                    processIps();
                }
            }
        };

        /**
         * Copies the current page URL to the clipboard.
         */
        const copyShareLink = () => {
            const shareUrl = window.location.href;

            if (navigator.clipboard) {
                navigator.clipboard.writeText(shareUrl).then(() => {
                    displayMessage('Share link copied to clipboard!', 'success');
                }).catch(err => {
                    console.error('Could not copy text: ', err);
                    displayMessage('Could not copy link. Try selecting the URL bar manually.', 'error');
                });
            } else {
                const tempInput = document.createElement('input');
                tempInput.value = shareUrl;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);
                displayMessage('Share link copied to clipboard (fallback)!', 'success');
            }
        };

        /**
         * Fetches service details for a single IP from the internal API.
         */
        const lookupService = async (ipv4) => {
            try {
                const response = await fetch(`${API_URL}?ipv4=${ipv4}`);
                const data = await response.json();

                if (response.ok && data.service_ipv4) {
                    return data;
                } else {
                    console.warn(`[Lookup Failed] IP: ${ipv4}, Error: ${data.error || 'Unknown error'}`);
                    return { service_ipv4: ipv4, error: 'Not Found' };
                }
            } catch (error) {
                console.error(`[Fetch Error] IP: ${ipv4}, Error:`, error);
                return { service_ipv4: ipv4, error: 'API Unreachable' };
            }
        };

        // --- Export Functions (maintained) ---

        /**
         * Generates the KML content and returns the Blob URL.
         */
        const generateKmlBlobUrl = () => {
            if (kmlBlobUrl) return kmlBlobUrl; 

            const geocodedResults = lookupResults.filter(result => 
                result.service_latitude && parseFloat(result.service_latitude) !== 0 && result.service_longitude && parseFloat(result.service_longitude) !== 0
            );

            if (geocodedResults.length === 0) {
                displayMessage('No geocoded data available to create a KML file.', 'error');
                return null;
            }

            const placemarks = geocodedResults.map(result => {
                
                let styleUrl = '#activeStyle';
                if (String(result.customer_status).toLowerCase() === 'blocked') {
                    styleUrl = '#blockedStyle';
                } else if (String(result.service_status).toLowerCase() === 'stopped') {
                    styleUrl = '#stoppedStyle';
                }

                const safeCustomerName = (result.customer_name || 'Unknown Customer').replace(/&/g, 'and');

                const description = `
                    <p><b>Service ID:</b> ${result.service_id}</p>
                    <p><b>IP:</b> ${result.service_ipv4}</p>
                    <p><b>Customer:</b> ${result.customer_name}</p>
                    <p><b>Customer Status:</b> ${result.customer_status}</p> 
                    <p><b>Service Status:</b> ${result.service_status}</p> 
                    <p><b>Address:</b> ${result.service_address}</p>
                    <p><b>Description:</b> ${result.service_description || 'N/A'}</p>
                `;
                
                return `
<Placemark>
    <name>${safeCustomerName} (${result.service_ipv4})</name>
    <description><![CDATA[${description}]]></description>
    <styleUrl>${styleUrl}</styleUrl>
    <Point>
        <coordinates>${result.service_longitude},${result.service_latitude},0</coordinates>
    </Point>
</Placemark>
                `.trim();
            }).join('\n');


            const kmlContent = `
<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
  <Document>
    <name>Splynx Service Locations Export</name>
    <Style id="blockedStyle">
      <IconStyle>
        <scale>1.2</scale>
        <Icon><href>http://maps.google.com/mapfiles/ms/icons/red-dot.png</href></Icon>
      </IconStyle>
    </Style>
    <Style id="stoppedStyle">
      <IconStyle>
        <scale>1.2</scale>
        <Icon><href>http://maps.google.com/mapfiles/ms/icons/yellow-dot.png</href></Icon>
      </IconStyle>
    </Style>
    <Style id="activeStyle">
      <IconStyle>
        <scale>1.2</scale>
        <Icon><href>http://maps.google.com/mapfiles/ms/icons/green-dot.png</href></Icon>
      </IconStyle>
    </Style>
    ${placemarks}
  </Document>
</kml>
            `.trim();

            const blob = new Blob([kmlContent], { type: 'application/vnd.google-earth.kml+xml;charset=utf-8;' });
            kmlBlobUrl = URL.createObjectURL(blob);
            return kmlBlobUrl;
        };

        const downloadCsv = () => {
            if (lookupResults.length === 0) {
                displayMessage('No data to export.', 'error');
                return;
            }
            
            // Get columns for CSV (uses the filters to include conditional columns)
            const exportColumns = getVisibleColumns(true); 

            // Header row generation
            const headers = exportColumns.map(col => `"${col.label.replace(/"/g, '""')}"`).join(',');
            const csvRows = [headers];

            // Data rows generation
            lookupResults.forEach(result => {
                const row = exportColumns.map(col => {
                    let value = result[col.key] || '';
                    
                    // Fields to be quoted and internal quotes escaped
                    if (col.key !== 'service_latitude' && col.key !== 'service_longitude') {
                         value = `"${String(value).replace(/"/g, '""')}"`;
                    }
                    // latitude/longitude are exported as plain numbers (or empty string)
                    
                    return value;
                });
                csvRows.push(row.join(','));
            });
            
            const csvContent = csvRows.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.setAttribute('href', url);
            link.setAttribute('download', 'splynx_batch_lookup.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        };

        const downloadKml = () => {
            const url = generateKmlBlobUrl();
            if (!url) return;
            const link = document.createElement('a');
            link.setAttribute('href', url);
            link.setAttribute('download', 'splynx_map_export.kml');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        };

        /**
         * Opens the message customers page with the current filtered customer data.
         */
        const handleMessageCustomers = () => {
            if (lookupResults.length === 0) return;

            // Filter out results with null or N/A service IDs
            const validResults = lookupResults.filter(r => {
                const serviceId = String(r.service_id || '').toLowerCase();
                return serviceId !== '' && serviceId !== 'n/a' && serviceId !== 'null';
            });

            if (validResults.length === 0) {
                displayMessage('No valid services found in the current results.', 'error');
                return;
            }

            // Check if there are any non-active customers or services (only among valid results)
            const hasNonActive = validResults.some(r => {
                const customerStatus = String(r.customer_status || '').toLowerCase();
                const serviceStatus = String(r.service_status || '').toLowerCase();
                return customerStatus !== 'active' || serviceStatus !== 'active';
            });

            // Show warning if non-active customers/services are present
            if (hasNonActive) {
                const confirmed = confirm(
                    'WARNING: You are about to message customers with non-active status or services.\n\n' +
                    'This may include blocked customers or stopped services.\n\n' +
                    'Click OK to CONTINUE or Cancel to ABORT.'
                );
                
                if (!confirmed) {
                    return; // User clicked Cancel/Abort
                }
            }

            // Prepare customer data with all necessary fields (from valid results only)
            const customersData = validResults.map(r => ({
                customer_id: r.customer_id,
                customer_name: r.customer_name,
                customer_phone: r.customer_phone,
                customer_email: r.customer_email,
                contact_2_name: r.contact_2_name,
                contact_2_phone: r.contact_2_phone
            }));

            // Remove duplicates based on customer_id
            const uniqueCustomers = [];
            const seenIds = new Set();
            customersData.forEach(customer => {
                if (customer.customer_id && !seenIds.has(customer.customer_id)) {
                    seenIds.add(customer.customer_id);
                    uniqueCustomers.push(customer);
                }
            });

            if (uniqueCustomers.length === 0) {
                displayMessage('No valid customer data found in the current results.', 'error');
                return;
            }

            // Create a temporary form to POST data to the new tab
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'message_customers.php';
            form.target = '_blank';

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'customers_data';
            input.value = JSON.stringify(uniqueCustomers);
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        };

        // --- Event Listeners and Initialization ---
        document.addEventListener('DOMContentLoaded', () => {
            lookupButton.addEventListener('click', processIps);
            messageCustomersButton.addEventListener('click', handleMessageCustomers);
            downloadCsvButton.addEventListener('click', downloadCsv);
            downloadKmlButton.addEventListener('click', downloadKml);
            shareLinkButton.addEventListener('click', copyShareLink); 
            
            // Re-run lookup and rendering when filters change
            includeStoppedCheckbox.addEventListener('change', processIps);
            includeBlockedCheckbox.addEventListener('change', processIps);
            
            // Update IP count dynamically
            ipListTextarea.addEventListener('input', () => {
                const rawInput = ipListTextarea.value.trim().replace(/,/g, '\n');
                const lines = rawInput.split('\n').map(line => line.trim()).filter(line => line !== '');
                
                let estimatedCount = 0;
                for (const line of lines) {
                    // CIDR notation
                    if (line.includes('/')) {
                        const cidrMatch = line.match(/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/(\d{1,2})$/);
                        if (cidrMatch) {
                            const bits = parseInt(cidrMatch[2]);
                            if (bits >= 0 && bits <= 32) {
                                const hostCount = Math.pow(2, 32 - bits);
                                // Subtract network and broadcast for /31 and smaller
                                estimatedCount += bits < 31 ? hostCount - 2 : hostCount;
                            }
                        }
                    }
                    // IP range
                    else if (line.includes('-')) {
                        const rangeMatch = line.match(/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\s*-\s*(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/);
                        if (rangeMatch) {
                            const [, startIp, endIp] = rangeMatch;
                            const start = ip2long(startIp);
                            const end = ip2long(endIp);
                            if (start <= end) {
                                estimatedCount += (end - start + 1);
                            }
                        }
                    }
                    // Single IP
                    else if (line.match(/^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/)) {
                        estimatedCount++;
                    }
                }
                
                ipCountSpan.textContent = estimatedCount;
            });

            // --- Load Google Maps API ---\
            if (GOOGLE_API_KEY && GOOGLE_API_KEY !== '') {
                const script = document.createElement('script');
                script.src = `https://maps.googleapis.com/maps/api/js?key=${GOOGLE_API_KEY}&callback=initMap`;
                script.async = true;
                document.head.appendChild(script);
            } else {
                displayMessage('FATAL ERROR: Google API Key is missing. Check config.php.', 'error', true);
                document.getElementById('map').innerHTML = '<p class="text-center text-red-500 py-20">Map disabled: Google API Key missing or invalid.</p>';
                checkAndAutoLoadIps(); 
            }
        });
    </script>
</body>
</html>