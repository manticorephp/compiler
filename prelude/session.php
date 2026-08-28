// ext/session: $_SESSION, the session_* family, and the save-handler protocol.
// DEMAND-GATED (Main.php): only a program that calls one of these carries it.
//
// Lives in the prelude, and could not live anywhere else: it declares INTERFACES
// and a class (the .sig carries functions only), it holds a handler OBJECT and
// user CALLABLES (neither crosses the stdlib.o boundary), it reads and writes
// $_SESSION (a module cell no separately-linked .o can reach), and it calls
// serialize()/unserialize(), which are themselves prelude tiers. What CAN live in
// the stdlib is the scalar half — the files store and id generation — and it
// does: src/Runtime/Stdlib/Session.php, reached through __mc_sess_*.
//
// Configuration is read from ini and nowhere else: session.name, save_path,
// gc_*, cookie_*, sid_* are the single source of truth, so ini_get('session.x')
// and session_x() can never disagree. session_start() after output has begun
// fails, exactly as php's CLI fails it.

const PHP_SESSION_DISABLED = 0;
const PHP_SESSION_NONE = 1;
const PHP_SESSION_ACTIVE = 2;

interface SessionHandlerInterface
{
    public function open(string $path, string $name): bool;
    public function close(): bool;
    public function read(string $id): mixed;
    public function write(string $id, string $data): bool;
    public function destroy(string $id): bool;
    public function gc(int $max_lifetime): mixed;
}

interface SessionIdInterface
{
    public function create_sid(): string;
}

interface SessionUpdateTimestampHandlerInterface
{
    public function validateId(string $id): bool;
    public function updateTimestamp(string $id, string $data): bool;
}

/**
 * php's own `files` handler, exposed as a class so user code can extend it and
 * delegate — `class MyHandler extends SessionHandler` with a `read()` that calls
 * `parent::read()` is the documented way to wrap the built-in store.
 */
class SessionHandler implements SessionHandlerInterface, SessionIdInterface
{
    public string $path = '';

    public function open(string $path, string $name): bool
    {
        $this->path = $path;
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): mixed
    {
        return \__mc_sess_read($this->path, $id);
    }

    public function write(string $id, string $data): bool
    {
        return \__mc_sess_write($this->path, $id, $data);
    }

    public function destroy(string $id): bool
    {
        return \__mc_sess_destroy($this->path, $id);
    }

    public function gc(int $max_lifetime): mixed
    {
        return \__mc_sess_gc($this->path, $max_lifetime);
    }

    public function create_sid(): string
    {
        return \__mc_sess_new_id((int)\ini_get('session.sid_length'), (int)\ini_get('session.sid_bits_per_character'));
    }
}

class __McSession
{
    /** PHP_SESSION_NONE / _ACTIVE. DISABLED never happens: the extension is here. */
    public static int $status = 1;

    /** The current session id, '' when none has been chosen yet. */
    public static string $id = '';

    /** A user save handler object, or null for the built-in files store. */
    public static ?SessionHandlerInterface $handler = null;

    /** @var array<int,mixed> the callable form of session_set_save_handler */
    public static array $cbs = [];

    /** True when the callable form is in force. */
    public static bool $useCbs = false;

    /** The save path the handler was opened with. */
    public static string $openPath = '';

    /** What the store last handed back, so lazy_write can skip an unchanged write. */
    public static string $lastRead = '';

    /** True once the implicit end-of-request write-close is registered. */
    public static bool $shutdownRegistered = false;

    /** @var array<int,int> parked $status, by task id */
    public static array $savedStatus = [];

    /** @var array<int,string> parked $id, by task id */
    public static array $savedId = [];

    /** @var array<int,string> parked $lastRead, by task id */
    public static array $savedLastRead = [];

    /** @var array<int,bool> whether that task's session state was ever parked */
    public static array $seen = [];
}

