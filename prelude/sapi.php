<?php

// Response headers, cookies, and the per-request context.
// DEMAND-GATED (Main.php): only a program that touches one of these carries it.
//
// This is deliberately NOT a SAPI registry — there are no modules to register,
// no lifecycle to plug into, and nothing to configure. A compiled binary IS the
// SAPI: an HTTP server written on Async\ calls Manticore\Sapi\requestBegin() with
// the request's GPC arrays, runs the handler, and calls Manticore\Sapi\requestEnd()
// for the status line and the header block it has to write back. Two functions.
//
// CLI parity is preserved by making the request the thing that switches
// behaviour, not a build mode: with no request active, headers_list() answers []
// and http_response_code() answers false, exactly as php's CLI SAPI does, while
// header()/setcookie() still record into the buffer so a server can drain it.
//
// The state lives here, in the prelude, and not in src/Runtime/Stdlib, because
// it holds ARRAYS (and, once sessions land, a handler OBJECT), and neither can
// cross the stdlib.o boundary. The one scalar the stdlib does own is the
// "output has started" bit (__mc_out_sent), because ini_set has to read it.
//
// ⚠ The context is per-FIBER, keyed by Async\Task id. A concurrent server
// interleaves requests inside one process, so process-global superglobals would
// let request B read request A's $_COOKIE. The swap is driven from the ONE place
// the scheduler switches tasks (Scheduler::step) rather than from every suspend
// point, and it re-snapshots FROM the superglobal cells on the way out: an eager
// array copy may have replaced the handle mid-request, so a cached handle would
// park the wrong array.

namespace Manticore\Sapi {

    final class Context
    {
        /** @var array<int,string> response header lines, in the order php would send them */
        public static array $headers = [];

        /** php's response code. 200 until http_response_code() or a `header()` status line. */
        public static int $status = 200;

        /** True while a request is being served, i.e. between begin and end. */
        public static bool $active = false;

        /**
         * Whether THIS request's head has gone out.
         *
         * Per-request, and that is the whole point: `__mc_out_sent()` is a PROCESS
         * global, so one request flipping it would mute `header()` for every other
         * request in flight. A server sets this the moment it writes the head,
         * which is what makes a streaming handler honest — inside the body closure
         * the headers really ARE gone, so `headers_sent()` must say so.
         */
        public static bool $sent = false;

        /** Task id owning the live context; 0 is the main flow (no task). */
        public static int $cur = 0;

        /** @var array<int,array<int,string>> parked $headers, by task id */
        public static array $savedHeaders = [];

        /** @var array<int,bool> parked $sent, by task id */
        public static array $savedSent = [];

        /** @var array<int,int> parked $status, by task id */
        public static array $savedStatus = [];

        /** @var array<int,bool> parked $active, by task id */
        public static array $savedActive = [];

        /** @var array<int,array<string,mixed>> parked $_SERVER, by task id */
        public static array $savedServer = [];

        /** @var array<int,array<string,mixed>> parked $_GET, by task id */
        public static array $savedGet = [];

        /** @var array<int,array<string,mixed>> parked $_POST, by task id */
        public static array $savedPost = [];

        /** @var array<int,array<string,mixed>> parked $_COOKIE, by task id */
        public static array $savedCookie = [];

        /** @var array<int,array<string,mixed>> parked $_REQUEST, by task id */
        public static array $savedRequest = [];

        /** @var array<int,array<string,mixed>> parked $_SESSION, by task id */
        public static array $savedSession = [];

        /** @var array<int,array<string,mixed>> parked $_FILES, by task id */
        public static array $savedFiles = [];

        /** tmp path → moved?, this request's uploads. Typed like $empty: the
         *  first registration of a process lands here before any reset.
         *  @var array<string,bool> */
        public static array<string, bool> $uploaded = [];

        /** @var array<int,array<string,bool>> parked $uploaded, by task id */
        public static array $savedUploaded = [];

        /**
         * The reset value for a superglobal, and the reason it is a property rather
         * than a `[]` literal: an empty literal types `assoc[string, unknown]`, so
         * the element stores that follow write RAW values while every other scope
         * reads the superglobal as cells. Assigning a cell-element array keeps the
         * repr, and the element stores then box by static type.
         * @var array<string,mixed>
         */
        public static array<string, mixed> $empty = [];

