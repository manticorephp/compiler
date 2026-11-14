<?php

namespace Lsp\JsonRpc;

/** `$/cancelRequest` state. A long handler calls check() between work units: it pulls pending input (poll) and throws RequestCancelled when its own request was cancelled. */
final class Cancellation
{
    /** @var array<string, bool> */
    private array $ids = [];
    private ?string $current = null;
    /** @var callable|null */
    private $poll = null;

    public function setPoll(callable $poll): void
    {
        $this->poll = $poll;
    }

    public function cancel(string $id): void
    {
        $this->ids[$id] = true;
    }

    public function isCancelled(string $id): bool
    {
        return isset($this->ids[$id]);
    }

    public function begin(string $id): void
    {
        $this->current = $id;
    }

    public function end(): void
    {
        if ($this->current !== null) { unset($this->ids[$this->current]); }
        $this->current = null;
    }

    public function check(): void
    {
        if ($this->current === null) { return; }
        if ($this->poll !== null) { ($this->poll)(); }
        if (isset($this->ids[$this->current])) {
            throw new RpcError(RpcError::CANCELLED, 'Request cancelled');
        }
    }
}
