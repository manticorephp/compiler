<?php

declare(strict_types=1);

namespace Manticore\TestRunner;

final class Result implements \JsonSerializable
{
    public function __construct(
        public string $file,
        public bool $ok,
        public ?string $error = null,
        public bool $skipped = false,
        public float $time = 0.0,
        public ?int $idx = null,
    ) {
    }

    /**
     * @return array{file: string, ok: bool, error: ?string, skipped: bool, time: float, idx: int|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'file' => $this->file,
            'ok' => $this->ok,
            'error' => $this->error,
            'skipped' => $this->skipped,
            'time' => $this->time,
            'idx' => $this->idx,
        ];
    }
}
