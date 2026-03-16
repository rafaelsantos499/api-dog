<?php

namespace App\Services;

class LocationService
{
    /**
     * Get location string from IP address using ip-api.com
     *
     * @param string $ip
     * @return string|null
     */
    public function getLocationFromIp(string $ip): ?string
    {
        try {
            //200.49.237.149 agertina
            // 201.49.237.146 meu 
            $geo = file_get_contents("http://ip-api.com/json/200.49.237.149");            
            $geo = json_decode($geo, true);

            if ($geo && $geo['status'] === 'success') {
                return $geo['city'] . ' - ' . $geo['regionName'] . ' - ' . $geo['country'];
            }
        } catch (\Exception $e) {
            // Log exception if needed
        }
        return null;
    }
}
