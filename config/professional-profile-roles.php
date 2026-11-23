<?php

$catalog = json_decode(
    file_get_contents(resource_path('js/lib/professional-role-catalog.json')),
    true,
    flags: JSON_THROW_ON_ERROR,
);

return [
    'allowed' => array_merge($catalog['legacy'], ...array_values($catalog['groups'])),
];
