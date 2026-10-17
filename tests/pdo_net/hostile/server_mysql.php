<?php
// Fake hostile MySQL server (Zend php), the mysql half of server.php. usage: server.php <mode> <portfile> mysql
// Serves ONE connection, then waits until the client hangs up (or 6 s).
[, $mode, $portFile] = $argv;
$srv = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
file_put_contents($portFile . '.tmp', (string) parse_url('tcp://' . stream_socket_get_name($srv, false), PHP_URL_PORT));
rename($portFile . '.tmp', $portFile);
$c = stream_socket_accept($srv, 10);
$rd = function (int $n) use ($c): string { $b = ''; while (strlen($b) < $n) { $x = fread($c, $n - strlen($b)); if ($x === '' || $x === false) { return $b; } $b .= $x; } return $b; };
$pkt = fn (int $seq, string $body): string => substr(pack('V', strlen($body)), 0, 3) . chr($seq) . $body;
$recv = function () use ($rd): array { $h = $rd(4); if (strlen($h) < 4) { return [-1, '']; } $l = unpack('V', $h[0] . $h[1] . $h[2] . "\0")[1]; return [ord($h[3]), $rd($l)]; };
$ls = fn (string $s): string => (strlen($s) < 251 ? chr(strlen($s)) : (strlen($s) < 65536 ? "\xfc" . pack('v', strlen($s)) : "\xfd" . substr(pack('V', strlen($s)), 0, 3))) . $s;
$ok = fn (int $seq, int $status = 2): string => $pkt($seq, "\x00\x00\x00" . pack('v', $status) . "\0\0");
$eof = fn (int $seq, int $status = 2): string => $pkt($seq, "\xfe\x00\x00" . pack('v', $status));
$err = fn (int $seq, int $no, string $state, string $msg): string => $pkt($seq, "\xff" . pack('v', $no) . '#' . $state . $msg);
$coldef = fn (string $name, int $type = 253, int $flags = 0): string => $ls('def') . $ls('') . $ls('t') . $ls('t') . $ls($name) . $ls($name) . "\x0c" . pack('v', 45) . pack('V', 255) . chr($type) . pack('v', $flags) . "\0\0\0";
// a one-column result set: coldef(type), then rows
$rs = function (int $seq, int $type, array $vals, int $flags = 0) use ($pkt, $coldef, $eof, $ls): string {
    $o = $pkt($seq++, "\x01") . $pkt($seq++, $coldef('a', $type, $flags)) . $eof($seq++);
    foreach ($vals as $v) { $o .= $pkt($seq++, $v === null ? "\xfb" : $ls($v)); }
    return $o . $eof($seq);
};
$hs = function (string $plugin = 'mysql_native_password', int $alen = 21, int $proto = 10) use ($pkt): string {
    $scr = str_repeat('a', 20);
    return $pkt(0, chr($proto) . "8.4.0-fake\0" . pack('V', 7) . substr($scr, 0, 8) . "\0" . pack('v', 0xA20D) . chr(255) . pack('v', 2) . pack('v', 0x002E)
        . chr($alen) . str_repeat("\0", 10) . substr($scr, 8) . "\0" . $plugin . "\0");
};
$quit = function () use ($c) { $end = microtime(true) + 6; stream_set_timeout($c, 1); while (microtime(true) < $end) { $x = fread($c, 1024); if ($x === '' && feof($c)) { break; } } exit; };

