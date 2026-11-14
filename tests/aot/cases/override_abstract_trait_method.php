<?php
// #[\Override] on a method implementing an abstract trait method is rejected: "no matching parent method exists".
trait LogsT { abstract public function log(string $m): void; public function info(string $m): void { $this->log($m); } }
interface Emitter { public function emit(): void; }
class NoopLogger implements Emitter {
    use LogsT;
    #[\Override] public function emit(): void {}
    #[\Override] public function log(string $m): void { echo "log: $m\n"; }
}
(new NoopLogger)->info("ok");
