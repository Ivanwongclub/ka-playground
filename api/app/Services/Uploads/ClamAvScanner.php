<?php

namespace App\Services\Uploads;

use RuntimeException;

/**
 * Minimal clamd INSTREAM client (TCP). No third-party package — the protocol is
 * a handful of bytes: "zINSTREAM\0", then {4-byte BE length}{chunk} frames,
 * a zero-length frame to finish, and a "stream: <verdict>" reply.
 */
class ClamAvScanner implements VirusScanner
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function scan(string $contents): ?string
    {
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeoutSeconds,
        );
        if ($socket === false) {
            throw new RuntimeException("clamd unreachable at {$this->host}:{$this->port}: {$errstr}");
        }

        try {
            stream_set_timeout($socket, $this->timeoutSeconds);
            fwrite($socket, "zINSTREAM\0");
            foreach (str_split($contents, 8192) as $chunk) {
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($socket, pack('N', 0));

            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($socket);
        }

        if ($reply === '' || str_contains($reply, 'ERROR')) {
            throw new RuntimeException("clamd scan failed: '{$reply}'");
        }
        if (str_ends_with($reply, 'OK')) {
            return null;
        }
        if (preg_match('/stream:\s*(.+)\s+FOUND$/', $reply, $m) === 1) {
            return $m[1];
        }

        throw new RuntimeException("clamd unexpected reply: '{$reply}'");
    }

    /**
     * clamd PING/PONG liveness (S04E D-4). Never throws: any failure to reach
     * or get a PONG from the daemon returns false, so the caller fails closed.
     */
    public function isAvailable(): bool
    {
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeoutSeconds,
        );
        if ($socket === false) {
            return false;
        }
        try {
            stream_set_timeout($socket, $this->timeoutSeconds);
            fwrite($socket, "zPING\0");
            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } catch (\Throwable) {
            return false;
        } finally {
            fclose($socket);
        }

        return $reply === 'PONG';
    }
}
