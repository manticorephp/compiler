<?php

/** ext/mysqli skeleton: every Zend symbol declared with its Zend signature; bodies land with the implementation. Gated on a `mysqli*` mention (see Main.php). */

const MYSQLI_ASSOC = 1;
const MYSQLI_ASYNC = 8;
const MYSQLI_AUTO_INCREMENT_FLAG = 512;
const MYSQLI_BINARY_FLAG = 128;
const MYSQLI_BLOB_FLAG = 16;
const MYSQLI_BOTH = 3;
const MYSQLI_CLIENT_CAN_HANDLE_EXPIRED_PASSWORDS = 4194304;
const MYSQLI_CLIENT_COMPRESS = 32;
const MYSQLI_CLIENT_FOUND_ROWS = 2;
const MYSQLI_CLIENT_IGNORE_SPACE = 256;
const MYSQLI_CLIENT_INTERACTIVE = 1024;
const MYSQLI_CLIENT_NO_SCHEMA = 16;
const MYSQLI_CLIENT_SSL = 2048;
const MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT = 64;
const MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT = 1073741824;
const MYSQLI_CURSOR_TYPE_NO_CURSOR = 0;
const MYSQLI_CURSOR_TYPE_READ_ONLY = 1;
const MYSQLI_DATA_TRUNCATED = 101;
const MYSQLI_DEBUG_TRACE_ENABLED = 0;
const MYSQLI_ENUM_FLAG = 256;
const MYSQLI_GROUP_FLAG = 32768;
const MYSQLI_INIT_COMMAND = 3;
const MYSQLI_IS_MARIADB = false;
const MYSQLI_MULTIPLE_KEY_FLAG = 8;
const MYSQLI_NOT_NULL_FLAG = 1;
const MYSQLI_NO_DATA = 100;
const MYSQLI_NO_DEFAULT_VALUE_FLAG = 4096;
const MYSQLI_NUM = 2;
const MYSQLI_NUM_FLAG = 32768;
const MYSQLI_ON_UPDATE_NOW_FLAG = 8192;
const MYSQLI_OPT_CAN_HANDLE_EXPIRED_PASSWORDS = 37;
const MYSQLI_OPT_CONNECT_TIMEOUT = 0;
const MYSQLI_OPT_INT_AND_FLOAT_NATIVE = 201;
const MYSQLI_OPT_LOAD_DATA_LOCAL_DIR = 43;
const MYSQLI_OPT_LOCAL_INFILE = 8;
const MYSQLI_OPT_NET_CMD_BUFFER_SIZE = 202;
const MYSQLI_OPT_NET_READ_BUFFER_SIZE = 203;
const MYSQLI_OPT_READ_TIMEOUT = 11;
const MYSQLI_OPT_SSL_VERIFY_SERVER_CERT = 21;
const MYSQLI_PART_KEY_FLAG = 16384;
const MYSQLI_PRI_KEY_FLAG = 2;
const MYSQLI_READ_DEFAULT_FILE = 4;
const MYSQLI_READ_DEFAULT_GROUP = 5;
const MYSQLI_REFRESH_BACKUP_LOG = 2097152;
const MYSQLI_REFRESH_GRANT = 1;
const MYSQLI_REFRESH_HOSTS = 8;
const MYSQLI_REFRESH_LOG = 2;
const MYSQLI_REFRESH_MASTER = 128;
const MYSQLI_REFRESH_REPLICA = 64;
const MYSQLI_REFRESH_SLAVE = 64;
const MYSQLI_REFRESH_STATUS = 16;
const MYSQLI_REFRESH_TABLES = 4;
const MYSQLI_REFRESH_THREADS = 32;
const MYSQLI_REPORT_ALL = 255;
const MYSQLI_REPORT_ERROR = 1;
const MYSQLI_REPORT_INDEX = 4;
const MYSQLI_REPORT_OFF = 0;
const MYSQLI_REPORT_STRICT = 2;
const MYSQLI_SERVER_PS_OUT_PARAMS = 4096;
const MYSQLI_SERVER_PUBLIC_KEY = 35;
const MYSQLI_SERVER_QUERY_NO_GOOD_INDEX_USED = 16;
const MYSQLI_SERVER_QUERY_NO_INDEX_USED = 32;
const MYSQLI_SERVER_QUERY_WAS_SLOW = 2048;
const MYSQLI_SET_CHARSET_NAME = 7;
const MYSQLI_SET_FLAG = 2048;
const MYSQLI_STMT_ATTR_CURSOR_TYPE = 1;
const MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH = 0;
const MYSQLI_STORE_RESULT = 0;
const MYSQLI_STORE_RESULT_COPY_DATA = 16;
const MYSQLI_TIMESTAMP_FLAG = 1024;
const MYSQLI_TRANS_COR_AND_CHAIN = 1;
const MYSQLI_TRANS_COR_AND_NO_CHAIN = 2;
const MYSQLI_TRANS_COR_NO_RELEASE = 8;
const MYSQLI_TRANS_COR_RELEASE = 4;
const MYSQLI_TRANS_START_READ_ONLY = 4;
const MYSQLI_TRANS_START_READ_WRITE = 2;
const MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT = 1;
const MYSQLI_TYPE_BIT = 16;
const MYSQLI_TYPE_BLOB = 252;
const MYSQLI_TYPE_CHAR = 1;
const MYSQLI_TYPE_DATE = 10;
const MYSQLI_TYPE_DATETIME = 12;
const MYSQLI_TYPE_DECIMAL = 0;
const MYSQLI_TYPE_DOUBLE = 5;
const MYSQLI_TYPE_ENUM = 247;
const MYSQLI_TYPE_FLOAT = 4;
const MYSQLI_TYPE_GEOMETRY = 255;
const MYSQLI_TYPE_INT24 = 9;
const MYSQLI_TYPE_JSON = 245;
const MYSQLI_TYPE_LONG = 3;
const MYSQLI_TYPE_LONGLONG = 8;
const MYSQLI_TYPE_LONG_BLOB = 251;
const MYSQLI_TYPE_MEDIUM_BLOB = 250;
const MYSQLI_TYPE_NEWDATE = 14;
const MYSQLI_TYPE_NEWDECIMAL = 246;
const MYSQLI_TYPE_NULL = 6;
const MYSQLI_TYPE_SET = 248;
const MYSQLI_TYPE_SHORT = 2;
const MYSQLI_TYPE_STRING = 254;
const MYSQLI_TYPE_TIME = 11;
const MYSQLI_TYPE_TIMESTAMP = 7;
const MYSQLI_TYPE_TINY = 1;
const MYSQLI_TYPE_TINY_BLOB = 249;
const MYSQLI_TYPE_VAR_STRING = 253;
const MYSQLI_TYPE_VECTOR = 242;
const MYSQLI_TYPE_YEAR = 13;
const MYSQLI_UNIQUE_KEY_FLAG = 4;
const MYSQLI_UNSIGNED_FLAG = 32;
const MYSQLI_USE_RESULT = 1;
const MYSQLI_ZEROFILL_FLAG = 64;

