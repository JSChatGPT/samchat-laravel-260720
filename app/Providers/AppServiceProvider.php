<?php

namespace App\Providers;

use App\Services\MtnSmsService;
use App\Services\SmsGatewayInterface;
use App\Services\ZamtelSmsService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Which SMS gateway OtpService (and anything else that type-hints
        // SmsGatewayInterface) actually uses — swap providers with the
        // SMS_PROVIDER env var alone, no code change needed.
        $this->app->bind(SmsGatewayInterface::class, function ($app) {
            return match (config('services.sms.default')) {
                'zamtel' => $app->make(ZamtelSmsService::class),
                default => $app->make(MtnSmsService::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