// ── handshake-phase modes ────────────────────────────────────────────────
if ($mode === 'huge') { fwrite($c, "\xff\xff\xff\x00"); $quit(); }                       // a 16 MB handshake
if ($mode === 'badseq') { fwrite($c, $pkt(5, substr($hs(), 4))); $quit(); }
if ($mode === 'short_scramble') { fwrite($c, $hs('mysql_native_password', 10)); $quit(); }
if ($mode === 'proto9') { fwrite($c, $hs('mysql_native_password', 21, 9)); $quit(); }
if ($mode === 'unsolicited_plugin') { fwrite($c, $hs('foo_plugin')); $quit(); }
if ($mode === 'err_handshake') { fwrite($c, $err(0, 1040, '08004', 'Too many connections')); $quit(); }
if ($mode === 'drip') {
    // a length-100 packet fed one byte per 0.4 s: every read succeeds, the packet never completes
    fwrite($c, "\x64\x00\x00\x00");
    for ($i = 0; $i < 20; $i++) { usleep(400000); if (@fwrite($c, 'x') === false) { break; } }
    $quit();
}
fwrite($c, $hs(str_starts_with($mode, 'auth_') ? 'caching_sha2_password' : 'mysql_native_password'));
$recv();                                                  // HandshakeResponse41
// caching_sha2_password state machine: AuthMoreData (0x01) is only valid as 03 once, or 04 once followed by the key exchange
$more = fn (int $seq, string $d): string => $pkt($seq, "\x01" . $d);
if ($mode === 'auth_0105') { fwrite($c, $more(2, "\x05")); $quit(); }
if ($mode === 'auth_repeat03') { fwrite($c, $more(2, "\x03") . $more(3, "\x03")); $quit(); }
if ($mode === 'auth_nonpem' || $mode === 'auth_badkey' || $mode === 'auth_bigkey' || $mode === 'auth_double04') {
    fwrite($c, $more(2, "\x04"));
    $recv();                                              // the client's 0x02: public key request
    if ($mode === 'auth_nonpem') { fwrite($c, $more(4, 'this is not a PEM key at all, just text')); $quit(); }
    if ($mode === 'auth_badkey') { fwrite($c, $more(4, "-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----\n")); $quit(); }
    if ($mode === 'auth_bigkey') { fwrite($c, $more(4, "-----BEGIN PUBLIC KEY-----\n" . str_repeat('A', 9000))); $quit(); }
    $k = openssl_pkey_new(['private_key_bits' => 2048]);
    fwrite($c, $more(4, openssl_pkey_get_details($k)['key']));
    $recv();                                              // the encrypted password
    fwrite($c, $more(6, "\x04"));                          // a second 04
    $quit();
}
if ($mode === 'switch_unknown') { fwrite($c, $pkt(2, "\xfesha256_password\0" . str_repeat('b', 20) . "\0")); $quit(); }
if ($mode === 'switch_short') { fwrite($c, $pkt(2, "\xfemysql_native_password\0" . str_repeat('b', 10) . "\0")); $quit(); }
if ($mode === 'switch_old') { fwrite($c, $pkt(2, "\xfe")); $quit(); }
if ($mode === 'switch_loop') { for ($i = 0; $i < 5; $i++) { fwrite($c, $pkt(2 + 2 * $i, "\xfemysql_native_password\0" . str_repeat('b', 20) . "\0")); $recv(); } $quit(); }
if ($mode === 'more_data_native') { fwrite($c, $pkt(2, "\x01\x03")); $quit(); }
if ($mode === 'eof_in_auth') { fclose($c); exit; }
fwrite($c, $ok(2));