/**
 * Park the per-request half of the session state alongside the SAPI context.
 *
 * Called from __mc_sapi_ctx_switch behind a function_exists guard, so sapi.php
 * carries no dependency on this file: a program with no session compiles the
 * guard away. The HANDLER is deliberately not parked — a save handler is
 * installed once for the process, exactly as php installs it.
 */
function __mc_session_ctx_switch(int $from, int $to): void
{
    \__McSession::$savedStatus[$from] = \__McSession::$status;
    \__McSession::$savedId[$from] = \__McSession::$id;
    \__McSession::$savedLastRead[$from] = \__McSession::$lastRead;
    \__McSession::$seen[$from] = true;
    if (!isset(\__McSession::$seen[$to])) {
        \__McSession::$status = 1;
        \__McSession::$id = '';
        \__McSession::$lastRead = '';
        return;
    }
    \__McSession::$status = \__McSession::$savedStatus[$to];
    \__McSession::$id = \__McSession::$savedId[$to];
    \__McSession::$lastRead = \__McSession::$savedLastRead[$to];
}

/** Invoke an array callable. Kept separate from the function/closure path so
 * the erased dynamic-method and erased invoke dispatches do not accumulate in
 * one megamorphic body. */
function __mc_sess_call_array(mixed $cb, string $a, string $b, int $argc): mixed
{
    $o = $cb[0];
    $m = $cb[1];
    if ($argc === 0) { return $o->$m(); }
    if ($argc === 1) { return $o->$m($a); }
    return $o->$m($a, $b);
}

/** Invoke a non-array callable. */
function __mc_sess_call_function(mixed $cb, string $a, string $b, int $argc): mixed
{
    if ($argc === 0) { return $cb(); }
    if ($argc === 1) { return $cb($a); }
    return $cb($a, $b);
}

/** Invoke a user handler callable, in either of the shapes php accepts. */
function __mc_sess_call(mixed $cb, string $a, string $b, int $argc): mixed
{
    if (\is_array($cb)) {
        return __mc_sess_call_array($cb, $a, $b, $argc);
    }
    return __mc_sess_call_function($cb, $a, $b, $argc);
}

/** The configured save path, resolved once per open. */
function __mc_sess_savepath(): string
{
    return \__mc_sess_path((string)\ini_get('session.save_path'));
}

function __mc_sess_h_open(string $path, string $name): bool
{
    if (\__McSession::$useCbs) {
        return (bool)\__mc_sess_call(\__McSession::$cbs[0], $path, $name, 2);
    }
    $h = \__McSession::$handler;
    if ($h !== null) {
        return $h->open($path, $name);
    }
    return true;
}

function __mc_sess_h_close(): bool
{
    if (\__McSession::$useCbs) {
        return (bool)\__mc_sess_call(\__McSession::$cbs[1], '', '', 0);
    }
    $h = \__McSession::$handler;
    if ($h !== null) {
        return $h->close();
    }
    return true;
}

function __mc_sess_h_read(string $id): string
{
    if (\__McSession::$useCbs) {
        $r = \__mc_sess_call(\__McSession::$cbs[2], $id, '', 1);
        return ($r === false || $r === null) ? '' : (string)$r;
    }
    $h = \__McSession::$handler;
    if ($h !== null) {
        $r = $h->read($id);
        return ($r === false || $r === null) ? '' : (string)$r;
    }
    return \__mc_sess_read(\__McSession::$openPath, $id);
}

function __mc_sess_h_write(string $id, string $data): bool
{
    if (\__McSession::$useCbs) {
        return (bool)\__mc_sess_call(\__McSession::$cbs[3], $id, $data, 2);
    }
    $h = \__McSession::$handler;
    if ($h !== null) {
        return $h->write($id, $data);
    }
    return \__mc_sess_write(\__McSession::$openPath, $id, $data);
}

