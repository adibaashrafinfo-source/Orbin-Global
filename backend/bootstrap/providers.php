<?php

use App\Providers\AppServiceProvider;
use App\Providers\DatabaseMacroServiceProvider;
use App\Providers\PermissionServiceProvider;

return [
    AppServiceProvider::class,
    DatabaseMacroServiceProvider::class,
    PermissionServiceProvider::class,
];
