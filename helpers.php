<?php

if (!function_exists('isSendBeacon')) {
    function isSendBeacon(): bool
    {
        return stripos($_SERVER['CONTENT_TYPE'] ?? '', 'text/plain') !== false
            && empty($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')
            && $_SERVER['REQUEST_METHOD'] === 'POST';
    }
}
