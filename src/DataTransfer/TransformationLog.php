<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

/** Optional streaming JSONL audit of every change, including attempted load changes. */
final class TransformationLog
{
    /** @var resource|null */
    private $handle;

    public function __construct(string $path, TransformationRules $rules)
    {
        if ($path === '' || str_contains($path, '://') || file_exists($path) || is_link($path)) {
            throw new \InvalidArgumentException('Change log must be a new local file. Existing files are never overwritten.');
        }
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create change log; the parent directory must exist and be writable.');
        }
        $this->handle = $handle;
        $this->write(['event' => 'start', 'version' => 1, 'configuration' => $rules->describe()]);
    }

    public function write(array $event): void
    {
        $line = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if ($this->handle === null || fwrite($this->handle, $line) !== strlen($line) || !fflush($this->handle)) {
            throw new \RuntimeException('Could not write the complete transformation audit log.');
        }
    }

    public function finish(string $outcome): void
    {
        try {
            $this->write(['event' => 'end', 'outcome' => $outcome]);
        } catch (\Throwable) {
            throw new \RuntimeException('Could not finalize the change log; target operation outcome: '.$outcome.'. Inspect the target database.');
        } finally {
            if ($this->handle !== null) {
                fclose($this->handle);
                $this->handle = null;
            }
        }
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }
}
