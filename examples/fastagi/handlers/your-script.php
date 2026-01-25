<?php

declare(strict_types=1);

// FastAGI handler example.
// Expects $env (array) and $socket (stream) provided by `fastagi/server.php`.
// Uses simple helpers to write/read FastAGI commands/responses over the socket.
if (!isset($socket) || !is_resource($socket)) {
    throw new \RuntimeException('FastAGI handler requires $socket resource provided by server');
}
$w = function (string $cmd) use ($socket) {
    fwrite($socket, $cmd."\r\n");
    fflush($socket);
};
$r = function () use ($socket) {
    return trim(fgets($socket));
};

// Answer the call
$w('ANSWER');
$r();

// Play a file (hello-world must exist in Asterisk sounds)
$w('EXEC Playback hello-world');
$r();

// Set a channel variable
$w('SET VARIABLE fastagi_demo 1');
$r();

// Optionally execute a dialplan application
// $w('EXEC Wait 2');
// $r();

// Hang up
$w('HANGUP');
$r();
