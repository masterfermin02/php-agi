<?php

declare(strict_types=1);

// Minimal classic AGI script: reads AGI environment from STDIN, answers, plays a file, sets a variable and hangs up.
$stdin = fopen('php://stdin', 'r');
$stdout = fopen('php://stdout', 'w');

// Read AGI environment (headers end with a blank line)
$env = [];
while (($line = trim(fgets($stdin))) !== '') {
    if ($line === '') {
        break;
    }
    if (strpos($line, ':') === false) {
        continue;
    }
    [$k, $v] = explode(':', $line, 2);
    $env[trim($k)] = trim($v);
}

// Send AGI commands to Asterisk via STDOUT and read responses from STDIN
fwrite($stdout, "ANSWER\r\n");
fflush($stdout);
$resp = trim(fgets($stdin));

fwrite($stdout, "EXEC Playback hello-world\r\n");
fflush($stdout);
$resp = trim(fgets($stdin));

fwrite($stdout, "SET VARIABLE greeted \"1\"\r\n");
fflush($stdout);
$resp = trim(fgets($stdin));

fwrite($stdout, "HANGUP\r\n");
fflush($stdout);

exit(0);
