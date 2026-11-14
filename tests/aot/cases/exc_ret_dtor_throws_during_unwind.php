<?php
// A destructor that runs while an exception unwinds may throw and catch its own exception; the outer one still arrives.
final class Noisy {
    public function __construct(private string $tag) {}
    public function __destruct() {
        try { throw new Exception("inside {$this->tag}"); } catch (Exception $e) { echo "dtor caught ", $e->getMessage(), "\n"; }
    }
}
function work(): void { $n = new Noisy("a"); throw new DomainException("outer"); }
try { work(); } catch (DomainException $e) { echo "main caught ", $e->getMessage(), "\n"; }
final class Bad { public function __destruct() { throw new UnexpectedValueException("from dtor"); } }
function work2(): void { $b = new Bad(); echo "work2\n"; }
try { work2(); } catch (UnexpectedValueException $e) { echo "caught ", $e->getMessage(), "\n"; }
echo "end\n";
