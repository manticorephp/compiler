<?php
// `$s[$i] = $c` on an untyped (cell) string: it took the array store path
// (SIGBUS). The polyfill-mbstring case-mapping loop, verbatim in shape.
final class Mb
{
    public static function lower($s)
    {
        static $lower = null;
        if (null === $lower) { $lower = ['A' => 'a', 'É' => 'é', 'Ω' => 'ω']; }
        $map = $lower;
        static $ulenMask = ["\xC0" => 2, "\xD0" => 2, "\xE0" => 3, "\xF0" => 4];
        $i = 0;
        $len = \strlen($s);
        $n = 0;
        while ($i < $len) {
            if (++$n > 100) { echo "LOOP i=$i len=$len\n"; break; }
            $ulen = $s[$i] < "\x80" ? 1 : $ulenMask[$s[$i] & "\xF0"];
            $uchr = substr($s, $i, $ulen);
            $i += $ulen;
            if (isset($map[$uchr])) {
                $uchr = $map[$uchr];
                $nlen = \strlen($uchr);
                if ($nlen == $ulen) {
                    $nlen = $i;
                    do {
                        $s[--$nlen] = $uchr[--$ulen];
                    } while ($ulen);
                } else {
                    $s = substr_replace($s, $uchr, $i - $ulen, $ulen);
                    $len += $nlen - $ulen;
                    $i += $nlen - $ulen;
                }
            }
        }
        return $s;
    }
}
echo Mb::lower('AbcÉxΩ'), "\n", Mb::lower('StringableForToString'), "\n";
function a($s) { $s[0] = "x"; return $s; }
echo a("Ab"), "\n";
function b(string $s) { $s[0] = "x"; return $s; }
echo b("Ab"), "\n";
$own = \str_repeat('ab', 2);
echo a($own), ' ', $own, "\n";
echo b($own), ' ', $own, "\n";
function c($v) { $v[1] = 'Z'; return $v; }
$arr = [1, 2, 3];
print_r(c($arr)); print_r($arr);
