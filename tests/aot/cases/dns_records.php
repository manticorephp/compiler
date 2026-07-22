<?php
// DNS resolver: structural invariants that hold with OR without a live network, so
// the offline suite stays deterministic. Live-record parity (MX/A/TXT byte-identical
// to php) is validated manually — a real query is non-deterministic, like TLS.
$hosts = []; $weights = [];

// dns_get_record always returns an array (empty on failure), never a warning.
var_dump(is_array(dns_get_record('example.com', DNS_A)));
var_dump(is_array(dns_get_record('example.com', DNS_MX)));

// A guaranteed-nonexistent name has no records either way -> false.
var_dump(checkdnsrr('no-such-host.invalid', 'MX'));
var_dump(getmxrr('no-such-host.invalid', $hosts, $weights));
var_dump($hosts);

// Type bitmask constants (php's own values).
var_dump(DNS_A, DNS_NS, DNS_CNAME, DNS_SOA, DNS_MX, DNS_TXT, DNS_AAAA, DNS_SRV, DNS_CAA, DNS_ANY);
echo "done\n";
