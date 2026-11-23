<?php

use App\Services\LegacyMembershipTemplate;

require __DIR__.'/../vendor/autoload.php';

echo json_encode(LegacyMembershipTemplate::definition(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