class mysqli
{
    public int|string $affected_rows;
    public string $client_info;
    public int $client_version;
    public int $connect_errno;
    public ?string $connect_error;
    public int $errno;
    public string $error;
    public array $error_list;
    public int $field_count;
    public string $host_info;
    public ?string $info;
    public int|string $insert_id;
    public int $protocol_version;
    public string $server_info;
    public int $server_version;
    public string $sqlstate;
    public int $thread_id;
    public int $warning_count;

    public function __construct(?string $hostname = null, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null)
    {
        throw new Error('mysqli: not implemented');
    }

    public function autocommit(bool $enable): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function change_user(string $username, #[\SensitiveParameter] string $password, ?string $database): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function character_set_name(): string
    {
        throw new Error('mysqli: not implemented');
    }

    public function close(): true
    {
        throw new Error('mysqli: not implemented');
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function connect(?string $hostname = null, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function debug(string $options): true
    {
        throw new Error('mysqli: not implemented');
    }

    public function dump_debug_info(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function escape_string(string $string): string
    {
        throw new Error('mysqli: not implemented');
    }

    public function execute_query(string $query, ?array $params = null): bool|mysqli_result
    {
        throw new Error('mysqli: not implemented');
    }

    public function get_charset(): ?object
    {
        throw new Error('mysqli: not implemented');
    }

    public function get_client_info(): string
    {
        throw new Error('mysqli: not implemented');
    }

    public function get_connection_stats(): array
    {
        throw new Error('mysqli: not implemented');
    }

    public function get_server_info(): string
    {
        throw new Error('mysqli: not implemented');
    }

    public function get_warnings(): false|mysqli_warning
    {
        throw new Error('mysqli: not implemented');
    }

    public function init()
    {
        throw new Error('mysqli: not implemented');
    }

    public function kill(int $process_id): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function more_results(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function multi_query(string $query): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function next_result(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function options(int $option, $value): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function ping(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public static function poll(?array &$read, ?array &$error, array &$reject, int $seconds, int $microseconds = 0): false|int
    {
        throw new Error('mysqli: not implemented');
    }

    public function prepare(string $query): false|mysqli_stmt
    {
        throw new Error('mysqli: not implemented');
    }

    public function query(string $query, int $result_mode = 0): bool|mysqli_result
    {
        throw new Error('mysqli: not implemented');
    }

    public function real_connect(?string $hostname = null, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null, int $flags = 0): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function real_escape_string(string $string): string
    {
        throw new Error('mysqli: not implemented');
    }

    public function real_query(string $query): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function reap_async_query(): bool|mysqli_result
    {
        throw new Error('mysqli: not implemented');
    }

    public function refresh(int $flags): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function release_savepoint(string $name): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function savepoint(string $name): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function select_db(string $database): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function set_charset(string $charset): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function set_opt(int $option, $value): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function ssl_set(?string $key, ?string $certificate, ?string $ca_certificate, ?string $ca_path, ?string $cipher_algos): true
    {
        throw new Error('mysqli: not implemented');
    }

    public function stat(): false|string
    {
        throw new Error('mysqli: not implemented');
    }

    public function stmt_init(): false|mysqli_stmt
    {
        throw new Error('mysqli: not implemented');
    }

    public function store_result(int $mode = 0): false|mysqli_result
    {
        throw new Error('mysqli: not implemented');
    }

    public function thread_safe(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function use_result(): false|mysqli_result
    {
        throw new Error('mysqli: not implemented');
    }
}

final class mysqli_driver
{
    public string $client_info;
    public int $client_version;
    public int $driver_version;
    public int $report_mode;
}

class mysqli_result implements IteratorAggregate
{
    public int $current_field;
    public int $field_count;
    public ?array $lengths;
    public int|string $num_rows;
    public int $type;

    public function __construct(mysqli $mysql, int $result_mode = 0)
    {
        throw new Error('mysqli: not implemented');
    }

    public function close(): void
    {
        throw new Error('mysqli: not implemented');
    }

    public function data_seek(int $offset): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_all(int $mode = 2): array
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_array(int $mode = 3): array|false|null
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_assoc(): array|false|null
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_column(int $column = 0): false|float|int|string|null
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_field(): false|object
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_field_direct(int $index): false|object
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_fields(): array
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_object(string $class = 'stdClass', array $constructor_args = []): false|object|null
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_row(): array|false|null
    {
        throw new Error('mysqli: not implemented');
    }

    public function field_seek(int $index): true
    {
        throw new Error('mysqli: not implemented');
    }

    public function free(): void
    {
        throw new Error('mysqli: not implemented');
    }

    public function free_result(): void
    {
        throw new Error('mysqli: not implemented');
    }

    public function getIterator(): Iterator
    {
        throw new Error('mysqli: not implemented');
    }
}

final class mysqli_sql_exception extends RuntimeException
{
    protected string $sqlstate = '00000';

    public function getSqlState(): string
    {
        return $this->sqlstate;
    }
}

class mysqli_stmt
{
    public int|string $affected_rows;
    public int $errno;
    public string $error;
    public array $error_list;
    public int $field_count;
    public int $id;
    public int|string $insert_id;
    public int|string $num_rows;
    public int $param_count;
    public string $sqlstate;

    public function __construct(mysqli $mysql, ?string $query = null)
    {
        throw new Error('mysqli: not implemented');
    }

    public function attr_get(int $attribute): int
    {
        throw new Error('mysqli: not implemented');
    }

    public function attr_set(int $attribute, int $value): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function bind_result(mixed &...$vars): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function close(): true
    {
        throw new Error('mysqli: not implemented');
    }

    public function data_seek(int $offset): void
    {
        throw new Error('mysqli: not implemented');
    }

    public function execute(?array $params = null): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch(): ?bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function free_result(): void
    {
        throw new Error('mysqli: not implemented');
    }

    public function get_result(): false|mysqli_result
    {
        throw new Error('mysqli: not implemented');
    }

    public function get_warnings(): false|mysqli_warning
    {
        throw new Error('mysqli: not implemented');
    }

    public function more_results(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function next_result(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function num_rows(): int|string
    {
        throw new Error('mysqli: not implemented');
    }

    public function prepare(string $query): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function reset(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function result_metadata(): false|mysqli_result
    {
        throw new Error('mysqli: not implemented');
    }

    public function send_long_data(int $param_num, string $data): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function store_result(): bool
    {
        throw new Error('mysqli: not implemented');
    }
}

final class mysqli_warning
{
    public int $errno;
    public string $message;
    public string $sqlstate;

    private function __construct()
    {
        throw new Error('mysqli: not implemented');
    }

    public function next(): bool
    {
        throw new Error('mysqli: not implemented');
    }
}