function __mc_sess_h_destroy(string $id): bool
{
    if (\__McSession::$useCbs) {
        return (bool)\__mc_sess_call(\__McSession::$cbs[4], $id, '', 1);
    }
    $h = \__McSession::$handler;
    if ($h !== null) {
        return $h->destroy($id);
    }
    return \__mc_sess_destroy(\__McSession::$openPath, $id);
}

function __mc_sess_h_gc(int $max): int
{
    if (\__McSession::$useCbs) {
        $r = \__mc_sess_call(\__McSession::$cbs[5], (string)$max, '', 1);
        return ($r === false || $r === null) ? 0 : (int)$r;
    }
    $h = \__McSession::$handler;
    if ($h !== null) {
        $r = $h->gc($max);
        return ($r === false || $r === null) ? 0 : (int)$r;
    }
    return \__mc_sess_gc(\__McSession::$openPath, $max);
}

/** A new id: the handler's create_sid when it has one, else the built-in. */
function __mc_sess_h_create_id(): string
{
    $len = (int)\ini_get('session.sid_length');
    $bits = (int)\ini_get('session.sid_bits_per_character');
    if (\__McSession::$useCbs && \count(\__McSession::$cbs) > 6) {
        $r = \__mc_sess_call(\__McSession::$cbs[6], '', '', 0);
        if ($r !== false && $r !== null && (string)$r !== '') {
            return (string)$r;
        }
    }
    $h = \__McSession::$handler;
    if ($h !== null && $h instanceof \SessionIdInterface) {
        $r = $h->create_sid();
        if ($r !== '') {
            return $r;
        }
    }
    return \__mc_sess_new_id($len, $bits);
}

/**
 * Whether an incoming id may be adopted. session.use_strict_mode is the switch:
 * off, any well-formed id is accepted (php's default, and what lets a client
 * pick its own); on, only an id with an existing record.
 */
function __mc_sess_h_validate(string $id): bool
{
    if (!\__mc_sess_valid_id($id)) {
        return false;
    }
    if ((string)\ini_get('session.use_strict_mode') !== '1') {
        return true;
    }
    if (\__McSession::$useCbs && \count(\__McSession::$cbs) > 7) {
        return (bool)\__mc_sess_call(\__McSession::$cbs[7], $id, '', 1);
    }
    $h = \__McSession::$handler;
    if ($h !== null && $h instanceof \SessionUpdateTimestampHandlerInterface) {
        return $h->validateId($id);
    }
    if ($h !== null) {
        return $h->read($id) !== '';
    }
    return \__mc_sess_exists(\__McSession::$openPath, $id);
}

/** php's `php` serialize_handler: `key|<serialized>` runs, no separator. */
function __mc_sess_encode_php(): string
{
    $out = '';
    foreach ($_SESSION as $k => $v) {
        $key = (string)$k;
        if (\strpos($key, '|') !== false) {
            throw new \ValueError('session_encode(): Key cannot contain a "|" character');
        }
        $out .= $key . '|' . \serialize($v);
    }
    return $out;
}

/** php's `php_binary`: one length byte, the key, then the serialized value. */
function __mc_sess_encode_binary(): string
{
    $out = '';
    foreach ($_SESSION as $k => $v) {
        $key = (string)$k;
        if (\strlen($key) > 127) {
            continue;
        }
        $out .= \chr(\strlen($key)) . $key . \serialize($v);
    }
    return $out;
}

/** The encoded form of $_SESSION under the configured serialize_handler. */
function __mc_sess_encode(): string
{
    $mode = (string)\ini_get('session.serialize_handler');
    if ($mode === 'php_serialize') {
        return \serialize($_SESSION);
    }
    if ($mode === 'php_binary') {
        return \__mc_sess_encode_binary();
    }
    return \__mc_sess_encode_php();
}

/**
 * Parse one serialized value starting at $pos, leaving $pos just past it.
 *
 * The session formats concatenate values, so the decoder needs the CURSOR the
 * unserialize prelude keeps internally — `unserialize()` itself hands back only
 * the value and would leave no way to find where the next key begins.
 */
