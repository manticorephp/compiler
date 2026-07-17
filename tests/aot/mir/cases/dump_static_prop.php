<?php
class Counter {
    public static int $count = 0;
}
function bump(): int {
    Counter::$count = Counter::$count + 1;
    return Counter::$count;
}
echo bump(), bump();
