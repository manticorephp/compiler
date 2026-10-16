<?php
interface Sub { public static function getSubscribedEvents(): array; }
class Svc implements Sub { public static function getSubscribedEvents(): array { return ['ev' => 'onEv', 'ev2' => ['x', 1]]; } }
class Disp implements Sub {
    public static array $aliases = ['ev' => 'alias.ev'];
    public static string $subscriber;
    public static function getSubscribedEvents(): array {
        $events = [];
        foreach ([self::$subscriber, 'getSubscribedEvents']() as $eventName => $params) {
            $events[self::$aliases[$eventName] ?? $eventName] = $params;
        }
        return $events;
    }
}
Disp::$subscriber = Svc::class;
print_r(Disp::getSubscribedEvents());
$s = new Disp();
foreach (Disp::getSubscribedEvents() as $k => $v) { echo $k, "\n"; }
$x = [Svc::class, 'getSubscribedEvents'];
echo count($x()), "\n";
