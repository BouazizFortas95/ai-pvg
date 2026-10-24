<?php
return [
    'provider' => env('YOUTUBE_PUBLISH_PROVIDER', 'youtube'),
    'client_id' => env('YOUTUBE_CLIENT_ID'),
    'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
    'redirect_uri' => env('YOUTUBE_REDIRECT_URI'),
    'access_token' => env('YOUTUBE_ACCESS_TOKEN'),
];
