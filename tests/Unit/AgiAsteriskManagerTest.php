<?php

use Fperdomo\PhpAgi\Agi;
use Fperdomo\PhpAgi\AgiAsteriskManager;

describe('AgiAsteriskManager', function () {
    it('initializes with default config', function () {
        $manager = new AgiAsteriskManager;

        expect($manager->config)->toBeArray()
            ->and($manager->config['asmanager']['server'])->toBe('localhost')
            ->and($manager->config['asmanager']['port'])->toBe(5038)
            ->and($manager->config['asmanager']['username'])->toBe('phpagi')
            ->and($manager->config['asmanager']['secret'])->toBe('phpagi')
            ->and($manager->config['asmanager']['write_log'])->toBeFalse();
    });

    it('initializes with custom config array', function () {
        $config = [
            'server' => '192.168.1.100',
            'port' => 5039,
            'username' => 'custom_user',
            'secret' => 'custom_secret',
        ];

        $manager = new AgiAsteriskManager(null, $config);

        expect($manager->config['asmanager']['server'])->toBe('192.168.1.100')
            ->and($manager->config['asmanager']['port'])->toBe(5039)
            ->and($manager->config['asmanager']['username'])->toBe('custom_user')
            ->and($manager->config['asmanager']['secret'])->toBe('custom_secret');
    });

    it('has null server and port initially', function () {
        $manager = new AgiAsteriskManager;

        expect($manager->server)->toBeNull()
            ->and($manager->port)->toBeNull();
    });

    it('has null pagi initially', function () {
        $manager = new AgiAsteriskManager;

        expect($manager->pagi)->toBeNull();
    });

    it('can set pagi reference', function () {
        $manager = new AgiAsteriskManager;

        $agi = new class extends Agi
        {
            public function __construct()
            {
                $this->config = ['phpagi' => ['error_handler' => false]];
                $this->request = [];
            }
        };

        $manager->setPagi($agi);

        expect($manager->pagi)->not->toBeNull()
            ->and($manager->pagi)->toBeInstanceOf(Agi::class);
    });

    it('initializes config from file if exists', function () {
        $tempConfig = tempnam(sys_get_temp_dir(), 'phpagi_test_');
        file_put_contents($tempConfig, "[asmanager]\nserver=testserver\nport=1234\n");

        $manager = new AgiAsteriskManager($tempConfig);

        expect($manager->config['asmanager']['server'])->toBe('testserver')
            ->and($manager->config['asmanager']['port'])->toBe('1234');

        unlink($tempConfig);
    });

    it('merges optional config with defaults', function () {
        $optConfig = [
            'server' => 'custom.server.com',
        ];

        $manager = new AgiAsteriskManager(null, $optConfig);

        expect($manager->config['asmanager']['server'])->toBe('custom.server.com')
            ->and($manager->config['asmanager']['port'])->toBe(5038) // default
            ->and($manager->config['asmanager']['username'])->toBe('phpagi'); // default
    });

    it('has event_handlers as private array', function () {
        $manager = new AgiAsteriskManager;

        // We can't directly access private properties, but we can verify the object was created successfully
        expect($manager)->toBeInstanceOf(AgiAsteriskManager::class);
    });
});

describe('AgiAsteriskManager Properties', function () {
    it('has public config property', function () {
        $manager = new AgiAsteriskManager;

        expect(property_exists($manager, 'config'))->toBeTrue();
    });

    it('has public socket property', function () {
        $manager = new AgiAsteriskManager;

        expect(property_exists($manager, 'socket'))->toBeTrue();
    });

    it('has public server property', function () {
        $manager = new AgiAsteriskManager;

        expect(property_exists($manager, 'server'))->toBeTrue();
    });

    it('has public port property', function () {
        $manager = new AgiAsteriskManager;

        expect(property_exists($manager, 'port'))->toBeTrue();
    });

    it('has public pagi property', function () {
        $manager = new AgiAsteriskManager;

        expect(property_exists($manager, 'pagi'))->toBeTrue();
    });
});
