<?php
// Fake hostile MySQL server (Zend php), the mysql half of server.php. usage: server.php <mode> <portfile> mysql
// Serves ONE connection, then waits until the client hangs up (or 6 s).
[, $mode, $portFile] = $argv;
$mode = preg_replace('/^us_/', '', $mode);   // us_<mode>: same server behaviour, the client streams
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
$hs = function (string $plugin = 'mysql_native_password', int $alen = 21, int $proto = 10, int $caps = 0xA20D) use ($pkt): string {
    $scr = str_repeat('a', 20);
    return $pkt(0, chr($proto) . "8.4.0-fake\0" . pack('V', 7) . substr($scr, 0, 8) . "\0" . pack('v', $caps) . chr(255) . pack('v', 2) . pack('v', 0x002E)
        . chr($alen) . str_repeat("\0", 10) . substr($scr, 8) . "\0" . $plugin . "\0");
};
$quit = function () use ($c) { $end = microtime(true) + 6; stream_set_timeout($c, 1); while (microtime(true) < $end) { $x = fread($c, 1024); if ($x === '' && feof($c)) { break; } } exit; };

// kill_stall: the client's query stalls (ATTR_TIMEOUT 1), its cancel hook connects again and sends KILL QUERY, which stalls too.
// Every accepted connection is counted in <portfile>.conns: a third one would mean the KILL connection cancelled itself.
if ($mode === 'kill_stall') {
    $held = [$c];
    $n = 1;
    file_put_contents($portFile . '.conns', (string) $n);
    $end = microtime(true) + 7;
    while (true) {
        $cc = $held[$n - 1];
        fwrite($cc, $hs());
        $s0 = fread($cc, 65536);                       // HandshakeResponse41
        fwrite($cc, $ok(2));
        stream_set_blocking($cc, false);
        do {
            $r = [$srv]; $w = null; $e = null;
            if (stream_select($r, $w, $e, 0, 200000) > 0) { $nc = stream_socket_accept($srv, 1); $held[] = $nc; $n++; file_put_contents($portFile . '.conns', (string) $n); continue 2; }
        } while (microtime(true) < $end);
        exit;
    }
}
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
// ── TLS modes: the client wants TLS. ssl_nocap: the server has no CLIENT_SSL; the rest advertise it (0x800) ──
if ($mode === 'ssl_nocap') { fwrite($c, $hs()); $quit(); }
if (str_starts_with($mode, 'ssl_')) {
    $sslHs = $hs('mysql_native_password', 21, 10, 0xA20D | 0x800);
    // plaintext in the SAME segment as the handshake: the client must refuse to start TLS over it
    if ($mode === 'ssl_extra') { fwrite($c, $sslHs . $ok(2)); $quit(); }
    fwrite($c, $sslHs);
    [$s, $p] = $recv();                                       // the 32-byte SSLRequest, sequence 1
    if ($s !== 1 || strlen($p) !== 32) { fwrite($c, $err(2, 1043, '08S01', 'Bad handshake')); $quit(); }
    if ($mode === 'ssl_garbage') { usleep(200000); fwrite($c, "HTTP/1.1 400 Bad Request\r\n\r\n"); }
    elseif ($mode === 'ssl_close') { usleep(200000); fclose($c); exit; }
    elseif ($mode === 'ssl_drip') { usleep(200000); fwrite($c, "\x16\x03\x03\x01\x00"); for ($i = 0; $i < 30; $i++) { usleep(400000); if (@fwrite($c, 'x') === false) { break; } } }
    $quit();                                                  // ssl_silent: nothing more
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
if ($mode === 'switch_unknown') { fwrite($c, $pkt(2, "\xfeauth_nonesuch\0" . str_repeat('b', 20) . "\0")); $quit(); }
if ($mode === 'switch_short') { fwrite($c, $pkt(2, "\xfemysql_native_password\0" . str_repeat('b', 10) . "\0")); $quit(); }
if ($mode === 'switch_old') { fwrite($c, $pkt(2, "\xfe")); $quit(); }
if ($mode === 'switch_loop') { for ($i = 0; $i < 5; $i++) { fwrite($c, $pkt(2 + 2 * $i, "\xfemysql_native_password\0" . str_repeat('b', 20) . "\0")); $recv(); } $quit(); }
if ($mode === 'more_data_native') { fwrite($c, $pkt(2, "\x01\x03")); $quit(); }
if ($mode === 'eof_in_auth') { fclose($c); exit; }
fwrite($c, $ok(2));

// ── command-phase modes: every query is answered as the mode says; the client's NEXT query is a healthy `select 1` ──
$n = 0;
$closes = 0; $preps = 0; $execs = 0;
$prepOk = fn (int $seq, int $id, int $cols, int $params): string => $pkt($seq, "\x00" . pack('V', $id) . pack('v', $cols) . pack('v', $params) . "\x00" . pack('v', 0));
$binRs = fn (int $type, string $row): string => $pkt(1, "\x01") . $pkt(2, $coldef('a', $type)) . $eof(3) . $pkt(4, $row) . $eof(5);
while (true) {
    [$s, $p] = $recv();
    if ($s < 0 || $p === '' || $p[0] === "\x01") { break; }
    // ── binary-protocol modes (np_*): the client prepares `select ?` with ATTR_EMULATE_PREPARES=false ──
    if (str_starts_with($mode, 'np_')) {
        if ($p[0] === "\x19") { $closes++; continue; }
        if ($p[0] === "\x03") { fwrite($c, $rs(1, 253, [(string) $closes])); continue; }
        if ($p[0] === "\x16") {
            $preps++;
            if ($mode === 'np_err_prepare' && $preps === 1) { fwrite($c, $err(1, 1064, '42000', 'You have an error in your SQL syntax')); continue; }
            $ok = $prepOk(1, 1, 1, 1) . $pkt(2, $coldef('?')) . $eof(3) . $pkt(4, $coldef('a')) . $eof(5);
            if ($mode === 'np_cols_absurd') { $ok = $prepOk(1, 1, 60000, 1); }
            elseif ($mode === 'np_params_absurd') { $ok = $prepOk(1, 1, 0, 65535) . $pkt(2, $coldef('?')) . $eof(3); }
            elseif ($mode === 'np_trunc_ok') { $ok = $pkt(1, "\x00\x01\x00\x00\x00\x00"); }
            elseif ($mode === 'np_params_fewer') { $ok = $prepOk(1, 1, 1, 2) . $pkt(2, $coldef('?')) . $eof(3); }
            elseif ($mode === 'np_params_more') { $ok = $prepOk(1, 1, 1, 1) . $pkt(2, $coldef('?')) . $pkt(3, $coldef('?')) . $eof(4); }
            elseif ($mode === 'np_cols_more') { $ok = $prepOk(1, 1, 1, 1) . $pkt(2, $coldef('?')) . $eof(3) . $pkt(4, $coldef('a')) . $pkt(5, $coldef('b')) . $eof(6); }
            elseif ($mode === 'np_prep_long') { $ok = $pkt(1, "\x00\x01\x00\x00\x00\x01\x00\x01\x00\x00\x00\x00\xff"); }
            elseif ($mode === 'np_params_mismatch') { $ok = $prepOk(1, 1, 1, 2) . $pkt(2, $coldef('?')) . $pkt(3, $coldef('?')) . $eof(4) . $pkt(5, $coldef('a')) . $eof(6); }
            fwrite($c, $ok);
            continue;
        }
        if ($p[0] === "\x17") {
            $execs++;
            if ($mode === 'np_err_exec' && $execs === 1) { fwrite($c, $err(1, 1062, '23000', "Duplicate entry '1' for key 'PRIMARY'")); continue; }
            if ($mode === 'np_idle_slow' || $mode === 'np_idle_explicit') { sleep($mode === 'np_idle_slow' ? 6 : 9); fwrite($c, $binRs(253, "\x00\x00" . $ls('okay'))); continue; }
            if ($mode === 'np_stall_mid') { fwrite($c, substr($pkt(1, str_repeat('x', 100)), 0, 14)); sleep(9); continue; }
            if ($mode === 'np_row_overrun') { fwrite($c, $binRs(253, "\x00\x00\xfc\xe8\x03abc")); }
            elseif ($mode === 'np_bitmap_short') { fwrite($c, $binRs(253, "\x00")); }
            elseif ($mode === 'np_dt_len') { fwrite($c, $binRs(12, "\x00\x00\x05abcde")); }
            elseif ($mode === 'np_time_len') { fwrite($c, $binRs(11, "\x00\x00\x09abcdefghi")); }
            elseif ($mode === 'np_time_len3') { fwrite($c, $binRs(11, "\x00\x00\x03abc")); }
            elseif ($mode === 'np_dt_overrun') { fwrite($c, $binRs(12, "\x00\x00\x0babc")); }
            elseif ($mode === 'np_huge_lenenc') { fwrite($c, $binRs(253, "\x00\x00\xfe" . pack('P', 1 << 62))); }
            elseif ($mode === 'np_neg_lenenc') { fwrite($c, $binRs(253, "\x00\x00\xfe" . str_repeat("\xff", 8))); }
            elseif ($mode === 'np_int_short') { fwrite($c, $binRs(8, "\x00\x00abc")); }
            elseif ($mode === 'np_trailing') { fwrite($c, $binRs(253, "\x00\x00" . $ls('ok') . 'junk')); }
            elseif ($mode === 'np_hdr') { fwrite($c, $binRs(253, "\x01\x00" . $ls('ok'))); }
            elseif ($mode === 'np_unexpected') { fwrite($c, $pkt(1, "\xfe")); }
            elseif ($mode === 'np_badseq') { fwrite($c, $pkt(5, "\x01") . $pkt(6, $coldef('a', 253)) . $eof(7)); }
            elseif ($mode === 'np_colcount_absurd') { fwrite($c, $pkt(1, "\xfd\x40\x42\x0f")); }
            elseif ($mode === 'np_no_eof') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a', 253)) . $pkt(3, "\x00\x00" . $ls('ok'))); }
            elseif ($mode === 'np_float_odd') { fwrite($c, $binRs(5, "\x00\x00" . pack('e', 0.1) . 'x')); }
            elseif ($mode === 'np_nan') { fwrite($c, $binRs(5, "\x00\x00\x00\x00\x00\x00\x00\x00\xf8\x7f")); }
            elseif ($mode === 'np_year_short') { fwrite($c, $binRs(13, "\x00\x00\x07")); }
            else { fwrite($c, $binRs(253, "\x00\x00" . $ls('okay'))); }
            continue;
        }
        fwrite($c, $ok(1));
        continue;
    }
    if ($p[0] !== "\x03") { fwrite($c, $ok(1)); continue; }
    $n++;
    if ($n > 1) { fwrite($c, $rs(1, 253, ['ok'])); continue; }
    if ($mode === 'idle_slow' || $mode === 'idle_explicit') { sleep($mode === 'idle_slow' ? 6 : 9); fwrite($c, $rs(1, 253, ['ok'])); continue; }
    if ($mode === 'stall_mid') { fwrite($c, substr($pkt(1, str_repeat('x', 100)), 0, 14)); sleep(9); continue; }
    if ($mode === 'idle_between') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a'))); sleep(32); fwrite($c, $eof(3) . $pkt(4, "\x02ok") . $eof(5)); continue; }
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
    elseif ($mode === 'drain_slow') {
        // an endless result, one row per 20 ms: draining it would take as long as the server likes
        fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3));
        for ($i = 0; $i < 300; $i++) { usleep(20000); if (@fwrite($c, $pkt(4 + $i, $ls('x'))) === false) { break; } }
        exit;
    }
    elseif ($mode === 'rows_trunc') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3) . $pkt(4, $ls('x')) . substr($pkt(5, $ls('yyyy')), 0, 6)); fclose($c); exit; }
    elseif ($mode === 'rows_noeof') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3) . $pkt(4, $ls('x'))); fclose($c); exit; }
    elseif ($mode === 'rows_badseq') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3) . $pkt(4, $ls('x')) . $pkt(9, $ls('y')) . $eof(10)); }
    elseif ($mode === 'drain_bad') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3) . $pkt(4, $ls('x')) . $pkt(5, "\xfc\xe8\x03abc") . $eof(6)); }   // well framed, content nobody reads
    elseif ($mode === 'drain_dtor') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3) . $pkt(4, $ls('x')) . substr($pkt(5, $ls('yyyy')), 0, 6)); fclose($c); exit; }
    // more results promised, then a header that is not one: found by nextRowset() (more_bad_header) or by the drain of closeCursor() (drain_bad_next)
    elseif ($mode === 'more_bad_header' || $mode === 'drain_bad_next') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3) . $pkt(4, $ls('x')) . $eof(5, 2 | 8) . $pkt(6, "\xfd\x40\x42\x0f")); }
    elseif ($mode === 'more_after_eof') { fwrite($c, $pkt(1, "\x01") . $pkt(2, $coldef('a')) . $eof(3) . $pkt(4, $ls('x')) . $eof(5, 2 | 8)); fclose($c); exit; }
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
