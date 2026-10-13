<?php

// ext/random — Random\Randomizer, Random\Engine\{Mt19937, Xoshiro256StarStar,
// PcgOneseq128XslRr64, Secure}, Random\IntervalBoundary and the error classes.
// DEMAND-GATED (Main.php): a program that never names `Random\` carries none of
// it. Seeded engines reproduce php's sequences bit for bit; the Randomizer's
// range / shuffle / pick / getFloat algorithms are php's, draw for draw.
//
// An engine word is an unsigned 64-bit value held in an int. `+`, `-`, `*` on
// such words must not overflow into float under Zend (this file is prototyped
// there), so every 64-bit add / multiply goes through the helpers below.
namespace Random {

    /** unsigned 64-bit logical shift right, 0 <= n < 64 */
    function __ushr(int $x, int $n): int
    {
        if ($n === 0) { return $x; }
        return ($x >> $n) & (\PHP_INT_MAX >> ($n - 1));
    }

    function __ult(int $a, int $b): bool
    {
        return ($a ^ \PHP_INT_MIN) < ($b ^ \PHP_INT_MIN);
    }

    /** a + b modulo 2^64 */
    function __add64(int $a, int $b): int
    {
        $lo = ($a & 0xFFFFFFFF) + ($b & 0xFFFFFFFF);
        $hi = (($a >> 32) & 0xFFFFFFFF) + (($b >> 32) & 0xFFFFFFFF) + ($lo >> 32);

        return (($hi & 0xFFFFFFFF) << 32) | ($lo & 0xFFFFFFFF);
    }

    function __neg64(int $a): int
    {
        return __add64(~$a, 1);
    }

    function __sub64(int $a, int $b): int
    {
        return __add64($a, __neg64($b));
    }

    /** a * b modulo 2^64, by 16-bit limbs (no product reaches 2^63) */
    function __mul64(int $a, int $b): int
    {
        $a0 = $a & 0xFFFF; $a1 = ($a >> 16) & 0xFFFF; $a2 = ($a >> 32) & 0xFFFF; $a3 = ($a >> 48) & 0xFFFF;
        $b0 = $b & 0xFFFF; $b1 = ($b >> 16) & 0xFFFF; $b2 = ($b >> 32) & 0xFFFF; $b3 = ($b >> 48) & 0xFFFF;
        $c0 = $a0 * $b0;
        $c1 = $a0 * $b1 + $a1 * $b0 + ($c0 >> 16);
        $c2 = $a0 * $b2 + $a1 * $b1 + $a2 * $b0 + ($c1 >> 16);
        $c3 = $a0 * $b3 + $a1 * $b2 + $a2 * $b1 + $a3 * $b0 + ($c2 >> 16);

        return (($c3 & 0xFFFF) << 48) | (($c2 & 0xFFFF) << 32) | (($c1 & 0xFFFF) << 16) | ($c0 & 0xFFFF);
    }

    /**
     * The full 128-bit product of two unsigned 64-bit words.
     * @return int[] [hi, lo]
     */
    function __mul64full(int $a, int $b): array
    {
        $x = [$a & 0xFFFF, ($a >> 16) & 0xFFFF, ($a >> 32) & 0xFFFF, ($a >> 48) & 0xFFFF];
        $y = [$b & 0xFFFF, ($b >> 16) & 0xFFFF, ($b >> 32) & 0xFFFF, ($b >> 48) & 0xFFFF];
        $r = [0, 0, 0, 0, 0, 0, 0, 0];
        $carry = 0;
        for ($k = 0; $k < 8; $k = $k + 1) {
            $acc = $carry;
            for ($i = 0; $i < 4; $i = $i + 1) {
                $j = $k - $i;
                if ($j >= 0 && $j < 4) { $acc = $acc + $x[$i] * $y[$j]; }
            }
            $r[$k] = $acc & 0xFFFF;
            $carry = $acc >> 16;
        }
        $lo = ($r[3] << 48) | ($r[2] << 32) | ($r[1] << 16) | $r[0];
        $hi = ($r[7] << 48) | ($r[6] << 32) | ($r[5] << 16) | $r[4];

        return [$hi, $lo];
    }

    /**
     * (h1:l1) * (h2:l2) modulo 2^128.
     * @return int[] [hi, lo]
     */
    function __mul128(int $h1, int $l1, int $h2, int $l2): array
    {
        $f = __mul64full($l1, $l2);
        $hi = __add64($f[0], __add64(__mul64($h1, $l2), __mul64($l1, $h2)));

        return [$hi, $f[1]];
    }

