<?php

use Fperdomo\PhpAgi\Agi;
use Fperdomo\PhpAgi\Constants;

describe('Agi', function () {
    beforeEach(function () {
        // Mock stdin/stdout to prevent actual I/O operations
        $this->tempConfig = tempnam(sys_get_temp_dir(), 'phpagi_test_');
        file_put_contents($this->tempConfig, "[phpagi]\nerror_handler=false\ndebug=false\n");
    });

    afterEach(function () {
        if (file_exists($this->tempConfig)) {
            unlink($this->tempConfig);
        }
    });

    it('initializes with default config', function () {
        $agi = new class extends Agi
        {
            public function __construct()
            {
                $this->config = [
                    'phpagi' => [
                        'error_handler' => false,
                        'debug' => false,
                        'admin' => null,
                        'tempdir' => Constants::AST_TMP_DIR,
                    ],
                ];
                $this->request = [];
            }
        };

        expect($agi->config)->toBeArray()
            ->and($agi->request)->toBeArray();
    });

    it('sets option delimiter correctly', function () {
        $agi = new class extends Agi
        {
            public function __construct()
            {
                $this->config = ['phpagi' => ['error_handler' => false]];
                $this->request = [];
                $this->option_delim = ',';
            }
        };

        expect($agi->option_delim)->toBe(',');
    });

    it('can access public properties', function () {
        $agi = new class extends Agi
        {
            public function __construct()
            {
                $this->config = ['phpagi' => ['error_handler' => false]];
                $this->request = [];
                $this->asmanager = null;
                $this->option_delim = ',';
            }
        };

        expect($agi->request)->toBeArray()
            ->and($agi->config)->toBeArray()
            ->and($agi->asmanager)->toBeNull()
            ->and($agi->option_delim)->toBe(',');
    });
});

describe('Agi Commands', function () {
    beforeEach(function () {
        $this->agi = new class extends Agi
        {
            public function __construct()
            {
                $this->config = ['phpagi' => ['error_handler' => false]];
                $this->request = [];
            }

            // Mock evaluate method for testing
            public function evaluate($command): array
            {
                return [
                    'code' => 200,
                    'result' => 0,
                    'data' => '',
                    'command' => $command,
                ];
            }
        };
    });

    it('calls answer command', function () {
        $result = $this->agi->answer();

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('ANSWER');
    });

    it('calls channel_status command', function () {
        $result = $this->agi->channel_status();

        expect($result)->toBeArray()
            ->and($result['command'])->toContain('CHANNEL STATUS');
    });

    it('calls channel_status with channel parameter', function () {
        $this->agi = new class extends Agi
        {
            public function __construct()
            {
                $this->config = ['phpagi' => ['error_handler' => false]];
                $this->request = [];
            }

            public function evaluate($command): array
            {
                return [
                    'code' => 200,
                    'result' => Constants::AST_STATE_UP,
                    'data' => 'Line is up',
                    'command' => $command,
                ];
            }
        };

        $result = $this->agi->channel_status('SIP/1234');

        expect($result)->toBeArray()
            ->and($result['command'])->toContain('CHANNEL STATUS SIP/1234')
            ->and($result['data'])->toBe('Line is up');
    });

    it('calls database_del command', function () {
        $result = $this->agi->database_del('family', 'key');

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('DATABASE DEL "family" "key"');
    });

    it('calls database_deltree command without keytree', function () {
        $result = $this->agi->database_deltree('family');

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('DATABASE DELTREE "family"');
    });

    it('calls database_deltree command with keytree', function () {
        $result = $this->agi->database_deltree('family', 'keytree');

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('DATABASE DELTREE "family" "keytree"');
    });

    it('calls database_get command', function () {
        $result = $this->agi->database_get('family', 'key');

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('DATABASE GET "family" "key"');
    });

    it('calls database_put command', function () {
        $result = $this->agi->database_put('family', 'key', 'value');

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('DATABASE PUT "family" "key" "value"');
    });

    it('escapes newlines in database_put', function () {
        $this->agi = new class extends Agi
        {
            public function __construct()
            {
                $this->config = ['phpagi' => ['error_handler' => false]];
                $this->request = [];
            }

            public function evaluate($command): array
            {
                return [
                    'code' => 200,
                    'result' => 1,
                    'data' => '',
                    'command' => $command,
                ];
            }
        };

        $result = $this->agi->database_put('family', 'key', "line1\nline2");

        expect($result['command'])->toContain('\\n');
    });

    it('calls set_global_var with string value', function () {
        $result = $this->agi->set_global_var('VAR', 'value');

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('Set(VAR="value",g);');
    });

    it('calls set_global_var with numeric value', function () {
        $result = $this->agi->set_global_var('VAR', 123);

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('Set(VAR=123,g);');
    });

    it('calls set_var with string value', function () {
        $result = $this->agi->set_var('VAR', 'value');

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('Set(VAR="value");');
    });

    it('calls set_var with numeric value', function () {
        $result = $this->agi->set_var('VAR', 456);

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('Set(VAR=456);');
    });

    it('calls exec with string options', function () {
        $result = $this->agi->exec('Playback', 'hello-world');

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('EXEC Playback hello-world');
    });

    it('calls exec with array options', function () {
        $this->agi = new class extends Agi
        {
            public function __construct()
            {
                $this->config = ['phpagi' => ['error_handler' => false]];
                $this->request = [];
            }

            public function evaluate($command): array
            {
                return [
                    'code' => 200,
                    'result' => 0,
                    'data' => '',
                    'command' => $command,
                ];
            }
        };

        $result = $this->agi->exec('Dial', ['SIP/1234', '30', 'tT']);

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('EXEC Dial SIP/1234|30|tT');
    });

    it('calls hangup command', function () {
        $result = $this->agi->hangup();

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('HANGUP ');
    });

    it('calls hangup with channel', function () {
        $result = $this->agi->hangup('SIP/1234');

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('HANGUP SIP/1234');
    });

    it('calls noop command', function () {
        $result = $this->agi->noop();

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('NOOP ""');
    });

    it('calls noop with string', function () {
        $result = $this->agi->noop('test message');

        expect($result)->toBeArray()
            ->and($result['command'])->toBe('NOOP "test message"');
    });
});