// ── command-phase modes: every query is answered as the mode says; the client's NEXT query is a healthy `select 1` ──
$n = 0;
while (true) {
    [$s, $p] = $recv();
    if ($s < 0 || $p === '' || $p[0] === "\x01") { break; }
    if ($p[0] !== "\x03") { fwrite($c, $ok(1)); continue; }
    $n++;
    if ($n > 1) { fwrite($c, $rs(1, 253, ['ok'])); continue; }
    if ($mode === 'idle_slow' || $mode === 'idle_explicit') { sleep($mode === 'idle_slow' ? 6 : 9); fwrite($c, $rs(1, 253, ['ok'])); continue; }
    if ($mode === 'stall_mid') { fwrite($c, substr($pkt(1, str_repeat('x', 100)), 0, 14)); sleep(9); continue; }
    if ($mode === 'row_huge_len') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3) . $pkt(4, "\xfe" . pack('P', 1 << 62)) . $eof(5)); continue; }
    if ($mode === 'row_neg_len') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3) . $pkt(4, "\xfe" . "\xff\xff\xff\xff\xff\xff\xff\xff") . $eof(5)); continue; }
    if ($mode === 'coldef_huge_len') { fwrite($c, $pkt(1, "\x01") . $pkt(2, "\xfe" . pack('P', 1 << 62) . 'xxxx')); continue; }
    if ($mode === 'trunc_coldef') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $ls('def') . $ls('') . chr(50) . 'xx')); }
    elseif ($mode === 'trunc_coldef_fixed') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $ls('def') . $ls('') . $ls('t') . $ls('t') . $ls('a') . $ls('a') . "\x0c" . pack('v', 45))); }
    elseif ($mode === 'row_overrun') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3) . $pkt(4, "\xfc\xe8\x03abc") . $eof(5)); }
    elseif ($mode === 'row_trailing') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3) . $pkt(4, $ls('x') . 'junk') . $eof(5)); }
    elseif ($mode === 'row_short') { fwrite($c, $pkt(1, "\x02") . $pkt(2, $coldef('a')) . $pkt(3, $coldef('b')) . $eof(4) . $pkt(5, $ls('x')) . $eof(6)); }
    elseif ($mode === 'colcount_absurd') { fwrite($c, $pkt(1, "\xfd\x40\x42\x0f")); }          // 999 999 columns
    elseif ($mode === 'colcount_zero_len') { fwrite($c, $pkt(1, "\xfc\x01\x00junk")); }
    elseif ($mode === 'no_eof') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $pkt(3, $ls('x'))); }
    elseif ($mode === 'local_infile') { fwrite($c, $pkt(1, "\xfb/etc/passwd")); }
    elseif ($mode === 'empty_pkt') { fwrite($c, $pkt(1, '')); }
    elseif ($mode === 'trunc_stream') { fwrite($c, $pkt(1, "\x01") . substr($pkt(2, $coldef('a')), 0, 10)); fclose($c); exit; }
    elseif ($mode === 'over_cap_query') { fwrite($c, "\xff\xff\xff\x01"); }
    elseif ($mode === 'err_mid_rows') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3) . $pkt(4, $ls('x')) . $err(5, 1317, '70100', 'Query execution was interrupted')); }
    elseif ($mode === 'bad_seq_query') { fwrite($c, $pkt(7, "\x00\x00\x00\x02\x00\x00\x00")); }
    elseif ($mode === 'more_forever') { fwrite($c, $ok(1, 2 | 8)); fclose($c); exit; }
    elseif ($mode === 'big_slow') {
        // one ~8 MB row streamed over ~3.6 s: far above the 64 KiB/s floor, but longer than 2 x ATTR_TIMEOUT
        $val = str_repeat('x', 8000000);
        $d = $pkt(4, $ls($val));
        fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3));
        foreach (str_split($d, 100000) as $ch) { fwrite($c, $ch); usleep(45000); }
        fwrite($c, $eof(5));
    }
    elseif ($mode === 'int_garbage') { fwrite($c, $rs(1, 3, ['abc'])); }
    elseif ($mode === 'int_empty') { fwrite($c, $rs(1, 3, [''])); }
    elseif ($mode === 'int_max') { fwrite($c, $rs(1, 8, ['9223372036854775807'])); }
    elseif ($mode === 'int_min') { fwrite($c, $rs(1, 8, ['-9223372036854775808'])); }
    elseif ($mode === 'int_overflow') { fwrite($c, $rs(1, 8, ['9223372036854775808'])); }
    elseif ($mode === 'int_underflow') { fwrite($c, $rs(1, 8, ['-9223372036854775809'])); }
    elseif ($mode === 'uint_max') { fwrite($c, $rs(1, 8, ['18446744073709551615'], 32)); }
    elseif ($mode === 'uint_over') { fwrite($c, $rs(1, 8, ['18446744073709551616'], 32)); }
    elseif ($mode === 'float_lead') { fwrite($c, $rs(1, 5, [' 5'])); }
    elseif ($mode === 'float_trail') { fwrite($c, $rs(1, 5, ['5 '])); }
    elseif ($mode === 'float_minus') { fwrite($c, $rs(1, 5, ['-'])); }
    elseif ($mode === 'float_plus') { fwrite($c, $rs(1, 5, ['+5'])); }
    elseif ($mode === 'bit_long') { fwrite($c, $rs(1, 16, ['123456789'])); }
    elseif ($mode === 'bit_max') { fwrite($c, $rs(1, 16, ["\xff\xff\xff\xff\xff\xff\xff\xff"])); }
}
$quit();