    /**
     * (h1:l1) + (h2:l2) modulo 2^128.
     * @return int[] [hi, lo]
     */
    function __add128(int $h1, int $l1, int $h2, int $l2): array
    {
        $lo = __add64($l1, $l2);
        $hi = __add64($h1, $h2);
        if (__ult($lo, $l1)) { $hi = __add64($hi, 1); }

        return [$hi, $lo];
    }

    /** x mod m for unsigned 64-bit x and unsigned m != 0 */
    function __umod(int $x, int $m): int
    {
        if ($m < 0) {
            return __ult($x, $m) ? $x : __sub64($x, $m);
        }
        if ($x >= 0) { return $x % $m; }
        $q = \intdiv(__ushr($x, 1), $m) << 1;
        $r = __sub64($x, __mul64($q, $m));
        while (!__ult($r, $m)) { $r = __sub64($r, $m); }

        return $r;
    }

    function __c64(int $hi, int $lo): int
    {
        return ($hi << 32) | $lo;
    }

    function __rotl(int $x, int $k): int
    {
        return ($x << $k) | __ushr($x, 64 - $k);
    }

    /** the 8 bytes at $off as a little-endian word */
    function __le64(string $s, int $off): int
    {
        $v = 0;
        for ($j = 7; $j >= 0; $j = $j - 1) { $v = ($v << 8) | \ord($s[$off + $j]); }

        return $v;
    }

    /** the low $n bytes of $v, little-endian */
    function __word(int $v, int $n): string
    {
        $out = '';
        for ($j = 0; $j < $n; $j = $j + 1) { $out .= \chr(($v >> (8 * $j)) & 0xFF); }

        return $out;
    }

    /** php_random_bin2hex_le */
    function __hexle(int $v, int $bytes): string
    {
        $out = '';
        for ($j = 0; $j < $bytes; $j = $j + 1) {
            $out .= \sprintf('%02x', ($v >> (8 * $j)) & 0xFF);
        }

        return $out;
    }

    /** php_random_hex2bin_le; null when $s is not exactly 2*$bytes hex digits */
    function __unhexle(mixed $s, int $bytes): ?int
    {
        if (!\is_string($s) || \strlen($s) !== 2 * $bytes || !\ctype_xdigit($s)) { return null; }
        $v = 0;
        for ($j = $bytes - 1; $j >= 0; $j = $j - 1) {
            $v = ($v << 8) | \hexdec(\substr($s, 2 * $j, 2));
        }

        return $v;
    }

    function __bits(float $x): int
    {
        return \__mc_nbuf_f_bits($x, 8);
    }

    function __fromBits(int $b): float
    {
        return \__mc_nbuf_bits_f($b, 8);
    }

    /** C nextafter(x, +DBL_MAX) */
    function __nextUp(float $x): float
    {
        if ($x === 0.0) { return 4.9406564584124654e-324; }
        if ($x === \PHP_FLOAT_MAX) { return $x; }
        $b = __bits($x);

        return __fromBits($x > 0.0 ? $b + 1 : $b - 1);
    }

    /** C nextafter(x, -DBL_MAX) */
    function __nextDown(float $x): float
    {
        if ($x === 0.0) { return -4.9406564584124654e-324; }
        if ($x === -\PHP_FLOAT_MAX) { return $x; }
        $b = __bits($x);

        return __fromBits($x > 0.0 ? $b - 1 : $b + 1);
    }

    function __gammaMax(float $x, float $y): float
    {
        return (\abs($x) > \abs($y)) ? (__nextUp($x) - $x) : ($y - __nextDown($y));
    }

    function __ceilint(float $a, float $b, float $g): int
    {
        $s = $b / $g - $a / $g;
        if (\abs($a) <= \abs($b)) {
            $e = -$a / $g - ($s - $b / $g);
        } else {
            $e = $b / $g - ($s + $a / $g);
        }
        $si = \ceil($s);

        return ($s != $si) ? (int)$si : (int)$si + ($e > 0 ? 1 : 0);
    }

    /** 4 * (m * 0.25 - hi * g) - lo * g, with k split into hi = k >> 2, lo = k & 3 */
    function __gsDown(float $m, int $k, float $g): float
    {
        return 4.0 * ($m * 0.25 - (float)($k >> 2) * $g) - (float)($k & 3) * $g;
    }

    function __gsUp(float $m, int $k, float $g): float
    {
        return 4.0 * ($m * 0.25 + (float)($k >> 2) * $g) + (float)($k & 3) * $g;
    }

    interface Engine
    {
        public function generate(): string;
    }

    interface CryptoSafeEngine extends Engine
    {
    }

    enum IntervalBoundary
    {
        case ClosedOpen;
        case ClosedClosed;
        case OpenClosed;
        case OpenOpen;
    }

