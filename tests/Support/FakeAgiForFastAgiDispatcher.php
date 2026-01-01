<?php

declare(strict_types=1);

namespace Fperdomo\PhpAgi;

/**
 * Lightweight AGI test double used by FastAgiDispatcher unit tests.
 *
 * The dispatcher instantiates "new AGI()" directly, so we provide a class
 * with that exact name in the same namespace, loaded only in the test suite.
 */
class AGI
{
    /**
     * Test hook: if set to an AGI instance, the next instantiation of this class
     * will copy the prepared instance's state (request/messages) and then clear.
     *
     * @internal
     */
    public static ?self $__nextInstance = null;

    /**
     * Mimics phpagi's request env array.
     *
     * @var array<string, mixed>
     */
    public array $request = [];

    /**
     * Captured conlog messages.
     *
     * @var list<string>
     */
    public array $conlogMessages = [];

    /**
     * Captured verbose messages.
     *
     * @var list<string>
     */
    public array $verboseMessages = [];

    public function __construct()
    {
        if (self::$__nextInstance !== null) {
            $prepared = self::$__nextInstance;
            self::$__nextInstance = null;

            $this->request = $prepared->request;
            $this->conlogMessages = $prepared->conlogMessages;
            $this->verboseMessages = $prepared->verboseMessages;
        }
    }

    public function conlog(string $message): void
    {
        $this->conlogMessages[] = $message;
    }

    public function verbose(string $message): void
    {
        $this->verboseMessages[] = $message;
    }
}