function __mc_sess_take_value(string $s, int &$pos): mixed
{
    $st = new \__McUnSt();
    $st->s = $s;
    $st->p = $pos;
    $v = \__mc_unser_val($st);
    if (!$st->ok) {
        $pos = \strlen($s);
        return null;
    }
    $pos = $st->p;
    return $v;
}

/** Decode into $_SESSION; false when the payload is malformed, as php answers. */
function __mc_sess_decode(string $data): bool
{
    $mode = (string)\ini_get('session.serialize_handler');
    if ($mode === 'php_serialize') {
        if ($data === '') {
            return true;
        }
        $v = \unserialize($data);
        if (!\is_array($v)) {
            return false;
        }
        foreach ($v as $k => $val) {
            $_SESSION[(string)$k] = $val;
        }
        return true;
    }
    $n = \strlen($data);
    $pos = 0;
    while ($pos < $n) {
        if ($mode === 'php_binary') {
            $len = \ord($data[$pos]);
            $pos = $pos + 1;
            if ($pos + $len > $n) {
                return false;
            }
            $key = \substr($data, $pos, $len);
            $pos = $pos + $len;
        } else {
            $bar = \strpos($data, '|', $pos);
            if ($bar === false) {
                return false;
            }
            $key = \substr($data, $pos, $bar - $pos);
            $pos = $bar + 1;
        }
        $val = \__mc_sess_take_value($data, $pos);
        $_SESSION[$key] = $val;
    }
    return true;
}

/** Send the session cookie for the current id, with the configured attributes. */
function __mc_sess_send_cookie(): void
{
    if ((string)\ini_get('session.use_cookies') !== '1') {
        return;
    }
    $lifetime = (int)\ini_get('session.cookie_lifetime');
    $expires = ($lifetime === 0) ? 0 : (\time() + $lifetime);
    $opts = [];
    $opts['expires'] = (string)$expires;
    $opts['path'] = (string)\ini_get('session.cookie_path');
    $opts['domain'] = (string)\ini_get('session.cookie_domain');
    $opts['secure'] = (string)\ini_get('session.cookie_secure');
    $opts['httponly'] = (string)\ini_get('session.cookie_httponly');
    $opts['samesite'] = (string)\ini_get('session.cookie_samesite');
    \setcookie(\session_name(), \__McSession::$id, $opts);
}

/** php's cache_limiter headers, sent on start. */
function __mc_sess_send_cache_headers(): void
{
    $limiter = (string)\ini_get('session.cache_limiter');
    if ($limiter === '' || $limiter === 'nocache') {
        if ($limiter === 'nocache') {
            \header('Expires: Thu, 19 Nov 1981 08:52:00 GMT');
            \header('Cache-Control: no-store, no-cache, must-revalidate');
            \header('Pragma: no-cache');
        }
        return;
    }
    $expire = (int)\ini_get('session.cache_expire');
    if ($limiter === 'public') {
        \header('Expires: ' . \gmdate('D, d M Y H:i:s \G\M\T', \time() + $expire * 60));
        \header('Cache-Control: public, max-age=' . ($expire * 60));
        return;
    }
    if ($limiter === 'private_no_expire') {
        \header('Cache-Control: private, max-age=' . ($expire * 60));
        return;
    }
    if ($limiter === 'private') {
        \header('Expires: Thu, 19 Nov 1981 08:52:00 GMT');
        \header('Cache-Control: private, max-age=' . ($expire * 60));
    }
}

/** Roll php's gc_probability/gc_divisor dice and collect if it comes up. */
function __mc_sess_maybe_gc(): void
{
    $prob = (int)\ini_get('session.gc_probability');
    $div = (int)\ini_get('session.gc_divisor');
    if ($prob <= 0 || $div <= 0) {
        return;
    }
    if (\random_int(1, $div) > $prob) {
        return;
    }
    \__mc_sess_h_gc((int)\ini_get('session.gc_maxlifetime'));
}

