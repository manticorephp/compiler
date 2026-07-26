<?php

// The async resolver's OFFLINE-testable parts. Everything here avoids the network:
// /etc/hosts, the TC-bit reader, and the scheduler-held cache (host → ip + expiry),
// which lives in the Scheduler precisely because a stdlib static cannot own an assoc.
//
// What is NOT covered offline (and is checked by hand / examples/async/tls_async_smoke):
// multi-nameserver failover, the TCP retry itself, and AAAA.
//
// Nothing prints before the first async(): php cannot run this (no Io\Poll), and
// difftest classifies a file as manticore-only by "php produced no stdout".

use function Async\async;
use function Async\spawn;

// /etc/hosts is the first resolver step — 'localhost' must resolve with no DNS at all.
$hosts = async(function (): string {
    $t = spawn(function (): string {
        $ip = __mc_hosts_lookup('localhost');
        return $ip === '' ? 'none' : ($ip === '127.0.0.1' ? 'loopback' : 'other');
    });
    return $t->await();
});
echo 'hosts: ', $hosts, "\n";

// A name nobody lists is a miss, not a wrong answer.
$missing = async(function (): string {
    $t = spawn(function (): string {
        return __mc_hosts_lookup('this-name-is-not-in-etc-hosts.invalid') === '' ? 'miss' : 'hit';
    });
    return $t->await();
});
echo 'unknown host: ', $missing, "\n";

// The TC (truncated) bit drives the TCP retry. Byte 2 bit 1 of the header.
$flagsNoTc = "\x12\x34\x81\x80" . str_repeat("\x00", 8);
$flagsTc   = "\x12\x34\x83\x80" . str_repeat("\x00", 8);
var_dump(__mc_dns_truncated($flagsNoTc));
var_dump(__mc_dns_truncated($flagsTc));
var_dump(__mc_dns_truncated('short'));

// The cache: store, hit, expire, miss. Reached directly here; the resolver goes
// through the netpoller hook.
$cache = async(function (): string {
    $t = spawn(function (): string {
        $s = \Async\Scheduler::instance();
        $s->dnsStore('cached.example', '10.1.2.3', 60);
        $hit = $s->dnsLookup('cached.example');
        $miss = $s->dnsLookup('never-stored.example');
        // A zero TTL expires immediately — the entry must not linger.
        $s->dnsStore('expired.example', '10.9.9.9', 0);
        $gone = $s->dnsLookup('expired.example');
        // An empty ip or host is not cacheable.
        $s->dnsStore('', '10.0.0.1', 60);
        $s->dnsStore('blank.example', '', 60);
        $blank = $s->dnsLookup('blank.example');
        return 'hit=' . $hit . ' miss=' . ($miss === '' ? 'empty' : $miss)
             . ' expired=' . ($gone === '' ? 'empty' : $gone)
             . ' blank=' . ($blank === '' ? 'empty' : $blank);
    });
    return $t->await();
});
echo $cache, "\n";

// The cache is per-run: a fresh async() starts with nothing, because the scheduler
// singleton (and the cache with it) is dropped when the previous run returned.
$fresh = async(function (): string {
    $t = spawn(function (): string {
        return \Async\Scheduler::instance()->dnsLookup('cached.example') === '' ? 'empty' : 'leaked';
    });
    return $t->await();
});
echo 'next run: ', $fresh, "\n";

// resolv.conf may list several servers; the value is machine-specific, so only its
// shape is asserted.
$ns = __mc_dns_nameservers();
var_dump($ns !== '');
var_dump(str_contains($ns, ' ') === false);
echo "done\n";
