<?php

$secretsManager = app(\App\Services\AWSSecretsManagerService::class);
$secrets = $secretsManager->getSecret('rabbit');
$_ENV['RABBITMQ_HOST'] = $secrets['RABBITMQ_HOST'];
$_ENV['RABBITMQ_PORT'] = $secrets['RABBITMQ_PORT'];
$_ENV['RABBITMQ_USER'] = $secrets['RABBITMQ_USER'];
$_ENV['RABBITMQ_PASSWORD'] = $secrets['RABBITMQ_PASSWORD'];

return [
    'host' => env('RABBITMQ_HOST'),
    'port' => env('RABBITMQ_PORT'),
    'user' => env('RABBITMQ_USER'),
    'password' => env('RABBITMQ_PASSWORD'),
    'vhost' => env('RABBITMQ_VHOST', '/'),
];