    class RandomError extends \Error
    {
    }

    class BrokenRandomEngineError extends RandomError
    {
    }

    class RandomException extends \Exception
    {
    }

    final class Randomizer
    {
        public readonly Engine $engine;
        // 0 userland, 1 Mt19937, 2 Xoshiro256StarStar, 3 PcgOneseq128XslRr64, 4 Secure
        private int $kind = 0;
        // byte size of the last word gen() returned
        private int $sz = 8;
        private ?\Random\Engine\Mt19937 $mt = null;
        private ?\Random\Engine\Xoshiro256StarStar $xo = null;
        private ?\Random\Engine\PcgOneseq128XslRr64 $pcg = null;
        private ?\Random\Engine\Secure $sec = null;

        public function __construct(?Engine $engine = null)
        {
            $this->engine = $engine ?? new \Random\Engine\Secure();
            $this->bind($this->engine);
        }

        private function bind(Engine $e): void
        {
            if ($e instanceof \Random\Engine\Mt19937) {
                $this->kind = 1; $this->mt = $e;
            } elseif ($e instanceof \Random\Engine\Xoshiro256StarStar) {
                $this->kind = 2; $this->xo = $e;
            } elseif ($e instanceof \Random\Engine\PcgOneseq128XslRr64) {
                $this->kind = 3; $this->pcg = $e;
            } elseif ($e instanceof \Random\Engine\Secure) {
                $this->kind = 4; $this->sec = $e;
            } else {
                $this->kind = 0;
            }
        }

        /** one engine word as an unsigned 64-bit value; sets $this->sz */
        private function gen(): int
        {
            if ($this->kind === 1) { $this->sz = 4; return $this->mt->__next(); }
            if ($this->kind === 2) { $this->sz = 8; return $this->xo->__next(); }
            if ($this->kind === 3) { $this->sz = 8; return $this->pcg->__next(); }
            if ($this->kind === 4) { $this->sz = 8; return $this->sec->__next(); }
            $s = $this->engine->generate();
            $n = \strlen($s);
            if ($n > 8) { $n = 8; }
            if ($n === 0) {
                throw new BrokenRandomEngineError('A random engine must return a non-empty string');
            }
            $r = 0;
            for ($i = 0; $i < $n; $i = $i + 1) { $r = $r | (\ord($s[$i]) << (8 * $i)); }
            $this->sz = $n;

            return $r;
        }

        private function gen32(): int
        {
            $result = 0;
            $total = 0;
            do {
                $r = $this->gen();
                $result = $result | ((($r & 0xFFFFFFFF) << ($total * 8)) & 0xFFFFFFFF);
                $total = $total + $this->sz;
            } while ($total < 4);

            return $result;
        }

        private function gen64(): int
        {
            $result = 0;
            $total = 0;
            do {
                $r = $this->gen();
                $result = $result | ($r << ($total * 8));
                $total = $total + $this->sz;
            } while ($total < 8);

            return $result;
        }

        private function range32(int $umax): int
        {
            $result = $this->gen32();
            if ($umax === 0xFFFFFFFF) { return $result; }
            $umax = $umax + 1;
            if (($umax & ($umax - 1)) === 0) { return $result & ($umax - 1); }
            $limit = 0xFFFFFFFF - (0xFFFFFFFF % $umax) - 1;
            $count = 0;
            while ($result > $limit) {
                $count = $count + 1;
                if ($count > 50) {
                    throw new BrokenRandomEngineError('Failed to generate an acceptable random number in 50 attempts');
                }
                $result = $this->gen32();
            }

            return $result % $umax;
        }

        private function range64(int $umax): int
        {
            $result = $this->gen64();
            if ($umax === -1) { return $result; }
            $umax = __add64($umax, 1);
            $mask = __add64($umax, -1);
            if (($umax & $mask) === 0) { return $result & $mask; }
            $limit = __add64(~__umod(-1, $umax), -1);
            $count = 0;
            while (__ult($limit, $result)) {
                $count = $count + 1;
                if ($count > 50) {
                    throw new BrokenRandomEngineError('Failed to generate an acceptable random number in 50 attempts');
                }
                $result = $this->gen64();
            }

            return __umod($result, $umax);
        }

        /** php_random_range off the bound engine (Secure answers random_int, as php does) */
        private function range(int $min, int $max): int
        {
            if ($this->kind === 4) { return \random_int($min, $max); }
            $umax = __sub64($max, $min);
            if ($umax < 0 || $umax > 0xFFFFFFFF) {
                return __add64($this->range64($umax), $min);
            }

            return $this->range32($umax) + $min;
        }

        public function nextInt(): int
        {
            return __ushr($this->gen(), 1);
        }

