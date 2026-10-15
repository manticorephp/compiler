<?php

// ext/bcmath — BcMath\Number (php 8.4), a thin immutable wrapper over the bcmath
// functions in src/Runtime/Stdlib/Bcmath.php. DEMAND-GATED (Main.php): a program
// that never names `BcMath\` carries none of it. Result scales follow Zend:
// add/sub/mod = max(scales), mul = sum, div/sqrt/negative pow = scale + 10 with
// trailing zeros trimmed (not below the left operand's scale), pow = scale * exp.
//
// Deviation: round()'s $mode is an int (PHP_ROUND_HALF_*, 1..4); Zend takes the
// RoundingMode enum, which Manticore does not have yet.
// Operators (+ - * / % ** <=> ==) are not here: a separate compiler pass owns them.
namespace BcMath {

    final readonly class Number implements \Stringable, \Manticore\Overloadable
    {
        public string $value;
        public int $scale;

        public function __construct(string|int $num)
        {
            [$this->value, $this->scale] = self::parse('__construct', 1, 'num', (string) $num);
        }

        public function add(Number|string|int $num, ?int $scale = null): Number
        {
            [$b, $sb] = self::operand('add', 1, 'num', $num);
            $s = self::scaleArg('add', 2, $scale) ?? \max($this->scale, $sb);
            return new Number(\bcadd($this->value, $b, $s));
        }

        public function sub(Number|string|int $num, ?int $scale = null): Number
        {
            [$b, $sb] = self::operand('sub', 1, 'num', $num);
            $s = self::scaleArg('sub', 2, $scale) ?? \max($this->scale, $sb);
            return new Number(\bcsub($this->value, $b, $s));
        }

        public function mul(Number|string|int $num, ?int $scale = null): Number
        {
            [$b, $sb] = self::operand('mul', 1, 'num', $num);
            $s = self::scaleArg('mul', 2, $scale) ?? $this->scale + $sb;
            return new Number(\bcmul($this->value, $b, $s));
        }

        public function div(Number|string|int $num, ?int $scale = null): Number
        {
            [$b] = self::operand('div', 1, 'num', $num);
            $s = self::scaleArg('div', 2, $scale);
            if ($s !== null) {
                return new Number(\bcdiv($this->value, $b, $s));
            }
            return new Number(self::trim(\bcdiv($this->value, $b, $this->scale + 10), $this->scale));
        }

        public function mod(Number|string|int $num, ?int $scale = null): Number
        {
            [$b, $sb] = self::operand('mod', 1, 'num', $num);
            $s = self::scaleArg('mod', 2, $scale) ?? \max($this->scale, $sb);
            return new Number(\bcmod($this->value, $b, $s));
        }

        public function pow(Number|string|int $exponent, ?int $scale = null): Number
        {
            [$e] = self::operand('pow', 1, 'exponent', $exponent);
            $s = self::scaleArg('pow', 2, $scale);
            if (\strpos($e, '.') !== false && \rtrim(\substr($e, \strpos($e, '.') + 1), '0') !== '') {
                throw new \ValueError('BcMath\Number::pow(): Argument #1 ($exponent) exponent cannot have a fractional part');
            }
            $e = self::intPart($e);
            $neg = $e[0] === '-';
            $digits = $neg ? \substr($e, 1) : $e;
            if (\strlen($digits) > 19 || (\strlen($digits) === 19 && $digits > ($neg ? '9223372036854775808' : '9223372036854775807'))) {
                throw new \ValueError('BcMath\Number::pow(): Argument #1 ($exponent) exponent is too large');
            }
            if ($s !== null) {
                return new Number(\bcpow($this->value, $e, $s));
            }
            if (!$neg) {
                return new Number(\bcpow($this->value, $e, $this->scale * (int) $e));
            }
            return new Number(self::trim(\bcpow($this->value, $e, $this->scale + 10), $this->scale));
        }

        public function powmod(Number|string|int $exponent, Number|string|int $modulus, ?int $scale = null): Number
        {
            [$e] = self::operand('powmod', 1, 'exponent', $exponent);
            [$m] = self::operand('powmod', 2, 'modulus', $modulus);
            $s = self::scaleArg('powmod', 3, $scale) ?? 0;
            if (self::fractional($this->value)) {
                throw new \ValueError('Base number cannot have a fractional part');
            }
            if (self::fractional($e)) {
                throw new \ValueError('BcMath\Number::powmod(): Argument #1 ($exponent) cannot have a fractional part');
            }
            if ($e[0] === '-') {
                throw new \ValueError('BcMath\Number::powmod(): Argument #1 ($exponent) must be greater than or equal to 0');
            }
            if (self::fractional($m)) {
                throw new \ValueError('BcMath\Number::powmod(): Argument #2 ($modulus) cannot have a fractional part');
            }
            return new Number(\bcpowmod($this->value, $e, $m, $s));
        }

        public function sqrt(?int $scale = null): Number
        {
            $s = self::scaleArg('sqrt', 1, $scale);
            if ($this->value[0] === '-') {
                throw new \ValueError('Base number must be greater than or equal to 0');
            }
            if ($s !== null) {
                return new Number(\bcsqrt($this->value, $s));
            }
            return new Number(self::trim(\bcsqrt($this->value, $this->scale + 10), $this->scale));
        }

        public function floor(): Number
        {
            return new Number(\bcfloor($this->value));
        }

        public function ceil(): Number
        {
            return new Number(\bcceil($this->value));
        }

        public function round(int $precision = 0, int $mode = 1): Number
        {
            return new Number(\bcround($this->value, $precision, $mode));
        }

        public function compare(Number|string|int $num, ?int $scale = null): int
        {
            [$b, $sb] = self::operand('compare', 1, 'num', $num);
            $s = self::scaleArg('compare', 2, $scale) ?? \max($this->scale, $sb);
            return \bccomp($this->value, $b, $s);
        }

        /**
         * Operator hook ({@see \Compile\Mir\Passes\InlineClosures::tryOperator}): `$n + 1`,
         * `2 ** $n`, `-$n` run the matching method. `$swapped` = this was the RIGHT operand.
         * A float operand is truncated to int, as in Zend.
         */
        public function __operator(string $op, mixed $other, bool $swapped): Number
        {
            $o = self::coerce($op, $other, $swapped);
            $l = $swapped ? $o : $this;
            $r = $swapped ? $this : $o;
            return match ($op) {
                '+' => $l->add($r),
                '-' => $l->sub($r),
                '*' => $l->mul($r),
                '/' => $l->div($r),
                '%' => $l->mod($r),
                '**' => $l->pow($r),
            };
        }

        /** Ordering hook for `< <= > >= == != <=>`. A non-numeric string is uncomparable: answers 1. */
        public function __compare(mixed $other): int
        {
            if (\is_string($other) && !\preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $other)) {
                return 1;
            }
            return $this->compare(self::coerce('<=>', $other, false));
        }

