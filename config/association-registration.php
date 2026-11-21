<?php

return [
    'reviewer_ids' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('FILMUNITY_ASSOCIATION_REVIEWER_IDS', ''))),
        static fn (string $id): bool => $id !== '',
    )),
];
