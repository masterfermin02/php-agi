# PHPAGI

PHPAGI is a PHP library that simplifies building Asterisk AGI (Asterisk Gateway Interface), AMI (Asterisk Manager Interface), and FastAGI applications. This repository is a modernized fork of the original welltime/phpagi project and is updated to be compatible with PHP 8.2+ while maintaining a familiar API for easier migration.

Table of Contents

- Features
- Requirements
- Installation
- Usage
  - AGI script
  - FastAGI
  - Asterisk Manager (AMI)
- Directory layout
- Contributing
- License

## Features

- AGI class for interactive call control
- FastAGI server support
- Asterisk Manager Interface (AMI) integration
- Updated for PHP 8.2+ compatibility
- Maintains the original API design to simplify migration

## Requirements

- PHP 8.2 or higher
- Asterisk PBX (with AGI / FastAGI support)
- Composer for dependency management

## Installation

Install the package via Composer (replace with your vendor if you publish under a different name):

```
composer require fperdomo/php-agi
```


## Usage

Below are minimal examples to get started with AGI, FastAGI, and AMI usage.

### AGI script

AGI scripts are executed by Asterisk from the dialplan. Place executable PHP scripts in the location Asterisk can call.

Example: simple AGI script

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Fperdomo\PhpAgi\Agi;

try {
    $agi = new Agi();
    
    // Answer the call
    $agi->answer();
    
    // Speak text using text-to-speech
    $agi->text2wav("Hello from PHPAGI!");
    
    // Hangup the call
    $agi->hangup();
    
    exit(0);
} catch (\Throwable $e) {
    // Log errors to stderr for debugging
    error_log('AGI Error: ' . $e->getMessage());
    exit(1);
}
```

Make the script executable:

```bash
chmod +x your-script.php
```

In `extensions.conf`:

```ini
exten => 123,1,AGI(your-script.php)
exten => 123,n,Hangup()
```

### FastAGI

FastAGI runs as a server process and handles AGI commands over TCP. Run a FastAGI server and configure Asterisk to call it.

Example FastAGI server:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Fperdomo\PhpAgi\FastAGI;

$host = getenv('FASTAGI_HOST') ?: '0.0.0.0';
$port = (int) (getenv('FASTAGI_PORT') ?: 4573);

try {
    $server = new FastAGI($host, $port);
    echo "FastAGI server listening on {$host}:{$port}\n";
    $server->listen();
} catch (\Throwable $e) {
    error_log('FastAGI Error: ' . $e->getMessage());
    exit(1);
}
```

Configure Asterisk to use FastAGI in `extensions.conf`:

```ini
exten => 123,1,FastAGI(agi://your.server.ip:4573/your-script)
```

### Asterisk Manager (AMI)

Use the AgiAsteriskManager class to connect to Asterisk's management interface to receive events or take actions programmatically.

Example:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Fperdomo\PhpAgi\AgiAsteriskManager;

// Configuration from environment variables or defaults
$config = [
    'server'   => getenv('AMI_HOST') ?: '127.0.0.1',
    'port'     => (int) (getenv('AMI_PORT') ?: 5038),
    'username' => getenv('AMI_USER') ?: 'admin',
    'secret'   => getenv('AMI_SECRET') ?: 'amp111',
];

try {
    $manager = new AgiAsteriskManager(null, $config);
    
    // Connect to Asterisk Manager
    if ($manager->connect()) {
        echo "Connected to Asterisk Manager Interface\n";
        
        // Perform actions (e.g., originate call, query status, etc.)
        // $response = $manager->send_request('Action', ['Action' => 'Ping']);
        
        // Remember to disconnect when done
        // $manager->disconnect();
    }
} catch (\Throwable $e) {
    error_log('AMI Error: ' . $e->getMessage());
    exit(1);
}
```

**Environment Variables:**

For security, use environment variables for sensitive configuration:

```bash
export AMI_HOST="127.0.0.1"
export AMI_PORT="5038"
export AMI_USER="admin"
export AMI_SECRET="your-secret-password"
```

## Directory layout

A suggested layout for this project:

```
src/
  AGI.php               # Main AGI class
  FastAGI.php           # FastAGI server class
  Manager.php           # Asterisk Manager (AMI) support
composer.json
README.md
```

## Contributing

Contributions are welcome. Suggested workflow:

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/fooBar`
3. Commit your changes
4. Push to your fork
5. Open a Pull Request

Testing & CI: Ensure compatibility with PHP 8.2+; GitHub Actions or similar CI is recommended.

## License

This project is released under the GNU Lesser General Public License (LGPL-2.1+), consistent with the original PHPAGI license.