        private static function coerce(string $op, mixed $x, bool $swapped): Number
        {
            if ($x instanceof Number) {
                return $x;
            }
            if (\is_int($x) || \is_float($x) || $x === null || \is_bool($x)) {
                return new Number((int) $x);
            }
            if (\is_string($x)) {
                if (!\preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $x)) {
                    throw new \ValueError(($swapped ? 'Left' : 'Right') . ' string operand cannot be converted to BcMath\Number');
                }
                return new Number($x);
            }
            $me = 'BcMath\Number';
            $you = \get_debug_type($x);
            throw new \TypeError('Unsupported operand types: ' . ($swapped ? $you . ' ' . $op . ' ' . $me : $me . ' ' . $op . ' ' . $you));
        }

        public function __toString(): string
        {
            return $this->value;
        }

        /** @return array<string,string> */
        public function __serialize(): array
        {
            return ['value' => $this->value];
        }

        /** @param array<string,mixed> $data */
        public function __unserialize(array $data): void
        {
            $v = $data['value'] ?? null;
            if (!\is_string($v) || \preg_match('/^[+-]?(\d*)(?:\.(\d*))?$/D', $v) !== 1) {
                throw new \Exception('Invalid serialization data for BcMath\Number object');
            }
            [$this->value, $this->scale] = self::parse('__unserialize', 1, 'value', $v);
        }

        /** @return array{0:string,1:int} normalized value, scale */
        private static function parse(string $fn, int $no, string $name, string $s): array
        {
            // Fast path: the canonical `-?int(.frac)?` text bc* itself returns — no regex.
            $body = $s !== '' && $s[0] === '-' ? \substr($s, 1) : $s;
            $dot = \strpos($body, '.');
            $ip = $dot === false ? $body : \substr($body, 0, $dot);
            $fp = $dot === false ? '' : \substr($body, $dot + 1);
            if ($ip !== '' && \ctype_digit($ip) && ($ip === '0' || $ip[0] !== '0')
                && ($dot === false || ($fp !== '' && \ctype_digit($fp)))
                && ($body === $s || \ltrim($ip . $fp, '0') !== '')) {
                return [$s, \strlen($fp)];
            }
            if (\preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/D', $s, $m) !== 1) {
                throw new \ValueError('BcMath\Number::' . $fn . '(): Argument #' . $no . ' ($' . $name . ') is not well-formed');
            }
            $int = \ltrim($m[2], '0');
            if ($int === '') {
                $int = '0';
            }
            $frac = $m[3] ?? '';
            $neg = $m[1] === '-' && ($int !== '0' || \rtrim($frac, '0') !== '');
            return [($neg ? '-' : '') . $int . ($frac === '' ? '' : '.' . $frac), \strlen($frac)];
        }

        /** @return array{0:string,1:int} */
        private static function operand(string $fn, int $no, string $name, Number|string|int $x): array
        {
            if ($x instanceof Number) {
                return [$x->value, $x->scale];
            }
            return self::parse($fn, $no, $name, (string) $x);
        }

        private static function scaleArg(string $fn, int $no, ?int $scale): ?int
        {
            if ($scale !== null && ($scale < 0 || $scale > 2147483647)) {
                throw new \ValueError('BcMath\Number::' . $fn . '(): Argument #' . $no . ' ($scale) must be between 0 and 2147483647');
            }
            return $scale;
        }

        private static function fractional(string $v): bool
        {
            $p = \strpos($v, '.');
            return $p !== false && \rtrim(\substr($v, $p + 1), '0') !== '';
        }

        private static function intPart(string $v): string
        {
            $p = \strpos($v, '.');
            $i = $p === false ? $v : \substr($v, 0, $p);
            return $i === '-0' ? '0' : $i;
        }

        /** drop trailing fraction zeros, keeping at least $min fraction digits */
        private static function trim(string $v, int $min): string
        {
            $p = \strpos($v, '.');
            if ($p === false) {
                return $v;
            }
            $end = \strlen($v);
            while ($end - $p - 1 > $min && $v[$end - 1] === '0') {
                $end--;
            }
            return \substr($v, 0, $end === $p + 1 ? $p : $end);
        }
    }
}