/**
 * Start a session: adopt the incoming id or mint one, open the store, decode the
 * record into $_SESSION.
 *
 * $options mirrors php's: every key is a `session.` ini directive, applied for
 * the rest of the process. Values are strings because that is what an ini
 * directive holds — `['cookie_lifetime' => '3600']`.
 *
 * @param array<string,string> $options
 */
function session_start(array<string, string> $options = []): bool
{
    if (\__McSession::$status === 2) {
        return false;
    }
    if (\headers_sent()) {
        return false;
    }
    foreach ($options as $k => $v) {
        \ini_set('session.' . $k, $v);
    }
    $name = \session_name();
    $fresh = true;
    $id = \__McSession::$id;
    if ($id === '' && (string)\ini_get('session.use_cookies') === '1' && isset($_COOKIE[$name])) {
        $candidate = (string)$_COOKIE[$name];
        \__McSession::$openPath = \__mc_sess_savepath();
        if (\__mc_sess_h_validate($candidate)) {
            $id = $candidate;
            $fresh = false;
        }
    } elseif ($id !== '') {
        $fresh = false;
    }
    \__McSession::$openPath = \__mc_sess_savepath();
    \__mc_sess_h_open(\__McSession::$openPath, $name);
    if ($id === '') {
        $id = \__mc_sess_h_create_id();
    }
    \__McSession::$id = $id;
    \__McSession::$status = 2;
    $_SESSION = \__McSapi::$empty;
    $data = \__mc_sess_h_read($id);
    \__McSession::$lastRead = $data;
    if ($data !== '') {
        \__mc_sess_decode($data);
    }
    if ($fresh) {
        \__mc_sess_send_cookie();
    }
    \__mc_sess_send_cache_headers();
    \__mc_sess_maybe_gc();
    if (!\__McSession::$shutdownRegistered) {
        // php writes and closes at the end of the request whether or not the
        // script says so; the atexit trampoline that drains the shutdown queue
        // is what makes that true here too.
        \register_shutdown_function('session_write_close');
        \__McSession::$shutdownRegistered = true;
    }
    return true;
}

/** PHP_SESSION_NONE or PHP_SESSION_ACTIVE. */
function session_status(): int
{
    return \__McSession::$status;
}

/**
 * Encode $_SESSION and hand it to the store, then close.
 *
 * session.lazy_write skips the write when the encoded form is byte-identical to
 * what was read — the point of the directive, and free to honour here because
 * the read is already kept for it.
 */
function session_write_close(): bool
{
    if (\__McSession::$status !== 2) {
        return true;
    }
    $data = \__mc_sess_encode();
    $ok = true;
    if ($data !== \__McSession::$lastRead || (string)\ini_get('session.lazy_write') !== '1') {
        $ok = \__mc_sess_h_write(\__McSession::$id, $data);
    }
    \__mc_sess_h_close();
    \__McSession::$status = 1;
    \__McSession::$lastRead = $data;
    return $ok;
}

/** php's other spelling of session_write_close(). */
function session_commit(): bool
{
    return \session_write_close();
}

/** Close the session WITHOUT writing: every change since start is discarded. */
function session_abort(): bool
{
    if (\__McSession::$status !== 2) {
        return false;
    }
    \__mc_sess_h_close();
    \__McSession::$status = 1;
    return true;
}

/** Re-read the record, throwing away the changes made since start. */
function session_reset(): bool
{
    if (\__McSession::$status !== 2) {
        return false;
    }
    $_SESSION = \__McSapi::$empty;
    $data = \__mc_sess_h_read(\__McSession::$id);
    \__McSession::$lastRead = $data;
    if ($data !== '') {
        \__mc_sess_decode($data);
    }
    return true;
}

