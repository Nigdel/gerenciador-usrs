<?php

namespace App\Providers;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Policies\GestorUserPolicy;
use App\Policies\SubsystemPolicy;
use App\Policies\UserPolicy;
use App\Policies\UserSubsystemAccountPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        GestorUser::class => GestorUserPolicy::class,
        Subsystem::class => SubsystemPolicy::class,
        UserSubsystemAccount::class => UserSubsystemAccountPolicy::class,
        User::class => UserPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
