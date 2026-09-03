<?php

declare(strict_types=1);

if (! defined('LARAVEL_VERSION')) {
    define('LARAVEL_VERSION', '12.0.0');
}

if (! defined('Larastan\Larastan\LARAVEL_VERSION')) {
    define('Larastan\Larastan\LARAVEL_VERSION', '12.0.0');
}

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/vendor/larastan/larastan/bootstrap.php';
