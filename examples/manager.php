<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Fperdomo\PhpAgi\AgiAsteriskManager;

// Configuration from environment variables or defaults
$config = [
    'server' => getenv('AMI_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('AMI_PORT') ?: 5038),
    'username' => getenv('AMI_USER') ?: 'admin',
    'secret' => getenv('AMI_SECRET') ?: 'supersecret',
];

try {
    $manager = new AgiAsteriskManager(null, $config);

    // Connect to Asterisk Manager
    if ($manager->connect()) {
        echo "Connected to Asterisk Manager Interface\n";

        // Perform actions (e.g., originate call, query status, etc.)
        // $response = $manager->send_request('Action', ['Action' => 'Ping']);
        $response = $manager->Command('core show version');
        print_r($response);

        // Remember to disconnect when done
        $manager->disconnect();
    } else {
        echo "Failed to connect to Asterisk Manager Interface\n";
        exit(1);
    }
} catch (\Throwable $e) {
    error_log('AMI Error: '.$e->getMessage());
    echo 'AMI Error: '.$e->getMessage();
    exit(1);
}
