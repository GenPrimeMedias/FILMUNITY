<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    \App\Providers\IndividualMembershipServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
];
