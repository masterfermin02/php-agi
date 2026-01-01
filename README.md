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

Recommended PSR-4 autoload configuration in `composer.json`:

```
{
  "autoload": {
    "psr-4": {
      "Fperdomo\\PhpAgi\\": "src/"
    }
  }
}
```

After editing `composer.json` run:

```
composer dump-autoload
```

## Usage

Below are minimal examples to get started with AGI, FastAGI, and AMI usage.

### AGI script

AGI scripts are executed by Asterisk from the dialplan. Place executable PHP scripts in the location Asterisk can call.

Example: simple AGI script

```
#!/usr/bin/php -q
<?php
require 'vendor/autoload.php';

use Fperdomo\PhpAgi\AGI;

$agi = new AGI();

// Answer call
$agi->answer();

// Speak text and hangup
$agi->text2wav("Hello from AGI!");
$agi->hangup();
```

In `extensions.conf`:

```
exten=>123,1,AGI(your-script.php)
exten=>123,n,Hangup()
```

### FastAGI

FastAGI runs as a server process and handles AGI commands over TCP. Run a FastAGI server and configure Asterisk to call it.

Example FastAGI server (simplified):

```
<?php
require 'vendor/autoload.php';

use Fperdomo\PhpAgi\FastAGI;

$server = new FastAGI('0.0.0.0', 4573);
$server->listen();
```

Configure Asterisk to use FastAGI in `extensions.conf`:

```
exten=>123,1,FastAGI(agi://your.server.ip/your-script)
```

### Asterisk Manager (AMI)

Use the Manager class to connect to Asterisk's management interface to receive events or take actions programmatically.

Example:

```
<?php
require 'vendor/autoload.php';

use Fperdomo\PhpAgi\Manager;

$manager = new Manager([
    'host'     => '127.0.0.1',
    'username' => 'admin',
    'secret'   => 'amp111',
]);

$manager->connect();
// $manager->originate(...);
```

## Directory layout

A suggested layout for this project:

```
src/
  AGI.php               # Main AGI class
  FastAGI.php           # FastAGI server class
  Manager.php           # Asterisk Manager (AMI) support
docs/
  README.agi.md         # AGI usage and notes
  README.fastagi.md     # FastAGI details
  README.amanager.md    # AMI usage guide
examples/               # Script examples
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
