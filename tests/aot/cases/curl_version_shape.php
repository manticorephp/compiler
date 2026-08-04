<?php

// curl_version(), against real php's.
//
// ⚠ SHAPE ONLY, never values. The binary links whatever libcurl the host ships
// (8.7.1 from the macOS SDK here) while the `php` this is compared against was
// built against its own (8.21.0 from Homebrew) — so every version string,
// version number and feature bit legitimately differs. What must agree is the
// key set, the types, and the invariants a caller actually branches on.
//
// This is the second and last libcurl struct the extension dereferences. It is
// safe to hard-code the offsets because `curl_version_info()` IGNORES the age it
// is passed and answers with the age THIS build supports, and the struct is only
// ever appended to — which is what the age-gated reads below rely on.

$v = \curl_version();

$keys = \array_keys($v);
\sort($keys);
echo "keys: ", \count($keys), "\n";
foreach ($keys as $k) {
    echo "  ", \str_pad($k, 20), \gettype($v[$k]), "\n";
}

echo "-- invariants --\n";
echo "age >= 4:        ", $v['age'] >= 4 ? 'yes' : 'no', "\n";
echo "version_number:  ", $v['version_number'] > 0 ? 'positive' : 'BAD', "\n";
echo "version dots:    ", \substr_count($v['version'], '.') === 2 ? 'yes' : 'no', "\n";
echo "host non-empty:  ", $v['host'] !== '' ? 'yes' : 'no', "\n";
echo "features > 0:    ", $v['features'] > 0 ? 'yes' : 'no', "\n";

$protocols = $v['protocols'];
echo "protocols > 4:   ", \count($protocols) > 4 ? 'yes' : 'no', "\n";
echo "has file:        ", \in_array('file', $protocols, true) ? 'yes' : 'no', "\n";
echo "has http:        ", \in_array('http', $protocols, true) ? 'yes' : 'no', "\n";
echo "has https:       ", \in_array('https', $protocols, true) ? 'yes' : 'no', "\n";

$fl = $v['feature_list'];
echo "feature_list:    ", \count($fl), "\n";
echo "  IPv6 is bool:  ", \is_bool($fl['IPv6']) ? 'yes' : 'no', "\n";
echo "  SSL is bool:   ", \is_bool($fl['SSL']) ? 'yes' : 'no', "\n";
// The bitmask and its spelled-out form must not disagree.
echo "  SSL agrees:    ", $fl['SSL'] === (($v['features'] & CURL_VERSION_SSL) !== 0) ? 'yes' : 'no', "\n";
echo "  IPv6 agrees:   ", $fl['IPv6'] === (($v['features'] & CURL_VERSION_IPV6) !== 0) ? 'yes' : 'no', "\n";
echo "  libz agrees:   ", $fl['libz'] === (($v['features'] & CURL_VERSION_LIBZ) !== 0) ? 'yes' : 'no', "\n";
