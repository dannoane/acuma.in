<?php
/**
 * Copyright 2016 [e-spres-oh]
 * This file is part of Acuma.in
 *
 * Acuma.in is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Acuma.in is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Acuma.in.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace AcumaIn\Import;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use AcumaIn\CoverBoundingBox;


class FacebookLocationImport {
    
    protected $city;
    protected $access_token;
    
    
    public function __construct($city, $access_token) {
        
        /* Description: Create a new instance of FacebookLocationImport.
         * Input: $city - the city from which to import locations
         *        $access_token - (string) the access token to FacebookGraphAPI generated by FacebookAuth.
         */
        
        $this->city = $city;
        $this->access_token = $access_token;
    }
    
    
    public function getAllLocations() {
        
        /* Description: Divide the area of the city in small circles and
         * import locations from each one.
         */
        
        // radius of smaller areas should be smaller than half the size of the
        // radius of the big area for accurate results
        $coverBoundingBox =  new CoverBoundingBox($this->city, 2000);
        foreach ($coverBoundingBox->getAllCircleCenters() as $circle) {
            
            $this->getLocationsFromArea($circle);
        }
    }
    
    
    private function getLocationsFromArea($circle) {
        
        /* Description: Make a GET request for locations in the vicinity of the given coordinate.
         */
        
        $center = $circle->latitude . ',' . $circle->longitude;
        $params = [
            'query' => [
                'q' => '',
                'type' => 'place',
                'center' => $center,
                'distance' => '4000',   // the search radius should be a bit larger than the area's
                'access_token' => $this->access_token
            ]
        ];
        
        $client = new Client(['base_uri' => 'https://graph.facebook.com']);
        $retry = false;
        do {
            try { 
                $response = $client->request('GET', '/v2.7/search', $params);
                $retry = false;
            }
            catch (ConnectException $e) {
                $retry = true;
                continue;
            }
            
            if ($response->getStatusCode() !== 200) {
                throw new \UnexpectedValueException("Response status code returned:\n$response->getStatusCode()\nResponse body:$response->getBody()");
            }
            
            $locations = json_decode((string) $response->getBody());
            $this->addToDB($locations);
            
            // the location are on multiple 'pages'
            // we are given the url of the next page, if exists
            if (isset($locations->paging->next)) {
                $url = parse_url($locations->paging->next);
                parse_str($url['query']);
                
                $params['query']['limit'] = $limit;
                $params['query']['offset'] = $offset;
            }
        }while ($retry || isset($locations->paging->next));
    }
    
    
    private function addToDB($locations) {
        
        /* Description: Add the locations to the db.
         * Input: $location - (JSON) the locations returned by the GET request
         */
        
        foreach ($locations->data as $location) {
            
            $found = \ORM::for_table('fb_location')
                ->where('location_id', $location->id)
                ->find_one();
                
            if ($found !== false) {
                continue;
            }
            
            $new_location = \ORM::for_table('fb_location')
                ->create();
                
            $new_location->location_id = $location->id;
            $new_location->name = $location->name;
            $new_location->latitude = $location->location->latitude;
            $new_location->longitude = $location->location->longitude;
            $new_location->city_id = $this->city->id;
            
            $new_location->save();
        }
    }
}