<?php

namespace Exxtensio\RabbitExtension;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        require_once __DIR__ . '/../helpers.php';
    }

    public function boot(Filesystem $filesystem): void
    {
        if (!$filesystem->exists(config_path('rabbit-extension.php'))) {
            $filesystem->copy(__DIR__.'/../config/rabbit-extension.php', config_path('rabbit-extension.php'));
        }
    }
}
