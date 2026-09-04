<?php

namespace Runtime\Stdlib;

use Manticore\Attr\RefOut;

// php builtin NAMES, kept in a namespace so the Zend cold seed can parse this
// file without redeclaring dns_get_record/checkdnsrr/getmxrr. User code reaches
// them through the emitter's bare-name resolution. The wire helpers they call
// live in the GLOBAL namespace — see DnsWire.php for why.
/**
 * php.net's dns_get_record: the resource records for $hostname of the requested
 * $type (a DNS_* bitmask). Returns an array of record arrays, or false on failure.
 * The by-ref authoritative/additional sections are not populated here (php fills
 * them from the reply's authority/additional; apps rarely read them).
 *
 * DNS_ANY / DNS_ALL fan out over the common types. A single DNS_* bit sends one
 * query. Unknown bits yield an empty result.
 * @return array<int,array<string,mixed>>|false
 */
function dns_get_record(string $hostname, int $type = 268435456, #[RefOut] array &$authoritative_name_servers = [], #[RefOut] array &$additional_records = [], bool $raw = false)
{
    $authoritative_name_servers = [];
    $additional_records = [];
    // The type set to query: an explicit single bit, or the common fan-out for
    // DNS_ANY (268435456) / DNS_ALL (268435455).
    /** @var int[] $bits */
    $bits = [];
    if ($type === 268435456 || $type === 268435455) {
        $bits = [1, 2, 16, 16384, 32768, 32, 134217728];   // A, NS, CNAME, MX, TXT, SOA, AAAA
    } else {
        $bits = [$type];
    }
    /** @var array<int,array<string,mixed>> $out */
    $out = [];
    foreach ($bits as $bit) {
        $wire = \__mc_dns_wire_type($bit);
        if ($wire === 0) {
            continue;
        }
        $msg = \__mc_dns_query($hostname, $wire);
        if ($msg === '') {
            continue;
        }
        $recs = \__mc_dns_parse($msg, $wire);
        foreach ($recs as $r) {
            $out[] = $r;
        }
    }
    return $out;
}

/**
 * php.net's checkdnsrr: whether any DNS record of $type exists for $hostname.
 * $type is a STRING ('A','MX','NS','SOA','PTR','CNAME','AAAA','SRV','TXT','CAA','ANY').
 */
function checkdnsrr(string $hostname, string $type = 'MX'): bool
{
    $t = \strtoupper($type);
    $wire = 0;
    if ($t === 'A') { $wire = 1; }
    elseif ($t === 'NS') { $wire = 2; }
    elseif ($t === 'CNAME') { $wire = 5; }
    elseif ($t === 'SOA') { $wire = 6; }
    elseif ($t === 'PTR') { $wire = 12; }
    elseif ($t === 'MX') { $wire = 15; }
    elseif ($t === 'TXT') { $wire = 16; }
    elseif ($t === 'AAAA') { $wire = 28; }
    elseif ($t === 'SRV') { $wire = 33; }
    elseif ($t === 'CAA') { $wire = 257; }
    elseif ($t === 'ANY') { $wire = 255; }
    if ($wire === 0) {
        return false;
    }
    $msg = \__mc_dns_query($hostname, $wire === 255 ? 1 : $wire);
    if ($msg === '') {
        return false;
    }
    // ANY: any answer counts; else count only matching-type answers.
    if ($wire === 255) {
        return \strlen($msg) >= 8 && \__mc_dns_u16($msg, 6) > 0;
    }
    return \count(\__mc_dns_parse($msg, $wire)) > 0;
}

/** Alias of checkdnsrr, php.net's dns_check_record. */
function dns_check_record(string $hostname, string $type = 'MX'): bool
{
    return checkdnsrr($hostname, $type);
}

/**
 * php.net's getmxrr: the MX records for $hostname into &$hosts (targets) and
 * &$weights (priorities). Returns true when at least one MX record was found.
 * @param string[] $hosts
 * @param int[] $weights
 */
function getmxrr(string $hostname, #[RefOut] array &$hosts, #[RefOut] array &$weights = []): bool
{
    $hosts = [];
    $weights = [];
    $msg = \__mc_dns_query($hostname, 15);
    if ($msg === '') {
        return false;
    }
    $recs = \__mc_dns_parse($msg, 15);
    foreach ($recs as $r) {
        $hosts[] = $r['target'];
        $weights[] = $r['pri'];
    }
    return \count($hosts) > 0;
}