/** Empty $_SESSION, leaving the session itself open. */
function session_unset(): bool
{
    if (\__McSession::$status !== 2) {
        return false;
    }
    $_SESSION = \__McSapi::$empty;
    return true;
}

/** Destroy the record behind the current session. */
function session_destroy(): bool
{
    if (\__McSession::$status !== 2) {
        return false;
    }
    $ok = \__mc_sess_h_destroy(\__McSession::$id);
    \__mc_sess_h_close();
    \__McSession::$status = 1;
    \__McSession::$id = '';
    \__McSession::$lastRead = '';
    return $ok;
}

/**
 * Get or set the session id. Setting it while a session is active is refused —
 * the id is what the open record is keyed by.
 */
function session_id(?string $id = null): mixed
{
    $prev = \__McSession::$id;
    if ($id === null) {
        return $prev;
    }
    if (\__McSession::$status === 2 || \headers_sent()) {
        return false;
    }
    \__McSession::$id = $id;
    return $prev;
}

/** Get or set session.name, which is also the cookie's name. */
function session_name(?string $name = null): mixed
{
    $prev = (string)\ini_get('session.name');
    if ($name === null) {
        return $prev;
    }
    if (\__McSession::$status === 2 || \headers_sent()) {
        return false;
    }
    \ini_set('session.name', $name);
    return $prev;
}

/** Get or set session.save_path. */
function session_save_path(?string $path = null): mixed
{
    $prev = (string)\ini_get('session.save_path');
    if ($path === null) {
        return $prev;
    }
    if (\__McSession::$status === 2 || \headers_sent()) {
        return false;
    }
    \ini_set('session.save_path', $path);
    return $prev;
}

/** Get or set session.save_handler. Only `files` and `user` exist here. */
function session_module_name(?string $module = null): mixed
{
    $prev = (string)\ini_get('session.save_handler');
    if ($module === null) {
        return $prev;
    }
    if (\__McSession::$status === 2 || \headers_sent()) {
        return false;
    }
    \ini_set('session.save_handler', $module);
    return $prev;
}

/** Get or set session.cache_limiter. */
function session_cache_limiter(?string $value = null): mixed
{
    $prev = (string)\ini_get('session.cache_limiter');
    if ($value === null) {
        return $prev;
    }
    \ini_set('session.cache_limiter', $value);
    return $prev;
}

/** Get or set session.cache_expire, in minutes. */
function session_cache_expire(?int $value = null): mixed
{
    $prev = (int)\ini_get('session.cache_expire');
    if ($value === null) {
        return $prev;
    }
    \ini_set('session.cache_expire', (string)$value);
    return $prev;
}

/** A new session id, without touching the current session. */
function session_create_id(string $prefix = ''): mixed
{
    return $prefix . \__mc_sess_h_create_id();
}

/**
 * Move the current session's data to a freshly minted id — the defence against
 * session fixation, so the new cookie is sent unconditionally.
 */
function session_regenerate_id(bool $delete_old_session = false): bool
{
    if (\__McSession::$status !== 2 || \headers_sent()) {
        return false;
    }
    $old = \__McSession::$id;
    if ($delete_old_session) {
        \__mc_sess_h_destroy($old);
    } else {
        // Keep the old record consistent: php writes the current data out under
        // the OLD id before moving, so an id still in flight reads the truth.
        \__mc_sess_h_write($old, \__mc_sess_encode());
    }
    \__McSession::$id = \__mc_sess_h_create_id();
    \__McSession::$lastRead = '';
    \__mc_sess_send_cookie();
    return true;
}

/** The encoded form of the current $_SESSION. */
function session_encode(): mixed
{
    if (\__McSession::$status !== 2) {
        return false;
    }
    return \__mc_sess_encode();
}

/** Decode an encoded payload into $_SESSION. */
function session_decode(string $data): bool
{
    if (\__McSession::$status !== 2) {
        return false;
    }
    return \__mc_sess_decode($data);
}

