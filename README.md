# PHPAGI

PHPAGI is a PHP library that simplifies building Asterisk AGI (Asterisk Gateway Interface), AMI (Asterisk Manager Interface), and FastAGI applications. This repository is a modernized fork of the original welltime/phpagi project and is updated to be compatible with PHP 8.3+ while maintaining a familiar API for easier migration.

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
- Updated for PHP 8.3+ compatibility
- Maintains the original API design to simplify migration
- Extensions: ext-sockets

## Requirements

- PHP 8.3 or higher
- Asterisk PBX (with AGI / FastAGI support)
- Composer for dependency management

## Why this fork?

The original `welltime/phpagi` project is now archived, and many deployments still rely on the classic API.
This package modernizes PHPAGI for PHP 8.3+ while keeping a familiar developer experience to simplify migration.


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

## Production quickstart (AGI)

1) Install:
```bash
composer require fperdomo/php-agi
```
2) Put scripts in Asterisk AGI directory (commonly /var/lib/asterisk/agi-bin/).
3) Make executable and ensure correct owner:

```bash
chmod +x /var/lib/asterisk/agi-bin/hello.php
chown asterisk:asterisk /var/lib/asterisk/agi-bin/hello.php
```
4) In your dialplan:
```bash
 exten => 123,1,NoOp(Hello AGI)
 same => n,AGI(hello.php)
 same => n,Hangup()
```
5) Debug:

- Check Asterisk console: asterisk -rvvvvv
- Log in script using error_log() (stderr) and/or a file logger.

### “FastAGI as a systemd service”

```md
## Running FastAGI with systemd (recommended)

Create `/etc/systemd/system/phpagi-fastagi.service`:

```ini
[Unit]
Description=PHPAGI FastAGI Dispatcher
After=network.target

[Service]
Type=simple
User=asterisk
Group=asterisk
WorkingDirectory=/opt/phpagi
ExecStart=/usr/bin/php /opt/phpagi/fastagi-server.php
Restart=always
RestartSec=2
Environment=APP_ENV=production

[Install]
WantedBy=multi-user.target
```

Enable & start:
```bash
systemctl daemon-reload
systemctl enable phpagi-fastagi
systemctl start phpagi-fastagi
journalctl -u phpagi-fastagi -f
```

Dialplan:

```ini
exten => 123,1,FastAGI(agi://127.0.0.1:4573/your-script)

```

## AMI security notes

- Never expose AMI (5038) publicly.
- Use a dedicated AMI user with minimum permissions.
- Prefer binding to localhost or a private network interface.
- Store credentials in environment variables (or secrets manager).

### FastAGI

FastAGI runs as a server process and handles AGI commands over TCP. Run a FastAGI server and configure Asterisk to call it.

Example FastAGI dispatcher:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Fperdomo\PhpAgi\FastAgiDispatcher;

// Handler scripts live here
$dispatcher = new FastAgiDispatcher(
    baseDir: __DIR__ . '/handlers',
    dropPrivileges: true,
    logVerboseDump: false,
);

$dispatcher->handle();
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
  Agi.php               # Main AGI class
  FastAgiDispatcher.php # FastAGI dispatcher
  AgiAsteriskManager.php# Asterisk Manager (AMI) support
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
