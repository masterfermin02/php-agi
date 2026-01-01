<?php

declare(strict_types=1);

namespace Fperdomo\PhpAgi;

final readonly class FastAgiDispatcher
{
    private string $baseDir;

    /**
     * @param  string  $baseDir  Directory where handler scripts are allowed (e.g. /var/lib/asterisk/agi-bin/fastagi-handlers)
     * @param  bool  $dropPrivileges  If true, attempts to setuid/setgid to the handler script owner/group (requires POSIX)
     * @param  bool  $logVerboseDump  If true, logs a debug dump of the AGI instance (noisy; avoid in prod)
     */
    public function __construct(
        string $baseDir,
        private bool $dropPrivileges = false,
        private bool $logVerboseDump = false
    ) {
        $this->baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);
    }

    /**
     * Main entry point. Boots AGI and dispatches to the requested handler.
     */
    public function handle(): void
    {
        // PHPAGI defines class AGI in phpagi.php
        $agi = new AGI;

        if ($this->logVerboseDump) {
            $agi->verbose(print_r($agi, true));
        }

        $requested = $this->getRequestedNetworkScript($agi);
        if ($requested === null || $requested === '') {
            $this->conlog($agi, 'Missing agi_network_script; refusing to dispatch.');

            return;
        }

        $handlerPath = $this->resolveHandlerPath($requested);
        if ($handlerPath === null) {
            $this->conlog($agi, "Script '{$requested}' is not allowed to execute (outside baseDir).");

            return;
        }

        if (! is_file($handlerPath)) {
            $this->conlog($agi, "Handler does not exist: {$handlerPath}");

            return;
        }

        // Optional: drop privileges to file owner/group
        if ($this->dropPrivileges && ! $this->lowerPrivilegesToFileOwner($agi, $handlerPath)) {
            // lowerPrivilegesToFileOwner logs reason
            return;
        }

        if (! is_readable($handlerPath)) {
            $this->conlog($agi, "Handler is not readable: {$handlerPath}");

            return;
        }

        // Make AGI object available to handler scripts consistently:
        //  - $agi: the AGI instance
        //  - $fastagi: alias for older scripts that expect $fastagi
        $fastagi = $agi;

        require_once $handlerPath;
    }

    /**
     * Reads the AGI-requested network script name.
     * In FastAGI, Asterisk sets "agi_network_script".
     */
    private function getRequestedNetworkScript(AGI $agi): ?string
    {
        // PHPAGI typically populates $agi->request with AGI environment
        // (e.g. $agi->request['agi_network_script'])
        $script = $agi->request['agi_network_script'] ?? null;
        if (! is_string($script)) {
            return null;
        }

        // Normalize slashes and trim leading slash to avoid absolute paths
        $script = str_replace(['\\'], '/', $script);

        return ltrim($script, '/');
    }

    /**
     * Resolves a requested script into a filesystem path under baseDir using realpath allowlisting.
     * Returns null if the path is outside baseDir or cannot be resolved safely.
     */
    private function resolveHandlerPath(string $requested): ?string
    {
        // Basic hardening against null bytes
        $requested = str_replace("\0", '', $requested);

        // Construct candidate path
        $candidate = $this->baseDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $requested);

        // realpath() returns false if path doesn't exist.
        // We still want to prevent traversal: compare the resolved directory root.
        $baseReal = realpath($this->baseDir);
        if ($baseReal === false) {
            // Misconfiguration: base dir doesn't exist
            return null;
        }
        $baseReal = rtrim($baseReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        // Resolve the parent directory realpath; then append the filename
        $candidateDir = realpath(dirname($candidate));
        if ($candidateDir === false) {
            return null;
        }
        $candidateDir = rtrim($candidateDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        // Ensure the candidate directory is inside baseReal
        if (! str_starts_with($candidateDir, $baseReal)) {
            return null;
        }

        // Rebuild full path with the original basename
        return $candidateDir.basename($candidate);
    }

    /**
     * Drops privileges to the file owner/group using POSIX functions (if available).
     * Returns false if requested but not possible.
     */
    private function lowerPrivilegesToFileOwner(AGI $agi, string $filePath): bool
    {
        // If POSIX functions aren’t available, fail closed if dropPrivileges is required
        $required = [
            'posix_setgid',
            'posix_setegid',
            'posix_setuid',
            'posix_seteuid',
        ];

        foreach ($required as $fn) {
            if (! function_exists($fn)) {
                $this->conlog($agi, "POSIX not available ({$fn}); cannot drop privileges.");

                return false;
            }
        }

        $owner = @fileowner($filePath);
        $group = @filegroup($filePath);

        if (! is_int($owner) || $owner < 0 || ! is_int($group) || $group < 0) {
            $this->conlog($agi, "Cannot determine owner/group for {$filePath}; cannot drop privileges.");

            return false;
        }

        // Attempt to set group before user
        if (! @posix_setgid($group) || ! @posix_setegid($group) || ! @posix_setuid($owner) || ! @posix_seteuid($owner)) {
            $this->conlog($agi, 'Failed to lower privileges (setuid/setgid). Ensure the service starts with sufficient privileges.');

            return false;
        }

        return true;
    }

    /**
     * Logs to the Asterisk console/log if possible.
     * Falls back to error_log if conlog is unavailable.
     */
    private function conlog(AGI $agi, string $message): void
    {
        // phpagi has conlog(); if not, fallback
        if (method_exists($agi, 'conlog')) {
            $agi->conlog($message);

            return;
        }

        error_log('[FastAgiDispatcher] '.$message);
    }
}