/** Run the store's garbage collection now; answers how many records went. */
function session_gc(): mixed
{
    if (\__McSession::$status !== 2) {
        return false;
    }
    return \__mc_sess_h_gc((int)\ini_get('session.gc_maxlifetime'));
}

/**
 * The cookie attributes, in php's shape.
 * @return array<string,mixed>
 */
function session_get_cookie_params(): array<string, mixed>
{
    $out = [];
    $out['lifetime'] = (int)\ini_get('session.cookie_lifetime');
    $out['path'] = (string)\ini_get('session.cookie_path');
    $out['domain'] = (string)\ini_get('session.cookie_domain');
    $out['secure'] = (string)\ini_get('session.cookie_secure') === '1';
    $out['httponly'] = (string)\ini_get('session.cookie_httponly') === '1';
    $out['samesite'] = (string)\ini_get('session.cookie_samesite');
    return $out;
}

/**
 * Set the cookie attributes. php also takes an options ARRAY in place of the
 * first argument; that form is spelled here as the named parameters it stands
 * for, because a heterogeneous options array erases its element types.
 */
function session_set_cookie_params(int $lifetime, ?string $path = null, ?string $domain = null, bool $secure = false, bool $httponly = false, string $samesite = ''): bool
{
    if (\__McSession::$status === 2 || \headers_sent()) {
        return false;
    }
    \ini_set('session.cookie_lifetime', (string)$lifetime);
    if ($path !== null) {
        \ini_set('session.cookie_path', $path);
    }
    if ($domain !== null) {
        \ini_set('session.cookie_domain', $domain);
    }
    \ini_set('session.cookie_secure', $secure ? '1' : '');
    \ini_set('session.cookie_httponly', $httponly ? '1' : '');
    \ini_set('session.cookie_samesite', $samesite);
    return true;
}

/**
 * Install a save handler: either an object implementing SessionHandlerInterface,
 * or php's six-to-nine callables (open, close, read, write, destroy, gc, and
 * optionally create_sid, validate_sid, update_timestamp).
 */
function session_set_save_handler(mixed $a, mixed $b = null, mixed $c = null, mixed $d = null, mixed $e = null, mixed $f = null, mixed $g = null, mixed $h = null, mixed $i = null): bool
{
    if (\__McSession::$status === 2) {
        return false;
    }
    // Dispatched on the INTERFACE, not on is_object: a Closure is an object
    // too, so the object form would have swallowed php's callable form and
    // thrown on it. php 8.5 deprecates the callable form but still accepts it.
    if ($a instanceof \SessionHandlerInterface) {
        \__McSession::$handler = $a;
        \__McSession::$useCbs = false;
        \ini_set('session.save_handler', 'user');
        if ($b === null || $b === true) {
            \session_register_shutdown();
        }
        return true;
    }
    if ($f === null) {
        if (\is_object($a)) {
            throw new \TypeError('session_set_save_handler(): Argument #1 ($sessionhandler) must implement interface SessionHandlerInterface');
        }
        return false;
    }
    $cbs = [];
    $cbs[] = $a;
    $cbs[] = $b;
    $cbs[] = $c;
    $cbs[] = $d;
    $cbs[] = $e;
    $cbs[] = $f;
    if ($g !== null) { $cbs[] = $g; }
    if ($h !== null) { $cbs[] = $h; }
    if ($i !== null) { $cbs[] = $i; }
    \__McSession::$cbs = $cbs;
    \__McSession::$useCbs = true;
    \__McSession::$handler = null;
    \ini_set('session.save_handler', 'user');
    return true;
}

/** Write and close the session at the end of the request. */
function session_register_shutdown(): void
{
    if (\__McSession::$shutdownRegistered) {
        return;
    }
    \register_shutdown_function('session_write_close');
    \__McSession::$shutdownRegistered = true;
}
