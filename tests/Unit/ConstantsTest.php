<?php

use Fperdomo\PhpAgi\Constants;

describe('Constants', function () {
    it('has correct path constants', function () {
        expect(Constants::AST_CONFIG_DIR)->toBe('/etc/asterisk/');
        expect(Constants::AST_SPOOL_DIR)->toBe('/var/spool/asterisk/');
        expect(Constants::AST_TMP_DIR)->toBe('/var/spool/asterisk//tmp/');
        expect(Constants::DEFAULT_PHPAGI_CONFIG)->toBe('/etc/asterisk//phpagi.conf');
    });

    it('has correct digit constant', function () {
        expect(Constants::AST_DIGIT_ANY)->toBe('0123456789#*');
    });

    it('has correct result code', function () {
        expect(Constants::AGIRES_OK)->toBe(200);
    });

    it('has correct state constants', function () {
        expect(Constants::AST_STATE_DOWN)->toBe(0);
        expect(Constants::AST_STATE_RESERVED)->toBe(1);
        expect(Constants::AST_STATE_OFFHOOK)->toBe(2);
        expect(Constants::AST_STATE_DIALING)->toBe(3);
        expect(Constants::AST_STATE_RING)->toBe(4);
        expect(Constants::AST_STATE_RINGING)->toBe(5);
        expect(Constants::AST_STATE_UP)->toBe(6);
        expect(Constants::AST_STATE_BUSY)->toBe(7);
        expect(Constants::AST_STATE_DIALING_OFFHOOK)->toBe(8);
        expect(Constants::AST_STATE_PRERING)->toBe(9);
    });

    it('has correct audio file number', function () {
        expect(Constants::AUDIO_FILENO)->toBe(3);
    });
});
