<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Events\Failed;
use App\Listeners\LogFailedLogin;

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
