<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Fperdomo\PhpAgi\AgiAsteriskManager;

// AMI configuration from environment variables (defaults included)
$config = [
    'server' => getenv('AMI_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('AMI_PORT') ?: 5038),
    'username' => getenv('AMI_USER') ?: 'admin',
    'secret' => getenv('AMI_SECRET') ?: 'supersecret',
];

try {
    $manager = new AgiAsteriskManager(null, $config);

    if (!$manager->connect()) {
        fwrite(STDERR, "Failed to connect to AMI\n");
        exit(1);
    }

    // Send a Ping action to check connectivity
    $response = $manager->send_request('Ping', []);
    print_r($response);

    $manager->disconnect();
} catch (\Throwable $e) {
    fwrite(STDERR, 'AMI Error: '.$e->getMessage()."\n");
    exit(1);
}
