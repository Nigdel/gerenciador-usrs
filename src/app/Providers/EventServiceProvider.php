<?php

namespace App\Providers;

use App\Listeners\LogFailedLogin;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     */
    protected $listen = [
        Failed::class => [
            LogFailedLogin::class,
        ],
    ];

    public function boot(): void
    {
        //
    }
}
