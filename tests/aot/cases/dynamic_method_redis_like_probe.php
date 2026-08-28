<?php
class RedisLikeReceiver
{
    public function command00(int $x): int { return $x + 0; }
    public function command01(int $x): int { return $x + 1; }
    public function command02(int $x): int { return $x + 2; }
    public function command03(int $x): int { return $x + 3; }
    public function command04(int $x): int { return $x + 4; }
    public function command05(int $x): int { return $x + 5; }
    public function command06(int $x): int { return $x + 6; }
    public function command07(int $x): int { return $x + 7; }
    public function command08(int $x): int { return $x + 8; }
    public function command09(int $x): int { return $x + 9; }
    public function command10(int $x): int { return $x + 10; }
    public function command11(int $x): int { return $x + 11; }
    public function command12(int $x): int { return $x + 12; }
    public function command13(int $x): int { return $x + 13; }
    public function command14(int $x): int { return $x + 14; }
    public function command15(int $x): int { return $x + 15; }

    public function __call(string $name, array $args): mixed
    {
        return 900 + $args[0];
    }

    public function byref_command(int &$value): int
    {
        $value = $value + 4;
        return $value;
    }

    public function variadic_command(int ...$values): int
    {
        $sum = 0;
        foreach ($values as $value) { $sum = $sum + $value; }
        return $sum;
    }
}

function redis_like_commands()
{
    yield ['command07', [5]];
    yield ['missing_command', [6]];
}

function redis_like_call(object $o, string $name, mixed $args): int
{
    return $o->{$name}(...$args);
}

function redis_like_pipeline(object $o): int
{
    $total = 0;
    foreach (redis_like_commands() as $command) {
        $total = $total + redis_like_call($o, $command[0], $command[1]);
    }
    return $total;
}

function redis_like_byref_call(object $o, string $name, int &$value): int
{
    return $o->{$name}($value);
}

function redis_like_variadic_call(object $o, string $name, array $args): int
{
    return $o->{$name}(...$args);
}

$receiver = new RedisLikeReceiver();
$value = 3;
$byref = redis_like_byref_call($receiver, 'byref_command', $value);
$variadic = redis_like_variadic_call($receiver, 'variadic_command', [2, 5, 7]);
$pipeline = redis_like_pipeline($receiver);
echo $pipeline, ':', $byref, ':', $value, ':', $variadic, "\n";
