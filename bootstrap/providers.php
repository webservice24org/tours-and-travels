<?php

use App\Providers\AppServiceProvider;
use App\Providers\BablysCmsAuthServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    BablysCmsAuthServiceProvider::class,
];