describe('Agi channel_status states', function () {
    it('returns correct data for DOWN state', function () {
        $agi = new class extends Agi
        {
            public function __construct()
            {
                $this->config = ['phpagi' => ['error_handler' => false]];
                $this->request = [];
            }

            public function evaluate($command): array
            {
                return [
                    'code' => 200,
                    'result' => Constants::AST_STATE_DOWN,
                    'data' => '',
                    'command' => $command,
                ];
            }
        };

        $result = $agi->channel_status();
        expect($result['data'])->toBe('Channel is down and available');
    });

    it('returns correct data for UP state', function () {
        $agi = new class extends Agi
        {
            public function __construct()
            {
                $this->config = ['phpagi' => ['error_handler' => false]];
                $this->request = [];
            }

            public function evaluate($command): array
            {
                return [
                    'code' => 200,
                    'result' => Constants::AST_STATE_UP,
                    'data' => '',
                    'command' => $command,
                ];
            }
        };

        $result = $agi->channel_status();
        expect($result['data'])->toBe('Line is up');
    });

    it('returns correct data for BUSY state', function () {
        $agi = new class extends Agi
        {
            public function __construct()
            {
                $this->config = ['phpagi' => ['error_handler' => false]];
                $this->request = [];
            }

            public function evaluate($command): array
            {
                return [
                    'code' => 200,
                    'result' => Constants::AST_STATE_BUSY,
                    'data' => '',
                    'command' => $command,
                ];
            }
        };

        $result = $agi->channel_status();
        expect($result['data'])->toBe('Line is busy');
    });

    it('returns correct data for RINGING state', function () {
        $agi = new class extends Agi
        {
            public function __construct()
            {
                $this->config = ['phpagi' => ['error_handler' => false]];
                $this->request = [];
            }

            public function evaluate($command): array
            {
                return [
                    'code' => 200,
                    'result' => Constants::AST_STATE_RINGING,
                    'data' => '',
                    'command' => $command,
                ];
            }
        };

        $result = $agi->channel_status();
        expect($result['data'])->toBe('Remote end is ringing');
    });
});
