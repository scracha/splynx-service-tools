<?php
// render_map.php

/**
 * Endpoint to render a Google Map based on URL parameters (lat, lng).
 * This is designed to be loaded inside an iframe from the main lookup page (index.html)
 * and calls the displayMap function from googleMapsApi.php.
 */

// Includes necessary files
require_once 'config.php';
require_once 'googleMapsApi.php';

// Set headers for basic HTML output
header('Content-Type: text/html');

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : 0.0;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : 0.0;

// Simple validation
if ($lat !== 0.0 && $lng !== 0.0 && !empty($googleApiKey)) {
    // Basic HTML container for the map
    echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Service Location Map</title>
    <style>
        /* Ensure the map fills the iframe */
        body, html { margin: 0; padding: 0; height: 100%; overflow: hidden; }
        /* The displayMap function will generate the #map div */
    </style>
</head>
<body>";
    
    // Call the external PHP function to render the map HTML/JS
    displayMap($googleApiKey, $lat, $lng);

    echo "</body>
</html>";

} else {
    http_response_code(400);
    echo "<div>Map data incomplete or API key missing.</div>";
}
?>
