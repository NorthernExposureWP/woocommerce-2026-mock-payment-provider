<?php

declare(strict_types=1);

class PaymentStorage
{
    public function __construct(private readonly string $file)
    {
    }

    /**
     * Reads payments under a shared lock.
     * Returns [] if the file does not exist yet or is empty.
     *
     * @throws RuntimeException         if the file cannot be read or contains invalid JSON
     * @throws UnexpectedValueException if the JSON is valid but is not an array/object
     */
    public function read(): array
    {
        return $this->withLock(LOCK_SH, function (): array {
            if (!is_file($this->file)) {
                return [];
            }

            $contents = file_get_contents($this->file);
            if ($contents === false) {
                throw new RuntimeException("Cannot read {$this->file}");
            }

            if (trim($contents) === '') {
                return [];
            }

            try {
                $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException("{$this->file} contains invalid JSON: " . $e->getMessage(), 0, $e);
            }

            if (!is_array($data)) {
                throw new UnexpectedValueException(
                    sprintf('%s must contain a JSON array or object, %s given', $this->file, get_debug_type($data))
                );
            }

            return $data;
        });
    }

    /**
     * Writes payments under an exclusive lock. The data goes to a temporary
     * file first, which replaces the original with an atomic rename() only
     * after it has been fully written. On any failure the original file is
     * left untouched.
     *
     * @throws RuntimeException
     */
    public function write(array $payments): void
    {
        // Encode first: a failure here must not touch anything on disk.
        try {
            $json = json_encode($payments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Payments cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }

        $this->withLock(LOCK_EX, function () use ($json): void {
            // Same directory as the target, so rename() stays on one filesystem and is atomic.
            $tmp = sprintf('%s.%s.tmp', $this->file, bin2hex(random_bytes(8)));

            try {
                $this->writeTempFile($tmp, $json);

                if (!rename($tmp, $this->file)) {
                    throw new RuntimeException("Cannot replace {$this->file}");
                }
            } catch (Throwable $e) {
                @unlink($tmp);
                throw $e;
            }
        });
    }

    private function writeTempFile(string $path, string $json): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Cannot create temporary file {$path}");
        }

        try {
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException("Cannot write to temporary file {$path}");
            }

            // Make sure the data is on disk before it replaces the original.
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException("Cannot sync temporary file {$path}");
            }
        } finally {
            fclose($handle);
        }

        // Keep the permissions of the existing file, if there is one.
        if (is_file($this->file)) {
            $perms = fileperms($this->file);
            if ($perms !== false) {
                @chmod($path, $perms & 0777);
            }
        }
    }

    /**
     * Runs $callback while holding a lock on a separate lock file.
     *
     * The lock must not be taken on the JSON file itself: rename() swaps the
     * file for a new inode, so a lock on the old one would protect nothing.
     */
    private function withLock(int $operation, callable $callback): mixed
    {
        $lockFile = $this->file . '.lock';

        $handle = fopen($lockFile, 'cb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open lock file {$lockFile}");
        }

        try {
            if (!flock($handle, $operation)) {
                throw new RuntimeException("Cannot acquire lock on {$lockFile}");
            }

            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}