        /** The reset value for the header block, typed for the same reason.
         *  @var array<int,string> */
        public static array<int, string> $emptyLines = [];

        /** The reset value for $uploaded, typed for the same reason.
         *  @var array<string,bool> */
        public static array<string, bool> $emptyFlags = [];

        /**
         * The seed value for a GPC-shaped array — $_GET/$_POST/$_FILES and the
         * Request arrays they come from. php keys these int-OR-string (`?0=x`,
         * `a[]=1`, an anonymous upload at $_FILES[0]), so the key is a tagged
         * cell: a string-typed key over an int entry has no string to borrow and
         * would have to MINT one per iteration. Same cell-element reason as $empty.
         * @var array<int|string,mixed>
         */
        public static array<int|string, mixed> $emptyGpc = [];

        /** True once any request has begun, anywhere in the process — the guard that
         *  keeps the per-task swap off a program that serves none. */
        public static bool $everActive = false;

        /** @var array<int,bool> whether that task's context was ever parked */
        public static array $seen = [];
    }

    /** The characters php refuses in a cookie name, and in a raw cookie value. */
    function cookieBad(string $s, bool $withEq): bool
    {
        $n = \strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === ',' || $c === ';' || $c === ' ' || $c === "\t"
                || $c === "\r" || $c === "\n" || $c === "\013" || $c === "\014") {
                return true;
            }
            if ($withEq && $c === '=') {
                return true;
            }
        }
        return false;
    }

    /**
     * Park the live context under $from and make $to's the live one.
     *
     * Called from Scheduler::step around every resume — the single place a task
     * switch happens — and cheap enough to sit there unconditionally: a process
     * that never begins a request pays one bool test and one int store per switch.
     */
    function contextSwitch(int $from, int $to): void
    {
        if ($from === $to) {
            return;
        }
        if (!Context::$everActive) {
            // No request has EVER begun in this process, so there is nothing to
            // keep apart. This is the whole cost the swap adds to a plain async
            // program: one bool test and one int store per task switch.
            Context::$cur = $to;
            return;
        }
        // Park unconditionally, restore unconditionally. An asymmetric version —
        // "leave the live context alone when the target never served a request" —
        // leaked: after the last task finished, the main flow still read that task's
        // headers and $_GET.
        //
        // Snapshot the CELLS, not a handle kept from begin(): a copy-on-write
        // rebuild during the request would have left that handle stale.
        Context::$savedHeaders[$from] = Context::$headers;
        Context::$savedStatus[$from] = Context::$status;
        Context::$savedActive[$from] = Context::$active;
        Context::$savedSent[$from] = Context::$sent;
        Context::$savedServer[$from] = $_SERVER;
        Context::$savedGet[$from] = $_GET;
        Context::$savedPost[$from] = $_POST;
        Context::$savedCookie[$from] = $_COOKIE;
        Context::$savedRequest[$from] = $_REQUEST;
        Context::$savedSession[$from] = $_SESSION;
        Context::$savedFiles[$from] = $_FILES;
        Context::$savedUploaded[$from] = Context::$uploaded;
        Context::$seen[$from] = true;
        Context::$cur = $to;
        // The session tier parks its own per-request half (status, id) the same way.
        // Guarded, so this file keeps no dependency on it: a program with no session
        // compiles the branch away.
        if (\function_exists('__mc_session_ctx_switch')) {
            \__mc_session_ctx_switch($from, $to);
        }
        // The output-buffer stack is a PROCESS global too (@__mir_ob_depth /
        // @__mir_ob_stack are linkonce_odr — they have to be, so that `echo` inside
        // the prebuilt stdlib.o can reach them), so two concurrent handlers with
        // open buffers cross-contaminate: fiber A's `echo` lands in fiber B's
        // buffer. Verified by removing this call: A's body came back holding B's
        // text while its $_GET was still its own. Same guard as the session hook,
        // for the same reason — no dependency on ob.php, and a program without one
        // compiles the branch away.
        if (\function_exists('__mc_ob_ctx_switch')) {
            \__mc_ob_ctx_switch($from, $to);
        }
        if (!isset(Context::$seen[$to])) {
            // A flow that has never been parked starts clean — a fresh task has no
            // request, and inheriting the previous one's would be the leak above.
            Context::$headers = Context::$emptyLines;
            Context::$status = 200;
            Context::$sent = false;
            Context::$active = false;
            $_GET = Context::$empty;
            $_POST = Context::$empty;
            $_COOKIE = Context::$empty;
            $_REQUEST = Context::$empty;
            $_SESSION = Context::$empty;
            $_FILES = Context::$empty;
            Context::$uploaded = Context::$emptyFlags;
            return;
        }
        Context::$headers = Context::$savedHeaders[$to];
        Context::$status = Context::$savedStatus[$to];
        Context::$active = Context::$savedActive[$to];
        Context::$sent = Context::$savedSent[$to];
        $_SERVER = Context::$savedServer[$to];
        $_GET = Context::$savedGet[$to];
        $_POST = Context::$savedPost[$to];
        $_COOKIE = Context::$savedCookie[$to];
        $_REQUEST = Context::$savedRequest[$to];
        $_SESSION = Context::$savedSession[$to];
        $_FILES = Context::$savedFiles[$to];
        Context::$uploaded = Context::$savedUploaded[$to];
    }

    /**
     * Begin serving a request on the current flow: seed the GPC superglobals and
     * start an empty response.
     *
     * $server is MERGED over the process $_SERVER rather than replacing it, so the
     * CLI keys (SCRIPT_NAME, argv, REQUEST_TIME) stay visible to a framework that
     * reads them while the caller supplies REQUEST_URI / REQUEST_METHOD / headers.
     * $_REQUEST is built GET-then-POST, which is php's default request_order.
     *
     * ⚠ The seeding is ELEMENT BY ELEMENT, which is not style. A whole-array
     * store of a concrete-element array into a cell-element superglobal leaves
     * the elements RAW while every reader decodes them by tag — `echo $_GET['a']`
     * then printed 2.1E-314. Storing one element at a time boxes each value by
     * its static type, which is exactly what the readers expect. $get, $post and
     * $files are `mixed`-valued because they nest (`?a[]=1`, `a[b][c]`,
     * $_FILES['f']['size']); the caller builds every level as a cell-element
     * array (Http\parseQueryNested) so the nested stores stay typed too. Their
     * keys are int-or-string cells ({@see Context::$emptyGpc}): `?0=x` and an
     * anonymous upload land under the INT key, as php does, and the element
     * store below dispatches on the key's tag.
     */
    function requestBegin(array<string, string> $server = [], array<int|string, mixed> $get = [], array<int|string, mixed> $post = [], array<string, string> $cookie = [], array<int|string, mixed> $files = []): void
    {
        foreach ($server as $k => $v) {
            $_SERVER[$k] = $v;
        }
        $_GET = Context::$empty;
        foreach ($get as $k => $v) {
            $_GET[$k] = $v;
        }
        $_POST = Context::$empty;
        foreach ($post as $k => $v) {
            $_POST[$k] = $v;
        }
        $_COOKIE = Context::$empty;
        foreach ($cookie as $k => $v) {
            $_COOKIE[$k] = $v;
        }
        $_REQUEST = Context::$empty;
        foreach ($get as $k => $v) {
            $_REQUEST[$k] = $v;
        }
        foreach ($post as $k => $v) {
            $_REQUEST[$k] = $v;
        }
        $_FILES = Context::$empty;
        foreach ($files as $k => $v) {
            $_FILES[$k] = $v;
        }
        $_SESSION = Context::$empty;
        // $uploaded is NOT reset here: the multipart parse that registers this
        // request's temp files runs while the caller builds $files, i.e. BEFORE
        // this body. uploadsBegin() opens it, requestEnd() empties it.
        responseBegin();
    }

    /**
     * Start a RESPONSE on the current flow, without touching the superglobals.
     *
     * The cheap half of {@see requestBegin}: an empty header block, status
     * 200, and the flow marked live — which is all `header()`, `setcookie()` and
     * `http_response_code()` need to work. Http\Server calls this for every request
     * so those functions are live in every handler, and only calls the full
     * begin() when a program asked for `$_GET`/`$_POST` (`compat(true)`): seeding
     * four superglobals per request for code that never reads them is pure cost.
     */
    function responseBegin(): void
    {
        Context::$headers = Context::$emptyLines;
        Context::$status = 200;
        Context::$sent = false;
        Context::$active = true;
        Context::$everActive = true;
        Context::$seen[Context::$cur] = true;
    }

    /**
     * Finish the request. The context stays parked, so a handler may still read
     * $_SESSION after this returns; the next requestBegin() on the same flow
     * resets it.
     *
     * The upload sweep is php's: every temp file the multipart parser produced
     * for this request and nobody moved is unlinked here, on every path out of
     * the handler — a thrown handler leaves no file behind.
     *
     * The status and the header block are read back through their own typed
     * accessors rather than one `['status' => …, 'headers' => …]` array: a
     * heterogeneous array erases its element type, and the reader then decodes an
     * int as a cell (`status=2.06E-321`). Three accessors, three concrete types.
     */
    /**
     * Forget a task's parked context. Called from `Scheduler::step` the moment a
     * task SETTLES, which is the only point at which its id can never come back.
     *
     * {@see contextSwitch} parks twelve maps under the task id and nothing ever
     * removed an entry, so a server that runs each request in its own task grew
     * them for ever: measured at 46 B per request, and the heap showed it as
     * three int-keyed buffers reaching 2.1 MB each at 200k requests while the
     * node COUNT stayed flat — not a leak of objects, a set of maps nobody
     * pruned. The session and output-buffer tiers park under the same id and are
     * dropped through the same guarded hooks.
     */
    function contextDrop(int $id): void
    {
        if (!Context::$everActive) {
            return;
        }
        unset(Context::$savedHeaders[$id]);
        unset(Context::$savedStatus[$id]);
        unset(Context::$savedActive[$id]);
        unset(Context::$savedSent[$id]);
        unset(Context::$savedServer[$id]);
        unset(Context::$savedGet[$id]);
        unset(Context::$savedPost[$id]);
        unset(Context::$savedCookie[$id]);
        unset(Context::$savedRequest[$id]);
        unset(Context::$savedSession[$id]);
        unset(Context::$savedFiles[$id]);
        unset(Context::$savedUploaded[$id]);
        unset(Context::$seen[$id]);
        if (\function_exists('__mc_session_ctx_drop')) {
            \__mc_session_ctx_drop($id);
        }
        if (\function_exists('__mc_ob_ctx_drop')) {
            \__mc_ob_ctx_drop($id);
        }
    }

    function requestEnd(): void
    {
        Context::$active = false;
        foreach (Context::$uploaded as $tmp => $moved) {
            if (!$moved) {
                @\unlink($tmp);
            }
        }
        Context::$uploaded = Context::$emptyFlags;
    }

    /**
     * @internal Open this request's upload registry, typed. Called by the server
     * at the top of every request, BEFORE the multipart parse that registers
     * into it — that parse runs while requestBegin()'s arguments are built.
     */
    function uploadsBegin(): void
    {
        Context::$uploaded = Context::$emptyFlags;
    }

    /** @internal a temp file the multipart parser produced for THIS request. */
    function uploadRegister(string $tmp): void
    {
        if ($tmp !== '') {
            Context::$uploaded[$tmp] = false;
        }
    }

    /** @internal the file left the temp dir under the handler's control. */
    function uploadMoved(string $tmp): void
    {
        if (isset(Context::$uploaded[$tmp])) {
            Context::$uploaded[$tmp] = true;
        }
    }

    /** The response code the handler settled on. */
    function responseStatus(): int
    {
        return Context::$status;
    }

    /**
     * The response header lines, in the order php would send them.
     * @return array<int,string>
     */
    function responseHeaders(): array<int, string>
    {
        return Context::$headers;
    }

    /** Mark this request's head as written. The server's to call, not a user's. */
    function responseSent(): void
    {
        Context::$sent = true;
    }

    /** Render one Set-Cookie line, php's attribute order, from an options array. */
    function cookieLine(string $name, string $value, int $expires, string $path, string $domain, bool $secure, bool $httponly, string $samesite): string
    {
        $line = 'Set-Cookie: ' . $name . '=';
        if ($value === '') {
            // php picks an expiry in the past instead of an empty value, because
            // that is what actually deletes the cookie in every browser.
            return $line . 'deleted; expires=' . \gmdate('D, d M Y H:i:s \G\M\T', 1) . '; Max-Age=0';
        }
        $line .= $value;
        if ($expires !== 0) {
            $maxAge = $expires - \time();
            if ($maxAge < 0) {
                $maxAge = 0;
            }
            $line .= '; expires=' . \gmdate('D, d M Y H:i:s \G\M\T', $expires) . '; Max-Age=' . $maxAge;
        }
        if ($path !== '') {
            $line .= '; path=' . $path;
        }
        if ($domain !== '') {
            $line .= '; domain=' . $domain;
        }
        if ($secure) {
            $line .= '; secure';
        }
        if ($httponly) {
            $line .= '; HttpOnly';
        }
        if ($samesite !== '') {
            $line .= '; SameSite=' . $samesite;
        }
        return $line;
    }

    /** Shared by setcookie and setrawcookie; $raw skips the value encoding. */
    function setCookie(string $fn, bool $raw, string $name, string $value, mixed $expires_or_options, string $path, string $domain, bool $secure, bool $httponly): bool
    {
        if ($name === '' || cookieBad($name, true)) {
            throw new \ValueError($fn . '(): Argument #1 ($name) cannot contain "=", ",", ";", " ", "\t", "\r", "\n", "\013", or "\014"');
        }
        $expires = 0;
        $samesite = '';
        if (\is_array($expires_or_options)) {
            foreach ($expires_or_options as $k => $v) {
                $lk = \strtolower((string)$k);
                if ($lk === 'expires') { $expires = (int)$v; continue; }
                if ($lk === 'path') { $path = (string)$v; continue; }
                if ($lk === 'domain') { $domain = (string)$v; continue; }
                if ($lk === 'secure') { $secure = (bool)$v; continue; }
                if ($lk === 'httponly') { $httponly = (bool)$v; continue; }
                if ($lk === 'samesite') { $samesite = (string)$v; continue; }
                throw new \ValueError($fn . '(): option "' . (string)$k . '" is invalid');
            }
        } else {
            $expires = (int)$expires_or_options;
        }
        if ($raw) {
            if (cookieBad($value, false)) {
                throw new \ValueError($fn . '(): Argument #2 ($value) cannot contain ",", ";", " ", "\t", "\r", "\n", "\013", or "\014"');
            }
        } else {
            $value = ($value === '') ? '' : \rawurlencode($value);
        }
        if (\headers_sent()) {
            return false;
        }
        Context::$headers[] = cookieLine($name, $value, $expires, $path, $domain, $secure, $httponly, $samesite);
        return true;
    }

}

