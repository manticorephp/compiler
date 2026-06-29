<?php
class Money {
    public function __construct(public int $amount, public string $cur) {}
    public function __toString(): string { return $this->amount . " " . $this->cur; }
}
$m = new Money(42, "USD");
echo $m;
echo ",", "price=" . $m;
echo ",", (string)$m;
echo ",", $m instanceof Stringable ? "1" : "0";