        public function nextFloat(): float
        {
            return (float)__ushr($this->gen64(), 11) * (1.0 / 9007199254740992.0);
        }

        public function getInt(int $min, int $max): int
        {
            if ($max < $min) {
                throw new \ValueError('Random\Randomizer::getInt(): Argument #2 ($max) must be greater than or equal to argument #1 ($min)');
            }
            if ($this->kind === 1 && $this->mt->__mode() !== 0) {
                $r = __ushr($this->mt->__next(), 1);
                $offset = (int)(((float)$max - (float)$min + 1.0) * ((float)$r / 2147483648.0));

                return __add64($offset, $min);
            }

            return $this->range($min, $max);
        }

        public function getFloat(float $min, float $max, IntervalBoundary $boundary = IntervalBoundary::ClosedOpen): float
        {
            if (\is_nan($min) || \is_infinite($min)) {
                throw new \ValueError('Random\Randomizer::getFloat(): Argument #1 ($min) must be finite');
            }
            if (\is_nan($max) || \is_infinite($max)) {
                throw new \ValueError('Random\Randomizer::getFloat(): Argument #2 ($max) must be finite');
            }
            $g = __gammaMax($min, $max);
            if ($boundary === IntervalBoundary::ClosedOpen) {
                if ($max <= $min) {
                    throw new \ValueError('Random\Randomizer::getFloat(): Argument #2 ($max) must be greater than argument #1 ($min)');
                }
                $hi = __ceilint($min, $max, $g);
                if ($hi < 1) { return NAN; }
                $k = 1 + $this->range64($hi - 1);
                if (\abs($min) <= \abs($max)) {
                    return $k === $hi ? $min : __gsDown($max, $k, $g);
                }

                return __gsUp($min, $k - 1, $g);
            }
            if ($boundary === IntervalBoundary::ClosedClosed) {
                if ($max < $min) {
                    throw new \ValueError('Random\Randomizer::getFloat(): Argument #2 ($max) must be greater than or equal to argument #1 ($min)');
                }
                $hi = __ceilint($min, $max, $g);
                $k = $this->range64($hi);
                if (\abs($min) <= \abs($max)) {
                    return $k === $hi ? $min : __gsDown($max, $k, $g);
                }

                return $k === $hi ? $max : __gsUp($min, $k, $g);
            }
            if ($max <= $min) {
                throw new \ValueError('Random\Randomizer::getFloat(): Argument #2 ($max) must be greater than argument #1 ($min)');
            }
            $hi = __ceilint($min, $max, $g);
            if ($boundary === IntervalBoundary::OpenClosed) {
                if ($hi < 1) { return NAN; }
                $k = $this->range64($hi - 1);
                if (\abs($min) <= \abs($max)) { return __gsDown($max, $k, $g); }

                return $k === $hi - 1 ? $max : __gsUp($min, $k + 1, $g);
            }
            if ($hi < 2) {
                throw new \ValueError('The given interval is empty, there are no floats between argument #1 ($min) and argument #2 ($max)');
            }
            $k = 1 + $this->range64($hi - 2);
            if (\abs($min) <= \abs($max)) { return __gsDown($max, $k, $g); }

            return __gsUp($min, $k, $g);
        }

        public function getBytes(int $length): string
        {
            if ($length < 1) {
                throw new \ValueError('Random\Randomizer::getBytes(): Argument #1 ($length) must be greater than 0');
            }
            $out = '';
            $total = 0;
            while ($total < $length) {
                $r = $this->gen();
                $n = $this->sz;
                for ($i = 0; $i < $n; $i = $i + 1) {
                    $out .= \chr($r & 0xFF);
                    $r = __ushr($r, 8);
                    $total = $total + 1;
                    if ($total >= $length) { break; }
                }
            }

            return $out;
        }

        public function getBytesFromString(string $string, int $length): string
        {
            $srcLen = \strlen($string);
            $maxOffset = $srcLen - 1;
            if ($srcLen < 1) {
                throw new \ValueError('Random\Randomizer::getBytesFromString(): Argument #1 ($string) must not be empty');
            }
            if ($length < 1) {
                throw new \ValueError('Random\Randomizer::getBytesFromString(): Argument #2 ($length) must be greater than 0');
            }
            $out = '';
            $total = 0;
            if ($maxOffset > 0xFF) {
                while ($total < $length) {
                    $out .= $string[$this->range(0, $maxOffset)];
                    $total = $total + 1;
                }

                return $out;
            }
            $m = $maxOffset;
            $m = $m | ($m >> 1);
            $m = $m | ($m >> 2);
            $m = $m | ($m >> 4);
            $mask = 0;
            for ($i = 0; $i < 8; $i = $i + 1) { $mask = $mask | ($m << (8 * $i)); }
            $failures = 0;
            while ($total < $length) {
                $r = $this->gen();
                $offsets = $r & $mask;
                $n = $this->sz;
                for ($i = 0; $i < $n; $i = $i + 1) {
                    $offset = $offsets & 0xFF;
                    $offsets = __ushr($offsets, 8);
                    if ($offset > $maxOffset) {
                        $failures = $failures + 1;
                        if ($failures > 50) {
                            throw new BrokenRandomEngineError('Failed to generate an acceptable random number in 50 attempts');
                        }
                        continue;
                    }
                    $failures = 0;
                    $out .= $string[$offset];
                    $total = $total + 1;
                    if ($total >= $length) { break; }
                }
            }

            return $out;
        }

