<?php

// resolv.conf's search list and ndots — the half of the file the resolver used to
// throw away.
//
// Only `nameserver` lines were read, so a short name (`db`, `redis`,
// `service.namespace`) had no way to be qualified: the async resolver could not
// answer it and the lookup fell through to the BLOCKING getaddrinfo walk, stalling
// the whole loop. Inside compose and kubernetes those names are the norm, which is
// why this was invisible on a laptop and unavoidable in a container.
//
// Every parser takes TEXT, so all of this runs offline: no network, no /etc, and no
// env var (the AOT runner injects none). The last leg goes through a real file to
// cover __mc_resolv_text's own path.
//
// ⚠ Nothing printed before the first __mc_* call: difftest treats a file as
// manticore-only when php produces NO stdout, and php has no __mc_resolv_search.

$multi = "# a comment\n; another\nnameserver 10.0.0.1\nnameserver fe80::1\n\tnameserver 10.0.0.2\nsearch a.example b.example\noptions edns0 ndots:2 timeout:1\n";
// ⚠ The first manticore-only call must come BEFORE any echo, or php prints that
// leading literal, difftest sees stdout and reclassifies the file from PHP-SKIP to
// a DIFF. `echo 'ns: ', __mc_resolv_nameservers(...)` flushed "ns: " and then died.
$ns = __mc_resolv_nameservers($multi);
echo 'ns: ', $ns, "\n";
echo 'search: ', __mc_resolv_search($multi), "\n";
echo 'ndots: ', __mc_resolv_ndots($multi), "\n";

// `domain` names exactly one suffix; `search` and `domain` share a slot and the LAST
// line wins, which is what makes a file carrying both unambiguous.
echo 'domain-only: ', __mc_resolv_search("domain corp.example\nnameserver 1.1.1.1\n"), "\n";
echo 'search-then-domain: ', __mc_resolv_search("search x.example y.example\ndomain corp.example\n"), "\n";
echo 'domain-then-search: ', __mc_resolv_search("domain corp.example\nsearch x.example y.example\n"), "\n";
echo 'not-a-search-line: [', __mc_resolv_search("searching for trouble\n"), "]\n";
echo 'trailing-dots: ', __mc_resolv_search("search A.Example. b.example.\n"), "\n";
echo 'capped: ', __mc_resolv_search("search s1 s2 s3 s4 s5 s6 s7 s8\n"), "\n";

// ndots: absent → 1, clamped to 0..15, last one wins.
echo 'ndots-default: ', __mc_resolv_ndots("nameserver 1.1.1.1\n"), "\n";
echo 'ndots-clamped: ', __mc_resolv_ndots("options ndots:99\n"), "\n";
echo 'ndots-last-wins: ', __mc_resolv_ndots("options ndots:3\noptions ndots:5\n"), "\n";
echo 'ndots-tab: ', __mc_resolv_ndots("options\tndots:4 rotate\n"), "\n";

// The ndots rule: fewer dots than ndots ⇒ the suffixes lead (a short name is almost
// certainly a cluster-local one); at least ndots ⇒ the bare name leads.
$search = 'a.example,b.example';
echo 'short: ', __mc_dns_candidates('web', $search, 1), "\n";
echo 'qualified: ', __mc_dns_candidates('a.b.c', $search, 1), "\n";
echo 'ndots2-short: ', __mc_dns_candidates('svc.ns', $search, 2), "\n";
echo 'rooted: ', __mc_dns_candidates('host.', $search, 1), "\n";
echo 'no-search: ', __mc_dns_candidates('web', '', 1), "\n";

// RCODE, hand-built: NOERROR / NXDOMAIN / too short. -1 is what stops a search walk
// (nobody answered), so it must not be confused with NXDOMAIN, which continues it.
echo 'rcode-noerror: ', __mc_dns_rcode("\x12\x34\x81\x80" . str_repeat("\x00", 8)), "\n";
echo 'rcode-nxdomain: ', __mc_dns_rcode("\x12\x34\x81\x83" . str_repeat("\x00", 8)), "\n";
echo 'rcode-short: ', __mc_dns_rcode("\x12"), "\n";

// ...and through a real file, which is all __mc_resolv_text adds.
$path = sys_get_temp_dir() . '/mc_resolv_conf_case';
file_put_contents($path, "nameserver 192.0.2.53\nsearch svc.cluster.local cluster.local\noptions ndots:5\n");
$text = __mc_resolv_text($path);
echo 'file-ns: ', __mc_resolv_nameservers($text), "\n";
echo 'file-search: ', __mc_resolv_search($text), "\n";
echo 'file-candidates: ', __mc_dns_candidates('redis', __mc_resolv_search($text), __mc_resolv_ndots($text)), "\n";
unlink($path);
echo 'missing-file: [', __mc_resolv_text($path . '.nope'), "]\n";

// The live file still parses to the shape __mc_dns_query explodes.
$live = __mc_dns_nameservers();
echo 'live-nonempty: ', ($live !== '' ? 'yes' : 'no'), "\n";
echo 'live-no-spaces: ', (strpos($live, ' ') === false ? 'yes' : 'no'), "\n";
echo "done\n";
