<?php

return [
    'routes' => [
        ['name' => 'p4#get_changelists', 'url' => '/api/changelists', 'verb' => 'GET'],
        ['name' => 'p4#get_settings', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'p4#save_settings', 'url' => '/api/settings', 'verb' => 'POST'],
    ]
];