        /**
         * @param mixed[] $array
         * @return mixed[]
         */
        public function shuffleArray(array $array): array
        {
            $values = [];
            foreach ($array as $v) { $values[] = $v; }
            $left = \count($values) - 1;
            while ($left > 0) {
                $j = $this->range(0, $left);
                if ($j !== $left) {
                    $t = $values[$left];
                    $values[$left] = $values[$j];
                    $values[$j] = $t;
                }
                $left = $left - 1;
            }

            return $values;
        }

        public function shuffleBytes(string $bytes): string
        {
            $n = \strlen($bytes);
            if ($n < 2) { return $bytes; }
            $left = $n - 1;
            while ($left > 0) {
                $j = $this->range(0, $left);
                if ($j !== $left) {
                    $t = $bytes[$left];
                    $bytes[$left] = $bytes[$j];
                    $bytes[$j] = $t;
                }
                $left = $left - 1;
            }

            return $bytes;
        }

        /**
         * @param mixed[] $array
         * @return array<int,int|string>
         */
        public function pickArrayKeys(array $array, int $num): array
        {
            $keys = \array_keys($array);
            $avail = \count($keys);
            if ($avail === 0) {
                throw new \ValueError('Random\Randomizer::pickArrayKeys(): Argument #1 ($array) must not be empty');
            }
            if ($num === 1) {
                return [$keys[$this->range(0, $avail - 1)]];
            }
            if ($num <= 0 || $num > $avail) {
                throw new \ValueError('Random\Randomizer::pickArrayKeys(): Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array)');
            }
            $negative = false;
            if ($num > ($avail >> 1)) {
                $negative = true;
                $num = $avail - $num;
            }
            /** @var array<int,bool> $set */
            $set = [];
            $failures = 0;
            $i = $num;
            while ($i > 0) {
                $r = $this->range(0, $avail - 1);
                if (isset($set[$r])) {
                    $failures = $failures + 1;
                    if ($failures > 50) {
                        throw new BrokenRandomEngineError('Failed to generate an acceptable random number in 50 attempts');
                    }
                } else {
                    $set[$r] = true;
                    $i = $i - 1;
                    $failures = 0;
                }
            }
            $out = [];
            for ($p = 0; $p < $avail; $p = $p + 1) {
                if (isset($set[$p]) !== $negative) { $out[] = $keys[$p]; }
            }

            return $out;
        }

        /** @return array<int,array<string,Engine>> */
        public function __serialize(): array
        {
            return [['engine' => $this->engine]];
        }

        /** @param array<int,mixed> $data */
        public function __unserialize(array $data): void
        {
            $bad = 'Invalid serialization data for Random\Randomizer object';
            if (\count($data) !== 1 || !isset($data[0]) || !\is_array($data[0])) {
                throw new \Exception($bad);
            }
            $e = $data[0]['engine'] ?? null;
            if (!($e instanceof Engine)) {
                throw new \Exception($bad);
            }
            $this->engine = $e;
            $this->bind($e);
        }
    }
}

namespace Random\Engine {

    final class Mt19937 implements \Random\Engine
    {
        /** @var int[] */
        private array $st = [];
        private int $cnt = 624;
        private int $mode = 0;

        public function __construct(?int $seed = null, int $mode = 0)
        {
            if ($mode !== 0 && $mode !== 1) {
                throw new \ValueError('Random\Engine\Mt19937::__construct(): Argument #2 ($mode) must be either MT_RAND_MT19937 or MT_RAND_PHP');
            }
            $this->mode = $mode;
            if ($seed === null) { $seed = \random_int(0, 0xFFFFFFFF); }
            $s = $seed & 0xFFFFFFFF;
            $st = [$s];
            $prev = $s;
            for ($i = 1; $i < 624; $i = $i + 1) {
                $prev = (1812433253 * ($prev ^ ($prev >> 30)) + $i) & 0xFFFFFFFF;
                $st[] = $prev;
            }
            $this->st = $st;
            $this->reload();
        }

