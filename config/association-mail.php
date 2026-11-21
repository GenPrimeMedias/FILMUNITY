<?php

return [
    // Administrator-managed deployment policy; never editable by association users.
    'smtp_ports' => [465, 587, 2525],
    'private_smtp_cidrs' => [],
    // Exact association UUID => authorized managed sender email addresses, verified out of band.
    'managed_senders' => [],
];
