<?php

// A concrete-element array handed to a METHOD whose parameter is erased to
// cell elements (`array $b` it never reads, `mixed $b`) is REBUILT into a
// fresh cell array at the call site. The callee only borrows it, so the
// caller drops the rebuild after the call — the static-call path always did,
// the instance-method path did not: `$this->emitBody($body)` in HoistAllocas
// leaked every line of the compiler's own IR. memory_get_usage() answers
// the peak RSS here; 100 000 calls of a ~300-byte leak are ~30 MB, the bound
// is 3 MB. @serial: a memory measurement.

final class Sink
{
    public int $seen = 0;

    public function run(array $lines, int $i): int
    {
        $body = [];
        $body[] = $lines[$i % 3];
        $body[] = $lines[($i + 1) % 3];
        $this->take($body);
        $this->takeMixed($body);
        return \count($body);
    }

    private function take(array $body): void {}

    private function takeMixed(mixed $body): void
    {
        $this->seen = $this->seen + 1;
    }
}

$lines = explode(' ', str_repeat('a', 40) . ' ' . str_repeat('b', 40) . ' ' . str_repeat('c', 40));
$s = new Sink();
$sum = 0;
for ($i = 0; $i < 2000; $i = $i + 1) { $sum = $sum + $s->run($lines, $i); }
$before = memory_get_usage();
for ($i = 0; $i < 100000; $i = $i + 1) { $sum = $sum + $s->run($lines, $i); }
$growth = memory_get_usage() - $before;
echo 'sum=', $sum, ' seen=', $s->seen, ' ', $growth < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($growth / 1048576, 1) . 'MB', "\n";