        private function reload(): void
        {
            $st = $this->st;
            $php = $this->mode !== 0;
            for ($i = 0; $i < 624; $i = $i + 1) {
                $u = $st[$i];
                $v = $st[($i + 1) % 624];
                $y = ($u & 0x80000000) | ($v & 0x7FFFFFFF);
                $n = $st[($i + 397) % 624] ^ ($y >> 1);
                $lo = $php ? ($u & 1) : ($v & 1);
                if ($lo !== 0) { $n = $n ^ 0x9908B0DF; }
                $st[$i] = $n;
            }
            $this->st = $st;
            $this->cnt = 0;
        }

        /** @internal one tempered 32-bit word */
        public function __next(): int
        {
            if ($this->cnt >= 624) { $this->reload(); }
            $y = $this->st[$this->cnt];
            $this->cnt = $this->cnt + 1;
            $y = $y ^ ($y >> 11);
            $y = $y ^ (($y << 7) & 0x9D2C5680);
            $y = $y ^ (($y << 15) & 0xEFC60000);

            return ($y ^ ($y >> 18)) & 0xFFFFFFFF;
        }

        /** @internal */
        public function __mode(): int
        {
            return $this->mode;
        }

        public function generate(): string
        {
            return \Random\__word($this->__next(), 4);
        }

        /** @return mixed[] */
        private function stateList(): array
        {
            /** @var mixed[] $s */
            $s = [];
            foreach ($this->st as $w) { $s[] = \Random\__hexle($w, 4); }
            $s[] = $this->cnt;
            $s[] = $this->mode;

            return $s;
        }

        /** @return array<int,mixed> */
        public function __serialize(): array
        {
            return [[], $this->stateList()];
        }

        /** @param array<int,mixed> $data */
        public function __unserialize(array $data): void
        {
            $bad = 'Invalid serialization data for Random\Engine\Mt19937 object';
            if (\count($data) !== 2 || !isset($data[0]) || !\is_array($data[0]) || !isset($data[1]) || !\is_array($data[1])) {
                throw new \Exception($bad);
            }
            $d = $data[1];
            if (\count($d) !== 626) { throw new \Exception($bad); }
            $st = [];
            for ($i = 0; $i < 624; $i = $i + 1) {
                $w = \Random\__unhexle($d[$i] ?? null, 4);
                if ($w === null) { throw new \Exception($bad); }
                $st[] = $w;
            }
            $cnt = $d[624] ?? null;
            $mode = $d[625] ?? null;
            if (!\is_int($cnt) || $cnt > 624 || $cnt < 0 || !\is_int($mode) || ($mode !== 0 && $mode !== 1)) {
                throw new \Exception($bad);
            }
            $this->st = $st;
            $this->cnt = $cnt;
            $this->mode = $mode;
        }

        /** @return array<string,mixed> */
        public function __debugInfo(): array
        {
            return ['__states' => $this->stateList()];
        }
    }

    final class Xoshiro256StarStar implements \Random\Engine
    {
        private int $s0 = 0;
        private int $s1 = 0;
        private int $s2 = 0;
        private int $s3 = 0;

        public function __construct(string|int|null $seed = null)
        {
            if ($seed === null) {
                do {
                    $b = \random_bytes(32);
                    $a0 = \Random\__le64($b, 0); $a1 = \Random\__le64($b, 8);
                    $a2 = \Random\__le64($b, 16); $a3 = \Random\__le64($b, 24);
                } while ($a0 === 0 && $a1 === 0 && $a2 === 0 && $a3 === 0);
                $this->s0 = $a0; $this->s1 = $a1; $this->s2 = $a2; $this->s3 = $a3;
            } elseif (\is_string($seed)) {
                if (\strlen($seed) !== 32) {
                    throw new \ValueError('Random\Engine\Xoshiro256StarStar::__construct(): Argument #1 ($seed) must be a 32 byte (256 bit) string');
                }
                $a0 = \Random\__le64($seed, 0); $a1 = \Random\__le64($seed, 8);
                $a2 = \Random\__le64($seed, 16); $a3 = \Random\__le64($seed, 24);
                if ($a0 === 0 && $a1 === 0 && $a2 === 0 && $a3 === 0) {
                    throw new \ValueError('Random\Engine\Xoshiro256StarStar::__construct(): Argument #1 ($seed) must not consist entirely of NUL bytes');
                }
                $this->s0 = $a0; $this->s1 = $a1; $this->s2 = $a2; $this->s3 = $a3;
            } else {
                $this->s0 = $this->splitmix($seed);
                $this->s1 = $this->splitmix($seed);
                $this->s2 = $this->splitmix($seed);
                $this->s3 = $this->splitmix($seed);
            }
        }

