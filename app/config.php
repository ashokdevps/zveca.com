<?php
/**
 * Application configuration.
 *
 * Production values are below. For local development create app/config.local.php
 * returning an array with the keys you want to override; it is only loaded when
 * the site is served from localhost / CLI, so uploading it by accident is harmless.
 */
$config = [
    'app_name'  => 'Zveca HRMS',
    'timezone'  => 'Asia/Kolkata',
    'debug'     => false,

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'u918639175_hrms',
        'user' => 'u918639175_hrms',
        'pass' => 'Hrms@143_#5798$!!',
    ],

    // Optional Google sign-in. Leave blank to hide the button.
    'google_client_id'     => '',
    'google_client_secret' => '',

    'mail_from' => 'noreply@adorableit.com',

    'session_idle_minutes' => 120,
];

$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
$isLocal = PHP_SAPI === 'cli' || in_array($host, ['localhost', '127.0.0.1', '::1'], true);
if ($isLocal && is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
}

return $config;
