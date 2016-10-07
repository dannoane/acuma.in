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
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\ConnectException;


class FacebookPhotoImport {
    
    protected $event_id;
    protected $location_id;
    protected $city;
    protected $access_token;
    
    
    public function __construct($city, $access_token) {
        
        /* Description: Create a new instance of FacebookPhotoImport.
         * Input: $city - the city from which to import event photos
         *        $access_token - (string) the access token to FacebookGraphAPI generated by FacebookAuth.
         */
        
        $this->city = $city;
        $this->access_token = $access_token;
    }
    
    
    public function getPhotosFromAllEvents() {
        
        $events = $this->getEvents();
        foreach($events as $event) {
            $this->event_id = $event->event_id;
            $this->location_id = $event->location_id;
            
            $this->getPhotos();
        }
    }
    
    
    protected function getEvents() {
        
        $city_id = $this->city->id;
        $time = date('Y-m-d h:i:s', strtotime('-14 days'));
            
        $events = \ORM::for_table('timeline')
            ->table_alias('t')
            ->select('fbe.event_id')
            ->select('fbl.location_id')
            ->join('fb_event', "t.source = 'fb_event' and t.city_id = $city_id and fbe.start_time > '$time' and t.source_id = fbe.id", 'fbe')
            ->join('fb_location', 'fbe.location_id = fbl.id', 'fbl')
            ->find_many();
            
        return $events;
    }
    
    
    private function getPhotos() {
        
        /*  Description: Get photos from the event.
         */
        
        // get photos directly from the event page
        $this->getEventPhotos();
        // get photos from the albums found on the page of the location 
        $this->getLocationPhotos();
    }
    
    
    private function getEventPhotos($from = null) {
        
        /*  Description: Make a GET request for photos.
         *  Input: $from - if null it will be set to the event id so photos will be
         *  take from the page of the event, otherwise they will be taken from
         *  other sources (an album for example).
         */
        
        if (!isset($from)) {
            $from = $this->event_id;
        }
        
        $params = [
            'query' => [
                'access_token' => $this->access_token,
                'fields' => 'images'
            ]
        ];
        
        $client = new Client(['base_uri' => 'https://graph.facebook.com']);
        $retry = false;
        do {
            try {
                $response = $client->request('GET', "/v2.7/$from/photos", $params);
                $retry = false;
            }
            catch (ConnectException $e) {
                $retry = true;
                continue;   // retry request
            }
            
            if ($response->getStatusCode() !== 200) {
                throw new \UnexpectedValueException("Response status code returned:\n$response->getStatusCode()\nResponse body:$response->getBody()");
            }
            
            $photos = json_decode((string) $response->getBody());
            $this->addToDB($this->processPhotos($photos));
            
            // the photos are on multiple pages
            // we are given the url of the next page, if exists
            if (isset($photos->paging->next)) {
                $url = parse_url($photos->paging->next);
                parse_str($url['query']);
                
                $params['query']['after'] = $after;
                $params['query']['limit'] = $limit;
                if (isset($pretty)) {
                    $params['query']['pretty'] = $pretty;
                }
            }
        } while($retry || isset($photos->paging->next));
    }
    
    
    private function processPhotos($photos) {
        
        /*  Description: Process the photos, keep only relevant data.
         *  Input: $photos - (JSON) the photos returned by FacebookGraphAPI.
         */
        
        $processed_photos = [];
        foreach ($photos->data as $photo) {
            
            // keep the link to the image with the biggest resolution
            $processed_photo = [
                'photo_url' => preg_replace('/http:/', 'https:', $photo->images[0]->source) 
            ];
            
            $processed_photos[] = $processed_photo;
        }
        
        return $processed_photos;
    }
    
    
    private function addToDB($processed_photos) {
        
        /*  Description: Save the photos to db after being processed.
         *  Input: $processed_photo - (array) the list of photos.
         */
        
        $event = \ORM::for_table('fb_event')
            ->select('id', 'id')
            ->where('event_id', $this->event_id)
            ->find_one();
        
        $location = \ORM::for_table('fb_location')
            ->select('id', 'id')
            ->where('location_id', $this->location_id)
            ->find_one();
            
        foreach ($processed_photos as $processed_photo) {
            
            $found = \ORM::for_table('fb_photo')
                ->where('photo_url', $processed_photo['photo_url'])
                ->find_one();
                
            if ($found !== False) {
                continue;
            }
            
            $processed_photo['event_id'] = $event->id;
            $processed_photo['location_id'] = $location->id;
        
            $new_photo = \ORM::for_table('fb_photo')
                ->create();
                
            $new_photo->set($processed_photo);
            $new_photo->save();
        }
    }
    
    
    private function getLocationPhotos() {
        
        /* Description: Get photos from the album of the event if we already
         * know its id, or try to find it.
         */
        
        $album = \ORM::for_table('fb_event')
            ->select('album_id', 'id')
            ->where('event_id', $this->event_id)
            ->find_one();
            
        if ($album->id !== NULL) {
            $this->getEventPhotos($album->id);
        }
        else {
            $event = \ORM::for_table('fb_event')
                ->select('name', 'name')
                ->select('start_time', 'start_time')
                ->where('event_id', $this->event_id)
                ->find_one();
                
            $params = [
                'query' => [
                    'access_token' => $this->access_token
                ]
            ];
            
            $client = new Client(['base_uri' => 'https://graph.facebook.com']);
            $retry = false;
            do {
                try {
                    $response = $client->request('GET', "/v2.7/$this->location_id/albums", $params);
                    $retry = false;
                }
                catch (ConnectException $e) {
                    $retry = true;
                    continue;
                }
                
                if ($response->getStatusCode() !== 200) {
                    throw new \UnexpectedValueException("Response status code returned:\n$response->getStatusCode()\nResponse body:$response->getBody()");
                }
            
                $albums = json_decode((string) $response->getBody());
                foreach ($albums->data as $album) {
                    // the albums are stored in chronological order except for a few (Profile Pictures, Timline Photos, etc.)
                    if (strtotime($album->created_time) < strtotime($event->start_time) && !in_array($album->name, ['Profile Pictures', 'Timeline Photos', 'Cover Photos', 'Mobile Uploads'])) {
                        $old_albums = true;
                    }
                    
                    // try to find a match between the album name and the event
                    similar_text(metaphone($album->name), metaphone($event->name), $percentage);
                    if ((!isset($max_percentage)) || ($percentage > $max_percentage)) {
                        $album_id = $album->id;
                        $max_percentage = $percentage;
                    }
                }
                
                // the albums are on multiple pages
                // we are given the url of the next page, if exists
                if (isset($albums->paging->next)) {
                    $url = parse_url($albums->paging->next);
                    parse_str($url['query']);
                
                    $params['query']['after'] = $after;
                    $params['query']['limit'] = $limit;
                    if (isset($pretty)) {
                        $params['query']['pretty'] = $pretty;
                    }
                }
            } while ($retry || (!isset($old_albums) && isset($albums->paging->next)));
            
            // if we find a satisfying match we save the album id
            // and extract the photos from it
            if ($max_percentage > 70) {
                $event = \ORM::for_table('fb_event')
                    ->where('event_id', $this->event_id)
                    ->find_one();
                    
                $event->album_id = $album_id;
                $event->save();
                
                $this->getEventPhotos($album_id);
            }
        }
    }
}