        /** splitmix64 step; advances $seed */
        private function splitmix(int &$seed): int
        {
            $seed = \Random\__add64($seed, \Random\__c64(0x9E3779B9, 0x7F4A7C15));
            $r = $seed;
            $r = \Random\__mul64($r ^ \Random\__ushr($r, 30), \Random\__c64(0xBF58476D, 0x1CE4E5B9));
            $r = \Random\__mul64($r ^ \Random\__ushr($r, 27), \Random\__c64(0x94D049BB, 0x133111EB));

            return $r ^ \Random\__ushr($r, 31);
        }

        /** @internal one 64-bit word */
        public function __next(): int
        {
            $x = \Random\__add64($this->s1 << 2, $this->s1);
            $x = \Random\__rotl($x, 7);
            $r = \Random\__add64($x << 3, $x);
            $t = $this->s1 << 17;
            $this->s2 = $this->s2 ^ $this->s0;
            $this->s3 = $this->s3 ^ $this->s1;
            $this->s1 = $this->s1 ^ $this->s2;
            $this->s0 = $this->s0 ^ $this->s3;
            $this->s2 = $this->s2 ^ $t;
            $this->s3 = \Random\__rotl($this->s3, 45);

            return $r;
        }

        public function generate(): string
        {
            return \Random\__word($this->__next(), 8);
        }

        /** @param int[] $jmp */
        private function jumpBy(array $jmp): void
        {
            $a0 = 0; $a1 = 0; $a2 = 0; $a3 = 0;
            for ($i = 0; $i < 4; $i = $i + 1) {
                for ($j = 0; $j < 64; $j = $j + 1) {
                    if (($jmp[$i] & (1 << $j)) !== 0) {
                        $a0 = $a0 ^ $this->s0; $a1 = $a1 ^ $this->s1;
                        $a2 = $a2 ^ $this->s2; $a3 = $a3 ^ $this->s3;
                    }
                    $this->__next();
                }
            }
            $this->s0 = $a0; $this->s1 = $a1; $this->s2 = $a2; $this->s3 = $a3;
        }

        public function jump(): void
        {
            $this->jumpBy([
                \Random\__c64(0x180EC6D3, 0x3CFD0ABA), \Random\__c64(0xD5A61266, 0xF0C9392C),
                \Random\__c64(0xA9582618, 0xE03FC9AA), \Random\__c64(0x39ABDC45, 0x29B1661C),
            ]);
        }

        public function jumpLong(): void
        {
            $this->jumpBy([
                \Random\__c64(0x76E15D3E, 0xFEFDCBBF), \Random\__c64(0xC5004E44, 0x1C522FB3),
                \Random\__c64(0x77710069, 0x854EE241), \Random\__c64(0x39109BB0, 0x2ACBE635),
            ]);
        }

        /** @return string[] */
        private function stateList(): array
        {
            return [
                \Random\__hexle($this->s0, 8), \Random\__hexle($this->s1, 8),
                \Random\__hexle($this->s2, 8), \Random\__hexle($this->s3, 8),
            ];
        }

        /** @return array<int,mixed> */
        public function __serialize(): array
        {
            return [[], $this->stateList()];
        }

        /** @param array<int,mixed> $data */
        public function __unserialize(array $data): void
        {
            $bad = 'Invalid serialization data for Random\Engine\Xoshiro256StarStar object';
            if (\count($data) !== 2 || !isset($data[0]) || !\is_array($data[0]) || !isset($data[1]) || !\is_array($data[1])) {
                throw new \Exception($bad);
            }
            $d = $data[1];
            if (\count($d) !== 4) { throw new \Exception($bad); }
            $w = [];
            for ($i = 0; $i < 4; $i = $i + 1) {
                $v = \Random\__unhexle($d[$i] ?? null, 8);
                if ($v === null) { throw new \Exception($bad); }
                $w[] = $v;
            }
            if ($w[0] === 0 && $w[1] === 0 && $w[2] === 0 && $w[3] === 0) { throw new \Exception($bad); }
            $this->s0 = $w[0]; $this->s1 = $w[1]; $this->s2 = $w[2]; $this->s3 = $w[3];
        }

        /** @return array<string,mixed> */
        public function __debugInfo(): array
        {
            return ['__states' => $this->stateList()];
        }
    }

    final class PcgOneseq128XslRr64 implements \Random\Engine
    {
        private int $hi = 0;
        private int $lo = 0;

