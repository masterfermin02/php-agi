<?php

declare(strict_types=1);

namespace Fperdomo\PhpAgi;

/**
 * Central constants for the PHPAGI library.
 */
final class Constants
{
    public const string AST_CONFIG_DIR = '/etc/asterisk/';

    public const string AST_SPOOL_DIR = '/var/spool/asterisk/';

    public const string AST_TMP_DIR = self::AST_SPOOL_DIR.'/tmp/';

    public const string DEFAULT_PHPAGI_CONFIG = self::AST_CONFIG_DIR.'/phpagi.conf';

    public const string AST_DIGIT_ANY = '0123456789#*';

    public const int AGIRES_OK = 200;

    public const int AST_STATE_DOWN = 0;

    public const int AST_STATE_RESERVED = 1;

    public const int AST_STATE_OFFHOOK = 2;

    public const int AST_STATE_DIALING = 3;

    public const int AST_STATE_RING = 4;

    public const int AST_STATE_RINGING = 5;

    public const int AST_STATE_UP = 6;

    public const int AST_STATE_BUSY = 7;

    public const int AST_STATE_DIALING_OFFHOOK = 8;

    public const int AST_STATE_PRERING = 9;

    public const int AUDIO_FILENO = 3; // STDERR_FILENO + 1
}
