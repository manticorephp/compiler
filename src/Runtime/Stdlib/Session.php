<?php

/**
 * The `files` session store, and session-id generation.
 *
 * The scalar half of the session extension. Everything here takes and returns
 * ints and strings only — no array, no object, no callable — which is exactly
 * what lets it live in the stdlib .o while the extension itself (the handler
 * objects, $_SESSION, the encoders that call serialize()) stays in the prelude.
 * The same split datetime.php ↔ TzInfo.php uses.
 *
 * On-disk format is php's: one file per session named `sess_<id>` under the save
 * path, holding the encoded session data verbatim.
 */

/** php's alphabet for a given session.sid_bits_per_character. */
function __mc_sess_alphabet(int $bits): string
{
    if ($bits === 5) {
        return '0123456789abcdefghijklmnopqrstuv';
    }
    if ($bits === 6) {
        return '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ,-';
    }
    return '0123456789abcdef';
}

/**
 * A fresh session id: $len characters drawn from the $bits-wide alphabet.
 * Entropy comes from random_bytes, so an id is unguessable — the property the
 * whole mechanism rests on.
 */
function __mc_sess_new_id(int $len, int $bits): string
{
    if ($len < 22) {
        $len = 22;
    }
    if ($len > 256) {
        $len = 256;
    }
    $alpha = \__mc_sess_alphabet($bits);
    $mask = \strlen($alpha) - 1;
    $raw = \random_bytes($len);
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alpha[\ord($raw[$i]) & $mask];
    }
    return $out;
}

/**
 * Whether $id is a well-formed session id.
 *
 * php's charset, not the sid_bits_per_character alphabet: an id may arrive from
 * a client or from session_id(), and php accepts A-Z a-z 0-9 "," "-" whatever
 * the generator was configured to emit. Checking against the generator alphabet
 * instead would reject the ids php itself accepts.
 */
function __mc_sess_valid_id(string $id): bool
{
    $n = \strlen($id);
    if ($n < 1 || $n > 256) {
        return false;
    }
    for ($i = 0; $i < $n; $i++) {
        $c = $id[$i];
        if ($c >= '0' && $c <= '9') { continue; }
        if ($c >= 'a' && $c <= 'z') { continue; }
        if ($c >= 'A' && $c <= 'Z') { continue; }
        if ($c === ',' || $c === '-') { continue; }
        return false;
    }
    return true;
}

/** The directory sessions live in: the configured path, or the system temp dir. */
function __mc_sess_path(string $configured): string
{
    if ($configured !== '') {
        return \rtrim($configured, '/');
    }
    return \rtrim(\sys_get_temp_dir(), '/');
}

/** The file backing one session. */
function __mc_sess_file(string $path, string $id): string
{
    return \__mc_sess_path($path) . '/sess_' . $id;
}

/** Whether a session record exists — what use_strict_mode asks before accepting an id. */
function __mc_sess_exists(string $path, string $id): bool
{
    return \file_exists(\__mc_sess_file($path, $id));
}

/** The encoded data of one session, or '' when there is no record. */
function __mc_sess_read(string $path, string $id): string
{
    $f = \__mc_sess_file($path, $id);
    if (!\file_exists($f)) {
        return '';
    }
    $h = \fopen($f, 'rb');
    if ($h === false) {
        return '';
    }
    \flock($h, \LOCK_SH);
    $data = '';
    while (!\feof($h)) {
        $chunk = \fread($h, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    \flock($h, \LOCK_UN);
    \fclose($h);
    return $data;
}

/**
 * Replace one session's record.
 *
 * ⚠ The exclusive lock is held for the WRITE, not from read to write: keeping it
 * open across the request would mean parking a file handle in a stdlib static,
 * and only scalars survive there. Two requests writing the same session
 * therefore last-writer-wins rather than serialising, which is the one place
 * this store is weaker than php's.
 */
function __mc_sess_write(string $path, string $id, string $data): bool
{
    $f = \__mc_sess_file($path, $id);
    // 'wb', not php's 'cb': this runtime's fopen is libc's, which knows no 'c'
    // mode. The record is replaced wholesale either way — the handler's contract
    // is "these bytes are the session now" — so the truncate-on-open is right.
    $h = \fopen($f, 'wb');
    if ($h === false) {
        return false;
    }
    \flock($h, \LOCK_EX);
    $n = \fwrite($h, $data);
    \fflush($h);
    \flock($h, \LOCK_UN);
    \fclose($h);
    return $n !== false;
}

/** Drop one session's record. Missing is success — the record is gone either way. */
function __mc_sess_destroy(string $path, string $id): bool
{
    $f = \__mc_sess_file($path, $id);
    if (!\file_exists($f)) {
        return true;
    }
    return \unlink($f);
}

/**
 * The session files under $dir.
 *
 * Its own function, and DECLARED `array<int, string>`, because glob() answers
 * `array|false`: unioning the two erases the element type, and the caller then
 * reads each path as a raw pointer. The declared return re-establishes it.
 *
 * @return array<int,string>
 */
function __mc_sess_list(string $dir): array<int, string>
{
    $g = \glob($dir . '/sess_*');
    if ($g === false) {
        return [];
    }
    return $g;
}

/** Delete every record older than $maxlifetime seconds; answers how many went. */
function __mc_sess_gc(string $path, int $maxlifetime): int
{
    $dir = \__mc_sess_path($path);
    $files = \__mc_sess_list($dir);
    $cut = \time() - $maxlifetime;
    $gone = 0;
    foreach ($files as $f) {
        $m = \filemtime($f);
        if ($m === false || $m > $cut) {
            continue;
        }
        if (\unlink($f)) {
            $gone = $gone + 1;
        }
    }
    return $gone;
}
