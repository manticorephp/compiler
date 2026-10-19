<?php
// expects-connect-failure: every sslmode against a server with and without TLS, certificate checks, client certificates
// key-perms-refusals: 1
$dir = getenv('PDO_SSL_DIR');
// The oracle's libpq version shows in its wording (the driver speaks libpq 17's): libpq 16 added sslrootcert=system to the
// missing-root hint, libpq 17 quoted the option name of a bad protocol version. Fold both to one spelling so the case
// grades the driver, not the host's libpq.
function libpq_neutral(string $m): string
{
    $m = str_replace(', use the system\'s trusted roots with sslrootcert=system, or change', ' or change', $m);
    return preg_replace('/invalid "(ssl_m(?:in|ax)_protocol_version)" value/', 'invalid $1 value', $m);
}
$home = getenv('HOME');
$try = function (string $label, string $dsn, string $u = 'u', ?string $p = 'pw', array $o = []) use ($dir, $home) {
    try {
        $db = new PDO($dsn, $u, $p, $o);
        echo $label, ': ok ssl=', var_export($db->query('select ssl from pg_stat_ssl where pid = pg_backend_pid()')->fetchColumn(), true), "\n";
    } catch (PDOException $e) {
        $m = str_replace([$dir, $home], ['<certs>', '~'], $e->getMessage());
        $m = libpq_neutral(preg_replace('/for host "[0-9.]+"/', 'for host "<ip>"', $m));
        // sslrootcert=system is libpq 16+: an older libpq reads "system" as a file name, so only the failure itself is graded
        if (str_contains($label, 'root=system')) { $m = 'refused'; }
        echo $label, ': ', json_encode($m), ' ', $e->getCode(), "\n";
    }
};
foreach ([54320 => 'plain', 54323 => 'tls'] as $port => $n) {
    foreach (['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full', 'bogus', 'REQUIRE', ''] as $m) {
        $try("$n $m", "pgsql:host=127.0.0.1;port=$port;dbname=d;sslmode=$m;sslrootcert=/nonexistent");
    }
}
$tls = 'pgsql:host=127.0.0.1;port=54323;dbname=d';
$try('default no mode', $tls);
$try('verify-ca, no root file', "$tls;sslmode=verify-ca;sslrootcert=/nonexistent");
$try('verify-ca good', "$tls;sslmode=verify-ca;sslrootcert=$dir/ca.pem");
$try('verify-ca wrong root', "$tls;sslmode=verify-ca;sslrootcert=$dir/ca2.pem");
$try('verify-full good (ip san)', "$tls;sslmode=verify-full;sslrootcert=$dir/ca.pem");
$try('verify-full localhost', "pgsql:host=localhost;port=54323;dbname=d;sslmode=verify-full;sslrootcert=$dir/ca.pem");
$try('verify-full wrong host', "pgsql:host=wrong.example;hostaddr=127.0.0.1;port=54323;dbname=d;sslmode=verify-full;sslrootcert=$dir/ca.pem");
$try('verify-ca ignores host', "pgsql:host=wrong.example;hostaddr=127.0.0.1;port=54323;dbname=d;sslmode=verify-ca;sslrootcert=$dir/ca.pem");
$try('verify-full wrong root', "$tls;sslmode=verify-full;sslrootcert=$dir/ca2.pem");
$try('require, root file given verifies', "$tls;sslmode=require;sslrootcert=$dir/ca2.pem");
$try('require, root file ok', "$tls;sslmode=require;sslrootcert=$dir/ca.pem");
$try('prefer, wrong root', "$tls;sslmode=prefer;sslrootcert=$dir/ca2.pem");
$try('allow, wrong password', "$tls;sslmode=allow", 'u', 'bad');
$try('prefer, wrong password', "$tls;sslmode=prefer", 'u', 'bad');
$try('require, wrong password', "$tls;sslmode=require", 'u', 'bad');
$try('root file empty', "$tls;sslmode=verify-ca;sslrootcert=/dev/null");
$try('root is a directory', "$tls;sslmode=verify-ca;sslrootcert=$dir");
$try('root=system, verify-ca', "$tls;sslmode=verify-ca;sslrootcert=system");
$try('crl ok', "$tls;sslmode=verify-ca;sslrootcert=$dir/ca.pem;sslcrl=$dir/empty.crl");
$try('crl of another CA', "$tls;sslmode=verify-ca;sslrootcert=$dir/ca.pem;sslcrl=$dir/other.crl");
$try('crl missing file', "$tls;sslmode=verify-ca;sslrootcert=$dir/ca.pem;sslcrl=/nonexistent");
$cc = "$tls;sslmode=verify-full;sslrootcert=$dir/ca.pem";
$try('client cert', "$cc;sslcert=$dir/client.crt;sslkey=$dir/client.key", 'cu', null);
$try('client cert, password user', "$cc;sslcert=$dir/client.crt;sslkey=$dir/client.key");
$try('client cert required, none sent', $cc, 'cu', null);
$try('client cert file missing', "$cc;sslcert=/nonexistent;sslkey=$dir/client.key", 'cu', null);
$try('client key file missing', "$cc;sslcert=$dir/client.crt;sslkey=/nonexistent", 'cu', null);
$try('client key is world readable', "$cc;sslcert=$dir/client.crt;sslkey=$dir/ca.pem", 'cu', null);
$try('client cert is not a cert', "$cc;sslcert=$dir/client.key;sslkey=$dir/client.key", 'cu', null);
$try('client key does not match', "$cc;sslcert=$dir/ca.pem;sslkey=$dir/client.key", 'cu', null);
$try('cert without key', "$cc;sslcert=$dir/client.crt", 'cu', null);
$try('sslsni / sslcompression', "$tls;sslmode=require;sslsni=0;sslcompression=1");
$try('unknown option', "$tls;sslbogus=1");
// protocol range (libpq's ssl_min_protocol_version defaults to TLSv1.2)
$ver = function (string $label, string $extra) use ($tls) {
    try {
        $db = new PDO("$tls;sslmode=require;$extra", 'u', 'pw');
        echo $label, ': ', $db->query('select version from pg_stat_ssl where pid = pg_backend_pid()')->fetchColumn(), "\n";
    } catch (PDOException $e) { echo $label, ': ', json_encode(libpq_neutral($e->getMessage())), "\n"; }
};
$ver('min 1.2', 'ssl_min_protocol_version=TLSv1.2');
$ver('min 1.3', 'ssl_min_protocol_version=TLSv1.3');
$ver('max 1.2', 'ssl_max_protocol_version=TLSv1.2');
$ver('min 1.1', 'ssl_min_protocol_version=TLSv1.1');
$ver('bogus min', 'ssl_min_protocol_version=bogus');
$ver('bogus max', 'ssl_max_protocol_version=TLSv9');
$ver('empty range', 'ssl_min_protocol_version=TLSv1.3;ssl_max_protocol_version=TLSv1.2');
