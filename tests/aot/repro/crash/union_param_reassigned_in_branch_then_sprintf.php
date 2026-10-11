<?php
// A string|bool|null parameter reassigned to a string inside `if (true === $format)` segfaults when later passed to sprintf
// issue: #185
final class B
{
    public function getEnv(string $n): string { return strtoupper($n); }
    public function esc(string $v): string { return str_replace('%', '%%', $v); }

    public function resolve(mixed $value, string|bool|null $format = null, ?array &$usedEnvs = null): mixed
    {
        if (true === $format) {
            $format = '%%env(%s)%%';
        }
        if (\is_string($value)) {
            foreach (['foo' => ['env_a'], 'bar' => ['env_b']] as $env => $placeholders) {
                foreach ($placeholders as $placeholder) {
                    if (false !== stripos($value, $placeholder)) {
                        if (true === $format) {
                            $resolved = $this->esc($this->getEnv($env));
                        } else {
                            $resolved = sprintf($format, $env);
                        }
                        $usedEnvs[$env] = $env;
                        $value = str_ireplace($placeholder, $resolved, $value);
                    }
                }
            }
        }
        return $value;
    }
}

$b = new B();
$used = [];
echo $b->resolve('x env_a y', true, $used), "\n";
echo $b->resolve('x env_b y', '[%s]', $used), "\n";
echo $b->resolve('nothing', null), "\n";
echo implode(',', array_keys($used)), "\n";
