<?php
function mk(): \Closure {
    $default = ['this' => '$this', '@this' => '$this', '$self' => 'self'];
    return static function ($options, array $value) use ($default): array {
        $normalizedValue = [];
        foreach ($value as $from => $to) {
            if (\is_string($from)) {
                $from = strtolower($from);
            }
            if (!isset($default[$from])) {
                throw new \InvalidArgumentException(\sprintf('Unknown key "%s"', \gettype($from) . '#' . $from));
            }
            $normalizedValue[$from] = $to;
        }
        return $normalizedValue;
    };
}
/** @param array<string, mixed> $opts */
function run(\Closure $n, array $opts): void { var_dump($n(null, $opts['replacements'])); }
$n = mk();
run($n, ['replacements' => ['this' => '$this', '@THIS' => 'self', '$self' => 'self']]);
$m = ['this' => '$this', '@this' => '$this'];
var_dump($n(null, $m));
try { $n(null, ['x' => 1]); } catch (\InvalidArgumentException $e) { echo $e->getMessage(), "\n"; }
