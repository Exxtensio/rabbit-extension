<?php

namespace Exxtensio\RabbitExtension;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RabbitService::class, function () {
            return new RabbitService();
        });
    }

    public function boot(Filesystem $filesystem): void
    {
        if (!$filesystem->exists(config_path('rabbit-extension.php'))) {
            $filesystem->copy(__DIR__.'/../config/rabbit-extension.php', config_path('rabbit-extension.php'));
        }
    }
}