namespace {

    /**
     * Whether the header block has already gone out.
     *
     * Inside a request the answer is that REQUEST's — a server writing one
     * response must not tell every other in-flight handler that its headers are
     * gone. Outside one it is the process-wide CLI answer, which is what a plain
     * script sees.
     */
    function headers_sent(#[RefOut] string &$filename = '', #[RefOut] int &$line = 0): bool
    {
        // php's out-parameters. It fills them with the source position where output
        // STARTED; we do not record that position — no output path carries a
        // file/line — so they answer php's not-yet-sent values ('' and 0) either
        // way. Symfony's NativeSessionStorage::start() reads them straight into an
        // exception message, and passing them at all is the point: an argument in a
        // by-ref position is a DEFINITION, so without the `&` the caller's `$file`
        // was a dangling local and the whole program was refused.
        $filename = '';
        $line = 0;
        if (\Manticore\Sapi\Context::$active) {
            return \Manticore\Sapi\Context::$sent;
        }
        return \__mc_out_sent(0) === 1;
    }

    /**
     * Queue a response header. `$replace` false appends a second line with the same
     * field name instead of overwriting it, and a `HTTP/1.1 <code> <reason>` line
     * sets the status, as php's does.
     *
     * ⚠ php normalises Content-Type (it appends the default charset); this records
     * the line as written. A framework that sets its own charset is unaffected.
     */
    function header(string $header, bool $replace = true, int $response_code = 0): void
    {
        if (\headers_sent()) {
            return;
        }
        $line = \rtrim($header, "\r\n");
        if ($line === '') {
            return;
        }
        if (\strncasecmp($line, 'HTTP/', 5) === 0) {
            $parts = \explode(' ', $line);
            if (\count($parts) > 1) {
                \Manticore\Sapi\Context::$status = (int)$parts[1];
            }
            return;
        }
        if ($response_code > 0) {
            \Manticore\Sapi\Context::$status = $response_code;
        }
        $colon = \strpos($line, ':');
        if ($colon === false) {
            return;
        }
        $name = \substr($line, 0, $colon);
        if ($replace) {
            $kept = [];
            foreach (\Manticore\Sapi\Context::$headers as $h) {
                $c = \strpos($h, ':');
                $hn = ($c === false) ? $h : \substr($h, 0, $c);
                if (\strcasecmp($hn, $name) !== 0) {
                    $kept[] = $h;
                }
            }
            $kept[] = $line;
            \Manticore\Sapi\Context::$headers = $kept;
            return;
        }
        \Manticore\Sapi\Context::$headers[] = $line;
    }

    /** Drop one queued header field, or all of them. */
    function header_remove(?string $name = null): void
    {
        if ($name === null) {
            \Manticore\Sapi\Context::$headers = \Manticore\Sapi\Context::$emptyLines;
            return;
        }
        $kept = [];
        foreach (\Manticore\Sapi\Context::$headers as $h) {
            $c = \strpos($h, ':');
            $hn = ($c === false) ? $h : \substr($h, 0, $c);
            if (\strcasecmp($hn, $name) !== 0) {
                $kept[] = $h;
            }
        }
        \Manticore\Sapi\Context::$headers = $kept;
    }

    /**
     * The queued header lines. Empty with no request in flight, which is php's CLI
     * answer; a server reads the real block through requestEnd().
     * @return array<int,string>
     */
    function headers_list(): array
    {
        if (!\Manticore\Sapi\Context::$active) {
            return [];
        }
        return \Manticore\Sapi\Context::$headers;
    }

    /**
     * Get or set the response code. With no request in flight there is no response
     * to speak of, so the getter answers false — php's CLI answer too.
     */
    function http_response_code(mixed $response_code = false): mixed
    {
        if ($response_code === false) {
            if (!\Manticore\Sapi\Context::$active) {
                return false;
            }
            return \Manticore\Sapi\Context::$status;
        }
        $prev = \Manticore\Sapi\Context::$active ? \Manticore\Sapi\Context::$status : false;
        \Manticore\Sapi\Context::$status = (int)$response_code;
        return $prev;
    }

    /**
     * Queue a Set-Cookie header. The value is percent-encoded, as php encodes it;
     * `$expires_or_options` takes either php's legacy timestamp or the options array
     * (expires / path / domain / secure / httponly / samesite).
     */
    function setcookie(string $name, string $value = '', mixed $expires_or_options = 0, string $path = '', string $domain = '', bool $secure = false, bool $httponly = false): bool
    {
        return \Manticore\Sapi\setCookie('setcookie', false, $name, $value, $expires_or_options, $path, $domain, $secure, $httponly);
    }

    /** Same, with the value sent verbatim — so php rejects the bytes it would have encoded. */
    function setrawcookie(string $name, string $value = '', mixed $expires_or_options = 0, string $path = '', string $domain = '', bool $secure = false, bool $httponly = false): bool
    {
        return \Manticore\Sapi\setCookie('setrawcookie', true, $name, $value, $expires_or_options, $path, $domain, $secure, $httponly);
    }

    /**
     * php: true only for a file THIS request uploaded and nobody moved yet.
     * Outside a request nothing was registered, so this is php's CLI answer
     * (false) too — the ONE body for the name; a stdlib copy would be the strong
     * symbol over this linkonce_odr one and win every non-inlined call.
     */
    function is_uploaded_file(string $filename): bool
    {
        return isset(\Manticore\Sapi\Context::$uploaded[$filename]) && \Manticore\Sapi\Context::$uploaded[$filename] === false;
    }

    /** rename(2), falling back to copy+unlink across devices; the moved file survives the request-end sweep. */
    function move_uploaded_file(string $from, string $to): bool
    {
        if (!\is_uploaded_file($from)) {
            return false;
        }
        $ok = @\rename($from, $to);
        if (!$ok) {
            $ok = @\copy($from, $to);
            if ($ok) {
                @\unlink($from);
            }
        }
        if ($ok) {
            \Manticore\Sapi\Context::$uploaded[$from] = true;
        }
        return $ok;
    }

}
