<?php
class Logger extends Missing\AbstractLogger {
    const INFO = 'info';
    const LEVELS = [self::INFO => 1];
    public function log(string $l = self::INFO): void {}
}
class Event extends Missing\Event {
    const NAME = 'e';
    const ALL = [self::NAME];
}
new Event();
echo \count((new \ReflectionClass(\stdClass::class))->getConstants()), "\n";
echo "ok\n";
