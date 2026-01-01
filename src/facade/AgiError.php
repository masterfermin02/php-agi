<?php

namespace Fperdomo\PhpAgi\facade;

class AgiError
{
    /**
     * error handler for phpagi.
     *
     * @param  int  $level  PHP error level
     * @param  string  $message  error message
     * @param  string  $file  path to file
     * @param  int  $line  line number of error
     * @param  array|null  $context  variables in the current scope (deprecated in modern PHP)
     */
    public static function handler(
        int $level,
        string $message,
        string $file,
        int $line,
        ?array $context = null
    ): void {
        if (ini_get('error_reporting') == 0) {
            return;
        } // this happens with an @

        @syslog(LOG_WARNING, $file.'['.$line.']: '.$message);

        global $phpagi_error_handler_email;
        if (function_exists('mail') && ! is_null($phpagi_error_handler_email)) { // generate email debugging information
            // decode error level
            $levelName = match ($level) {
                E_WARNING, E_USER_WARNING => 'Warning',
                E_NOTICE, E_USER_NOTICE => 'Notice',
                E_USER_ERROR => 'Error',
                default => (string) $level,
            };

            // build message
            $basefile = basename($file);
            $subject = "$basefile/$line/$levelName: $message";
            $messageBody = "$levelName: $message in $file on line $line\n\n";

            // legacy mysql_* functions removed in modern PHP; prefer mysqli if available
            if (strpos(' '.strtolower($messageBody), 'mysql') && function_exists('mysqli_errno')) {
                // We can't access a mysqli link here, so include a generic note
                $messageBody .= 'MySQL detected in message, mysqli functions available.'."\n\n";
            }

            // figure out who we are
            if (function_exists('socket_create')) {
                $addr = null;
                $port = 80;
                $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
                @socket_connect($socket, '64.0.0.0', $port);
                @socket_getsockname($socket, $addr, $port);
                @socket_close($socket);
                $messageBody .= "\n\nIP Address: $addr\n";
            }

            // include variables
            $messageBody .= "\n\nContext:\n".print_r($context, true);
            $messageBody .= "\n\nGLOBALS:\n".print_r($GLOBALS, true);
            $messageBody .= "\n\nBacktrace:\n".print_r(debug_backtrace(), true);

            // include code fragment
            if (file_exists($file)) {
                $messageBody .= "\n\n$file:\n";
                $code = @file($file);
                for ($i = max(0, $line - 10); $i < min($line + 10, count($code)); $i++) {
                    $messageBody .= ($i + 1)."\t$code[$i]";
                }
            }

            // make sure message is fully readable (convert unprintable chars to hex representation)
            $ret = '';
            for ($i = 0; $i < strlen($messageBody); $i++) {
                $c = ord($messageBody[$i]);
                if (in_array($c, [10, 13, 9])) {
                    $ret .= $messageBody[$i];
                } elseif ($c < 16) {
                    $ret .= '\\x0'.dechex($c);
                } elseif ($c < 32 || $c > 127) {
                    $ret .= '\\x'.dechex($c);
                } else {
                    $ret .= $messageBody[$i];
                }
            }

            // send the mail if less than 5 errors
            static $mailcount = 0;
            if ($mailcount < 5) {
                @mail($phpagi_error_handler_email, $subject, $ret);
            }
            $mailcount++;
        }
    }
}