        public function __construct(string|int|null $seed = null)
        {
            if ($seed === null) {
                $b = \random_bytes(16);
                $this->seed128(\Random\__le64($b, 8), \Random\__le64($b, 0));
            } elseif (\is_string($seed)) {
                if (\strlen($seed) !== 16) {
                    throw new \ValueError('Random\Engine\PcgOneseq128XslRr64::__construct(): Argument #1 ($seed) must be a 16 byte (128 bit) string');
                }
                $this->seed128(\Random\__le64($seed, 0), \Random\__le64($seed, 8));
            } else {
                $this->seed128(0, $seed);
            }
        }

        private function seed128(int $sh, int $sl): void
        {
            $this->hi = 0;
            $this->lo = 0;
            $this->step();
            $r = \Random\__add128($this->hi, $this->lo, $sh, $sl);
            $this->hi = $r[0];
            $this->lo = $r[1];
            $this->step();
        }

        private function step(): void
        {
            $m = \Random\__mul128($this->hi, $this->lo, 2549297995355413924, 4865540595714422341);
            $m = \Random\__add128($m[0], $m[1], 6364136223846793005, 1442695040888963407);
            $this->hi = $m[0];
            $this->lo = $m[1];
        }

        /** @internal one 64-bit word */
        public function __next(): int
        {
            $this->step();
            $v = $this->hi ^ $this->lo;
            $s = \Random\__ushr($this->hi, 58);
            if ($s === 0) { return $v; }

            return \Random\__ushr($v, $s) | ($v << (64 - $s));
        }

        public function generate(): string
        {
            return \Random\__word($this->__next(), 8);
        }

        public function jump(int $advance): void
        {
            if ($advance < 0) {
                throw new \ValueError('Random\Engine\PcgOneseq128XslRr64::jump(): Argument #1 ($advance) must be greater than or equal to 0');
            }
            $cmh = 2549297995355413924; $cml = 4865540595714422341;
            $cph = 6364136223846793005; $cpl = 1442695040888963407;
            $amh = 0; $aml = 1; $aph = 0; $apl = 0;
            while ($advance > 0) {
                if (($advance & 1) !== 0) {
                    $t = \Random\__mul128($amh, $aml, $cmh, $cml);
                    $amh = $t[0]; $aml = $t[1];
                    $t = \Random\__mul128($aph, $apl, $cmh, $cml);
                    $t = \Random\__add128($t[0], $t[1], $cph, $cpl);
                    $aph = $t[0]; $apl = $t[1];
                }
                $t = \Random\__add128($cmh, $cml, 0, 1);
                $t = \Random\__mul128($t[0], $t[1], $cph, $cpl);
                $cph = $t[0]; $cpl = $t[1];
                $t = \Random\__mul128($cmh, $cml, $cmh, $cml);
                $cmh = $t[0]; $cml = $t[1];
                $advance = $advance >> 1;
            }
            $t = \Random\__mul128($amh, $aml, $this->hi, $this->lo);
            $t = \Random\__add128($t[0], $t[1], $aph, $apl);
            $this->hi = $t[0];
            $this->lo = $t[1];
        }

        /** @return string[] */
        private function stateList(): array
        {
            return [\Random\__hexle($this->hi, 8), \Random\__hexle($this->lo, 8)];
        }

        /** @return array<int,mixed> */
        public function __serialize(): array
        {
            return [[], $this->stateList()];
        }

        /** @param array<int,mixed> $data */
        public function __unserialize(array $data): void
        {
            $bad = 'Invalid serialization data for Random\Engine\PcgOneseq128XslRr64 object';
            if (\count($data) !== 2 || !isset($data[0]) || !\is_array($data[0]) || !isset($data[1]) || !\is_array($data[1])) {
                throw new \Exception($bad);
            }
            $d = $data[1];
            if (\count($d) !== 2) { throw new \Exception($bad); }
            $h = \Random\__unhexle($d[0] ?? null, 8);
            $l = \Random\__unhexle($d[1] ?? null, 8);
            if ($h === null || $l === null) { throw new \Exception($bad); }
            $this->hi = $h;
            $this->lo = $l;
        }

        /** @return array<string,mixed> */
        public function __debugInfo(): array
        {
            return ['__states' => $this->stateList()];
        }
    }

    final class Secure implements \Random\CryptoSafeEngine
    {
        /** @internal */
        public function __next(): int
        {
            return \Random\__le64(\random_bytes(8), 0);
        }

        public function generate(): string
        {
            return \random_bytes(8);
        }

        /** @return array<int,mixed> */
        public function __serialize(): array
        {
            throw new \Exception("Serialization of 'Random\\Engine\\Secure' is not allowed");
        }
    }
}
