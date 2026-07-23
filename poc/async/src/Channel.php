<?php

namespace Async;

/**
 * A Go-style CSP channel over the cooperative scheduler. Communicating fibers pass
 * values through it; the channel — not shared memory — is the synchronisation point.
 *
 * - **Unbuffered** (`capacity 0`): a rendezvous. `send` parks until a receiver takes
 *   the value; `recv` parks until a sender offers one.
 * - **Buffered** (`capacity N`): `send` parks only when the buffer is full; `recv`
 *   parks only when it is empty.
 *
 * `close()` wakes everyone: pending `recv`s return `null`, pending/subsequent `send`s
 * throw. Single-threaded and cooperative, so no locking — a fiber only ever observes
 * the channel between suspension points.
 */
final class Channel
{
    private int $cap;
    private bool $closed = false;

    /** @var mixed[] buffered values, FIFO (only used when cap > 0) */
    private array $buffer = [];

    /** @var Task[] senders parked because the buffer is full / no receiver (unbuffered) */
    private array $sendQ = [];
    /** @var mixed[] the value each parked sender is offering, parallel to $sendQ */
    private array $sendVal = [];

    /** @var Task[] receivers parked because there is nothing to take yet */
    private array $recvQ = [];

    public function __construct(int $capacity = 0)
    {
        $this->cap = $capacity < 0 ? 0 : $capacity;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Send $value, suspending until it is buffered or taken by a receiver. */
    public function send(mixed $value): void
    {
        if ($this->closed) {
            throw new \RuntimeException("send on a closed channel");
        }
        $sched = Scheduler::instance();

        // A receiver is already parked → hand the value straight to it (rendezvous).
        if (\count($this->recvQ) > 0) {
            $r = \array_shift($this->recvQ);
            $r->chanValue = $value;
            $sched->wake($r);
            return;
        }

        // Room in the buffer → enqueue and proceed without suspending.
        if (\count($this->buffer) < $this->cap) {
            $this->buffer[] = $value;
            return;
        }

        // Otherwise park until a receiver takes this exact value.
        $me = $sched->current();
        $this->sendQ[] = $me;
        $this->sendVal[] = $value;
        $sched->suspendCurrent();

        if ($this->closed) {
            throw new \RuntimeException("send on a closed channel");
        }
    }

    /** Receive the next value, suspending until one is available. Returns null once closed + drained. */
    public function recv(): mixed
    {
        $sched = Scheduler::instance();

        // Buffered value → take it, then let one parked sender fill the freed slot.
        if (\count($this->buffer) > 0) {
            $v = \array_shift($this->buffer);
            $this->promoteSender();
            return $v;
        }

        // No buffer, but a sender is parked (unbuffered rendezvous) → take directly.
        if (\count($this->sendQ) > 0) {
            $s = \array_shift($this->sendQ);
            $v = \array_shift($this->sendVal);
            $sched->wake($s);
            return $v;
        }

        if ($this->closed) {
            return null;                     // closed and drained
        }

        // Nothing to take → park until a sender (or close) delivers.
        $me = $sched->current();
        $me->chanValue = null;
        $this->recvQ[] = $me;
        $sched->suspendCurrent();
        return $me->chanValue;
    }

    /** Move one parked sender's value into a buffer slot freed by a recv. */
    private function promoteSender(): void
    {
        if (\count($this->sendQ) > 0 && \count($this->buffer) < $this->cap) {
            $s = \array_shift($this->sendQ);
            $v = \array_shift($this->sendVal);
            $this->buffer[] = $v;
            Scheduler::instance()->wake($s);
        }
    }

    /** Close the channel: parked receivers get null, parked/future senders throw. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $sched = Scheduler::instance();

        foreach ($this->recvQ as $r) {
            $r->chanValue = null;
            $sched->wake($r);
        }
        $this->recvQ = [];

        // Wake parked senders; each re-checks $closed on resume and throws.
        foreach ($this->sendQ as $s) {
            $sched->wake($s);
        }
        $this->sendQ = [];
        $this->sendVal = [];
    }
}
