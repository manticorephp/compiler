<?php
final class N {
    public static $ASCII = "\x20\x65\x69\x61\x73\x6E\x74\x72\x6F\x6C\x75\x64\x5D\x5B\x63\x6D\x70\x27\x0A\x67\x7C\x68\x76\x2E\x66\x62\x2C\x3A\x3D\x2D\x71\x31\x30\x43\x32\x2A\x79\x78\x29\x28\x4C\x39\x41\x53\x2F\x50\x22\x45\x6A\x4D\x49\x6B\x33\x3E\x35\x54\x3C\x44\x34\x7D\x42\x7B\x38\x46\x77\x52\x36\x37\x55\x47\x4E\x3B\x4A\x7A\x56\x23\x48\x4F\x57\x5F\x26\x21\x4B\x3F\x58\x51\x25\x59\x5C\x09\x5A\x2B\x7E\x5E\x24\x40\x60\x7F\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F";
    public static $D = ["\u{E9}" => "e\u{301}", "\u{F6}" => "o\u{308}"];
    public static $cC = ["\u{301}" => 230, "\u{308}" => 230];
    public static $KD = [];
    public static $ulenMask = ["\xC0" => 2, "\xD0" => 2, "\xE0" => 3, "\xF0" => 4];
    public static function decompose($s, $c)
    {
        $result = '';
        $ASCII = self::$ASCII; $decompMap = self::$D; $combClass = self::$cC; $ulenMask = self::$ulenMask;
        if ($c) { $compatMap = self::$KD; }
        $c = []; $i = 0; $len = \strlen($s); $guard = 0;
        while ($i < $len) {
            if (++$guard > 1000) { return "LOOP i=$i len=$len"; }
            if ($s[$i] < "\x80") {
                if ($c) { ksort($c); $result .= implode('', $c); $c = []; }
                $j = 1 + strspn($s, $ASCII, $i + 1);
                $result .= substr($s, $i, $j);
                $i += $j;
                continue;
            }
            $ulen = $ulenMask[$s[$i] & "\xF0"];
            $uchr = substr($s, $i, $ulen);
            $i += $ulen;
            if ($uchr < "\xEA\xB0\x80" || "\xED\x9E\xA3" < $uchr) {
                if ($uchr !== $j = $compatMap[$uchr] ?? ($decompMap[$uchr] ?? $uchr)) {
                    $uchr = $j;
                    $j = \strlen($uchr);
                    $ulen = $uchr[0] < "\x80" ? 1 : $ulenMask[$uchr[0] & "\xF0"];
                    if ($ulen != $j) {
                        $j -= $ulen; $i -= $j;
                        if (0 > $i) { $s = str_repeat(' ', -$i).$s; $len -= $i; $i = 0; }
                        while ($j--) { $s[$i + $j] = $uchr[$ulen + $j]; }
                        $uchr = substr($uchr, 0, $ulen);
                    }
                }
                if (isset($combClass[$uchr])) {
                    if (!isset($c[$combClass[$uchr]])) { $c[$combClass[$uchr]] = ''; }
                    $c[$combClass[$uchr]] .= $uchr;
                    continue;
                }
            }
            if ($c) { ksort($c); $result .= implode('', $c); $c = []; }
            $result .= $uchr;
        }
        if ($c) { ksort($c); $result .= implode('', $c); }
        return $result;
    }
}
foreach (["abc", "h\u{E9}llo w\u{F6}rld", "x\u{E9}"] as $s) { echo bin2hex(N::decompose($s, false)), "\n"; }
