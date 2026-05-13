<?php
// googleMapsApi.php

/**
 * Renders a Google Map centered on the given coordinates.
 * This function is included and called by migration_form.html.php
 *
 * @param string $apiKey The Google Maps API Key.
 * @param float $lat The latitude coordinate.
 * @param float $lng The longitude coordinate.
 * @return void Renders the necessary HTML and JavaScript directly.
 */
function displayMap(string $apiKey, float $lat, float $lng) {
    // Escape variables for JavaScript injection safety
    $safeLat = htmlspecialchars($lat);
    $safeLng = htmlspecialchars($lng);
    $safeApiKey = htmlspecialchars($apiKey);

    // Render HTML structure for the map container
    echo "<div id=\"map\" style=\"height: 400px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1);\"></div>";

    // Render JavaScript for map initialization
    echo "<script>
        function initMap() {
            const location = { lat: {$safeLat}, lng: {$safeLng} };
            
            const map = new google.maps.Map(document.getElementById(\"map\"), {
                zoom: 15, // High zoom for service location
                center: location,
                // MODIFIED: Set mapTypeId to HYBRID for Satellite view with labels
                mapTypeId: google.maps.MapTypeId.HYBRID 
            });

            new google.maps.Marker({
                position: location,
                map: map,
                title: \"Service Location\"
            });
        }
    </script>";
    
    // Render the Google Maps API script loader
    echo "<script async defer src=\"https://maps.googleapis.com/maps/api/js?key={$safeApiKey}&callback=initMap\"></script>";
}

?>