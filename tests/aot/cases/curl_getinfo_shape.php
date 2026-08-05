<?php

// curl_getinfo, against real php's.
//
// The values a transfer produces are timings and addresses that differ every
// run, so what is pinned here is the SHAPE: the key set, each key's type, and
// the handful of values a file:// transfer really does determine.
//
// ⚠ THIS BUILD CANNOT READ A C `double`. Every float-valued key libcurl exposes
// as CURLINFO_DOUBLE is fetched through its off_t sibling instead (microseconds
// for the timers, bytes for the sizes) and divided back down — which is why the
// float keys below still have to report `double`, not `integer`.

$tmp = \sys_get_temp_dir() . '/mc_curl_getinfo.txt';
$payload = \str_repeat("x", 4096);
\file_put_contents($tmp, $payload);

$ch = \curl_init('file://' . $tmp);
\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$body = \curl_exec($ch);

$info = \curl_getinfo($ch);

// ⚠ A CORE key list, not `array_keys($info)`. php builds its array from a list
// fixed when PHP ITSELF was compiled, guarded by the libcurl it was built
// against — so its key set legitimately differs from ours on a host whose
// libcurl is older or newer (Debian 12's 7.88 has no CURLINFO_HTTPAUTH_USED,
// which landed in 8.12). Dumping every key made this case a real difftest DIFF
// on Linux and a MATCH on macOS, which says nothing about ext/curl. These are
// the keys every libcurl in our supported range reports.
/** @var string[] $core */
$core = ['url', 'content_type', 'http_code', 'header_size', 'request_size',
         'filetime', 'ssl_verify_result', 'redirect_count', 'total_time',
         'namelookup_time', 'connect_time', 'pretransfer_time', 'size_upload',
         'size_download', 'speed_download', 'speed_upload',
         'download_content_length', 'upload_content_length',
         'starttransfer_time', 'redirect_time', 'redirect_url', 'primary_ip',
         'certinfo', 'primary_port', 'local_ip', 'local_port', 'http_version',
         'protocol', 'scheme'];
echo "core keys: ", \count($core), "\n";
foreach ($core as $k) {
    echo "  ", \str_pad($k, 26), \array_key_exists($k, $info) ? \gettype($info[$k]) : 'MISSING', "\n";
}

echo "-- determined values --\n";
echo "size_download:   ", (int) $info['size_download'], "\n";
echo "http_code:       ", $info['http_code'], "\n";
echo "certinfo:        ", \count($info['certinfo']), "\n";
// The temp directory differs per host (/tmp on Linux, /var/folders/... on
// macOS), so pin the SUFFIX rather than the path.
echo "url suffix:      ", \str_ends_with($info['url'], '/mc_curl_getinfo.txt') ? 'yes' : 'no', "\n";

echo "-- single-option reads --\n";
echo "SIZE_DOWNLOAD_T: ", \curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD_T), "\n";
echo "EFFECTIVE_URL:   ", \substr(\curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), 0, 7), "\n";
echo "HEADER_SIZE:     ", \curl_getinfo($ch, CURLINFO_HEADER_SIZE), "\n";
echo "CONTENT_TYPE:    ", \var_export(\curl_getinfo($ch, CURLINFO_CONTENT_TYPE), true), "\n";
echo "COOKIELIST:      ", \gettype(\curl_getinfo($ch, CURLINFO_COOKIELIST)), "\n";
// The DOUBLE class, routed through its _T sibling: a float, and non-negative.
$tt = \curl_getinfo($ch, CURLINFO_TOTAL_TIME);
echo "TOTAL_TIME:      ", \is_float($tt) ? 'double' : \gettype($tt), " >=0 ",
     $tt >= 0.0 ? 'yes' : 'no', "\n";
$sd = \curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
echo "SIZE_DOWNLOAD:   ", \is_float($sd) ? 'double' : \gettype($sd), " ", (int) $sd, "\n";

echo "body: ", \strlen($body) === \strlen($payload) ? 'match' : 'MISMATCH', "\n";

unset($ch);
\unlink($tmp);
