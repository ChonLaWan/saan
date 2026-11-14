<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

require_once __DIR__.'/../installer/Setup.php';
if (\SchoolAtelier\Installer\Setup::pending(dirname(__DIR__))) {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    header('Location: '.($base === '/' ? '' : $base).'/install/', true, 302);
    exit;
}

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
