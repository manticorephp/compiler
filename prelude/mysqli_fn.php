<?php

/** ext/mysqli procedural API (skeleton): see mysqli.php. */

function mysqli_affected_rows(mysqli $mysql): int|string
{
    return __McMysqliConn::of($mysql)->link()->affectedRows;
}

function mysqli_autocommit(mysqli $mysql, bool $enable): bool
{
    return $mysql->autocommit($enable);
}

function mysqli_begin_transaction(mysqli $mysql, int $flags = 0, ?string $name = null): bool
{
    return $mysql->__mcBeginTx($flags, $name, 'mysqli_begin_transaction', 2);
}

function mysqli_change_user(mysqli $mysql, string $username, #[\SensitiveParameter] string $password, ?string $database): bool
{
    return $mysql->change_user($username, $password, $database);
}

function mysqli_character_set_name(mysqli $mysql): string
{
    return $mysql->character_set_name();
}

function mysqli_close(mysqli $mysql): true
{
    return $mysql->close();
}

function mysqli_commit(mysqli $mysql, int $flags = 0, ?string $name = null): bool
{
    return $mysql->commit($flags, $name);
}

function mysqli_connect(?string $hostname = null, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null): false|mysqli
{
    $m = new mysqli();
    return __McMysqliConn::of($m)->connect($hostname, $username, $password, $database, $port, $socket, 0, false) ? $m : false;
}

function mysqli_connect_errno(): int
{
    return __McMysqliConn::$connErrno;
}

function mysqli_connect_error(): ?string
{
    return __McMysqliConn::$connError;
}

function mysqli_data_seek(mysqli_result $result, int $offset): bool
{
    return $result->__mcDataSeek($offset, 'mysqli_data_seek', 2);
}

function mysqli_debug(string $options): true
{
    throw new Error('mysqli: not implemented');
}

function mysqli_dump_debug_info(mysqli $mysql): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_errno(mysqli $mysql): int
{
    return __McMysqliConn::of($mysql)->link()->errno;
}

function mysqli_error(mysqli $mysql): string
{
    return __McMysqliConn::of($mysql)->link()->error;
}

function mysqli_error_list(mysqli $mysql): array
{
    return __McMysqliConn::of($mysql)->link()->errorList;
}

function mysqli_escape_string(mysqli $mysql, string $string): string
{
    return $mysql->real_escape_string($string);
}

function mysqli_execute(mysqli_stmt $statement, ?array $params = null): bool
{
    return $statement->__mcExecute($params, 'mysqli_execute', 2);
}

function mysqli_execute_query(mysqli $mysql, string $query, ?array $params = null): bool|mysqli_result
{
    return $mysql->__mcExecuteQuery($query, $params, 'mysqli_execute_query', 3);
}

function mysqli_fetch_all(mysqli_result $result, int $mode = 2): array
{
    return $result->__mcFetchAll($mode, 'mysqli_fetch_all', 2);
}

function mysqli_fetch_array(mysqli_result $result, int $mode = 3): array|false|null
{
    return $result->__mcFetchArray($mode, 'mysqli_fetch_array', 2);
}

function mysqli_fetch_assoc(mysqli_result $result): array|false|null
{
    return $result->fetch_assoc();
}

function mysqli_fetch_column(mysqli_result $result, int $column = 0): false|float|int|string|null
{
    return $result->__mcFetchColumn($column, 'mysqli_fetch_column', 2);
}

function mysqli_fetch_field(mysqli_result $result): false|object
{
    return $result->fetch_field();
}

function mysqli_fetch_field_direct(mysqli_result $result, int $index): false|object
{
    return $result->__mcFieldDirect($index, 'mysqli_fetch_field_direct', 2);
}

/** @return array<int,object> */
function mysqli_fetch_fields(mysqli_result $result): array
{
    return $result->fetch_fields();
}

function mysqli_fetch_lengths(mysqli_result $result): array|false
{
    $rs = __McMysqliRs::of($result);
    $l = $rs->lengths();
    return $l === null ? false : $l;
}

function mysqli_fetch_object(mysqli_result $result, string $class = 'stdClass', array $constructor_args = []): false|object|null
{
    return $result->__mcFetchObject($class, $constructor_args, 'mysqli_fetch_object', 2);
}

function mysqli_fetch_row(mysqli_result $result): array|false|null
{
    return $result->fetch_row();
}

function mysqli_field_count(mysqli $mysql): int
{
    return __McMysqliConn::of($mysql)->link()->fieldCount;
}

function mysqli_field_seek(mysqli_result $result, int $index): true
{
    return $result->__mcFieldSeek($index, 'mysqli_field_seek', 2);
}

function mysqli_field_tell(mysqli_result $result): int
{
    return __McMysqliRs::of($result)->field;
}

function mysqli_free_result(mysqli_result $result): void
{
    $result->free();
}

function mysqli_get_charset(mysqli $mysql): ?object
{
    return $mysql->get_charset();
}

function mysqli_get_client_info(?mysqli $mysql = null): string
{
    return __McMysqliConn::clientInfo();
}

function mysqli_get_client_stats(): array
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_client_version(): int
{
    return \PHP_VERSION_ID;
}

function mysqli_get_connection_stats(mysqli $mysql): array
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_host_info(mysqli $mysql): string
{
    return __McMysqliConn::of($mysql)->link()->hostInfo;
}

function mysqli_get_links_stats(): array
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_proto_info(mysqli $mysql): int
{
    return __McMysqliConn::of($mysql)->link()->protocolVersion;
}

function mysqli_get_server_info(mysqli $mysql): string
{
    return __McMysqliConn::of($mysql)->link()->serverInfo;
}

function mysqli_get_server_version(mysqli $mysql): int
{
    return __McMysqliConn::of($mysql)->link()->serverVersion;
}

function mysqli_get_warnings(mysqli $mysql): false|mysqli_warning
{
    return $mysql->get_warnings();
}

function mysqli_info(mysqli $mysql): ?string
{
    return __McMysqliConn::of($mysql)->link()->info;
}

function mysqli_init(): false|mysqli
{
    return new mysqli();
}

function mysqli_insert_id(mysqli $mysql): int|string
{
    return __McMysqliConn::of($mysql)->link()->insertId;
}

function mysqli_kill(mysqli $mysql, int $process_id): bool
{
    return $mysql->__mcKill($process_id, 'mysqli_kill', 2);
}

function mysqli_more_results(mysqli $mysql): bool
{
    return $mysql->more_results();
}

function mysqli_multi_query(mysqli $mysql, string $query): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_next_result(mysqli $mysql): bool
{
    return $mysql->next_result();
}

function mysqli_num_fields(mysqli_result $result): int
{
    return __McMysqliRs::of($result)->fields();
}

function mysqli_num_rows(mysqli_result $result): int|string
{
    $rs = __McMysqliRs::of($result);
    if ($rs->unbuf) { throw new Error('mysqli_num_rows() cannot be used in MYSQLI_USE_RESULT mode'); }
    return $rs->numRows();
}

function mysqli_options(mysqli $mysql, int $option, $value): bool
{
    return $mysql->options($option, $value);
}

function mysqli_ping(mysqli $mysql): bool
{
    return $mysql->ping();
}

function mysqli_poll(?array &$read, ?array &$error, array &$reject, int $seconds, int $microseconds = 0): false|int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_prepare(mysqli $mysql, string $query): false|mysqli_stmt
{
    return $mysql->prepare($query);
}

function mysqli_query(mysqli $mysql, string $query, int $result_mode = 0): bool|mysqli_result
{
    return $mysql->__mcQuery($query, $result_mode, 'mysqli_query', 2);
}

function mysqli_real_connect(mysqli $mysql, ?string $hostname = null, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null, int $flags = 0): bool
{
    return $mysql->real_connect($hostname, $username, $password, $database, $port, $socket, $flags);
}

function mysqli_real_escape_string(mysqli $mysql, string $string): string
{
    return $mysql->real_escape_string($string);
}

function mysqli_real_query(mysqli $mysql, string $query): bool
{
    return $mysql->real_query($query);
}

function mysqli_reap_async_query(mysqli $mysql): bool|mysqli_result
{
    throw new Error('mysqli: not implemented');
}

function mysqli_refresh(mysqli $mysql, int $flags): bool
{
    return $mysql->refresh($flags);
}

function mysqli_release_savepoint(mysqli $mysql, string $name): bool
{
    return $mysql->__mcSavepoint('RELEASE SAVEPOINT', $name, 'mysqli_release_savepoint', 2);
}

function mysqli_report(int $flags): true
{
    __McMysqliConn::$mode = $flags;
    return true;
}

function mysqli_rollback(mysqli $mysql, int $flags = 0, ?string $name = null): bool
{
    return $mysql->rollback($flags, $name);
}

function mysqli_savepoint(mysqli $mysql, string $name): bool
{
    return $mysql->__mcSavepoint('SAVEPOINT', $name, 'mysqli_savepoint', 2);
}

function mysqli_select_db(mysqli $mysql, string $database): bool
{
    return $mysql->select_db($database);
}

function mysqli_set_charset(mysqli $mysql, string $charset): bool
{
    return $mysql->set_charset($charset);
}

function mysqli_set_opt(mysqli $mysql, int $option, $value): bool
{
    return $mysql->options($option, $value);
}

function mysqli_sqlstate(mysqli $mysql): string
{
    return __McMysqliConn::of($mysql)->link()->sqlstate;
}

function mysqli_ssl_set(mysqli $mysql, ?string $key, ?string $certificate, ?string $ca_certificate, ?string $ca_path, ?string $cipher_algos): true
{
    return $mysql->ssl_set($key, $certificate, $ca_certificate, $ca_path, $cipher_algos);
}

function mysqli_stat(mysqli $mysql): false|string
{
    return $mysql->stat();
}

function mysqli_stmt_affected_rows(mysqli_stmt $statement): int|string
{
    return __McMysqliStmt::of($statement, __McMysqliConn::VALID)->affected;
}

function mysqli_stmt_attr_get(mysqli_stmt $statement, int $attribute): int
{
    return $statement->__mcAttrGet($attribute, 'mysqli_stmt_attr_get', 2);
}

function mysqli_stmt_attr_set(mysqli_stmt $statement, int $attribute, int $value): bool
{
    return $statement->__mcAttrSet($attribute, $value, 'mysqli_stmt_attr_set', 2);
}

function mysqli_stmt_bind_param(mysqli_stmt $statement, string $types, mixed &...$vars): bool
{
    return $statement->__mcBindParam($types, 'mysqli_stmt_bind_param', 2, ...$vars);
}

function mysqli_stmt_bind_result(mysqli_stmt $statement, mixed &...$vars): bool
{
    return $statement->bind_result(...$vars);
}

function mysqli_stmt_close(mysqli_stmt $statement): true
{
    return $statement->close();
}

function mysqli_stmt_data_seek(mysqli_stmt $statement, int $offset): void
{
    $statement->__mcDataSeek($offset, 'mysqli_stmt_data_seek', 2);
}

function mysqli_stmt_errno(mysqli_stmt $statement): int
{
    return __McMysqliStmt::of($statement, __McMysqliConn::INIT)->errno;
}

function mysqli_stmt_error(mysqli_stmt $statement): string
{
    return __McMysqliStmt::of($statement, __McMysqliConn::INIT)->error;
}

function mysqli_stmt_error_list(mysqli_stmt $statement): array
{
    return __McMysqliStmt::of($statement, __McMysqliConn::INIT)->errorList;
}

function mysqli_stmt_execute(mysqli_stmt $statement, ?array $params = null): bool
{
    return $statement->__mcExecute($params, 'mysqli_stmt_execute', 2);
}

function mysqli_stmt_fetch(mysqli_stmt $statement): ?bool
{
    return $statement->fetch();
}

function mysqli_stmt_field_count(mysqli_stmt $statement): int
{
    return __McMysqliStmt::of($statement, __McMysqliConn::VALID)->fieldCount;
}

function mysqli_stmt_free_result(mysqli_stmt $statement): void
{
    $statement->free_result();
}

function mysqli_stmt_get_result(mysqli_stmt $statement): false|mysqli_result
{
    return $statement->get_result();
}

function mysqli_stmt_get_warnings(mysqli_stmt $statement): false|mysqli_warning
{
    return $statement->get_warnings();
}

function mysqli_stmt_init(mysqli $mysql): false|mysqli_stmt
{
    return $mysql->stmt_init();
}

function mysqli_stmt_insert_id(mysqli_stmt $statement): int|string
{
    return __McMysqliStmt::of($statement, __McMysqliConn::VALID)->insertId;
}

function mysqli_stmt_more_results(mysqli_stmt $statement): bool
{
    return $statement->more_results();
}

function mysqli_stmt_next_result(mysqli_stmt $statement): bool
{
    return $statement->next_result();
}

function mysqli_stmt_num_rows(mysqli_stmt $statement): int|string
{
    return $statement->num_rows();
}

function mysqli_stmt_param_count(mysqli_stmt $statement): int
{
    return __McMysqliStmt::of($statement, __McMysqliConn::VALID)->paramCount;
}

function mysqli_stmt_prepare(mysqli_stmt $statement, string $query): bool
{
    return $statement->prepare($query);
}

function mysqli_stmt_reset(mysqli_stmt $statement): bool
{
    return $statement->reset();
}

function mysqli_stmt_result_metadata(mysqli_stmt $statement): false|mysqli_result
{
    return $statement->result_metadata();
}

function mysqli_stmt_send_long_data(mysqli_stmt $statement, int $param_num, string $data): bool
{
    return $statement->__mcSendLongData($param_num, $data, 'mysqli_stmt_send_long_data', 2);
}

function mysqli_stmt_sqlstate(mysqli_stmt $statement): string
{
    return __McMysqliStmt::of($statement, __McMysqliConn::VALID)->sqlstate;
}

function mysqli_stmt_store_result(mysqli_stmt $statement): bool
{
    return $statement->store_result();
}

function mysqli_store_result(mysqli $mysql, int $mode = 0): false|mysqli_result
{
    return $mysql->store_result($mode);
}

function mysqli_thread_id(mysqli $mysql): int
{
    return __McMysqliConn::of($mysql)->link()->threadId;
}

function mysqli_thread_safe(): bool
{
    return true;
}

function mysqli_use_result(mysqli $mysql): false|mysqli_result
{
    return $mysql->use_result();
}

function mysqli_warning_count(mysqli $mysql): int
{
    return __McMysqliConn::of($mysql)->link()->warningCount;
}
