<?php

/** ext/mysqli procedural API (skeleton): see mysqli.php. */

function mysqli_affected_rows(mysqli $mysql): int|string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_autocommit(mysqli $mysql, bool $enable): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_begin_transaction(mysqli $mysql, int $flags = 0, ?string $name = null): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_change_user(mysqli $mysql, string $username, #[\SensitiveParameter] string $password, ?string $database): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_character_set_name(mysqli $mysql): string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_close(mysqli $mysql): true
{
    throw new Error('mysqli: not implemented');
}

function mysqli_commit(mysqli $mysql, int $flags = 0, ?string $name = null): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_connect(?string $hostname = null, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null): false|mysqli
{
    throw new Error('mysqli: not implemented');
}

function mysqli_connect_errno(): int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_connect_error(): ?string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_data_seek(mysqli_result $result, int $offset): bool
{
    throw new Error('mysqli: not implemented');
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
    throw new Error('mysqli: not implemented');
}

function mysqli_error(mysqli $mysql): string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_error_list(mysqli $mysql): array
{
    throw new Error('mysqli: not implemented');
}

function mysqli_escape_string(mysqli $mysql, string $string): string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_execute(mysqli_stmt $statement, ?array $params = null): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_execute_query(mysqli $mysql, string $query, ?array $params = null): bool|mysqli_result
{
    throw new Error('mysqli: not implemented');
}

function mysqli_fetch_all(mysqli_result $result, int $mode = 2): array
{
    throw new Error('mysqli: not implemented');
}

function mysqli_fetch_array(mysqli_result $result, int $mode = 3): array|false|null
{
    throw new Error('mysqli: not implemented');
}

function mysqli_fetch_assoc(mysqli_result $result): array|false|null
{
    throw new Error('mysqli: not implemented');
}

function mysqli_fetch_column(mysqli_result $result, int $column = 0): false|float|int|string|null
{
    throw new Error('mysqli: not implemented');
}

function mysqli_fetch_field(mysqli_result $result): false|object
{
    throw new Error('mysqli: not implemented');
}

function mysqli_fetch_field_direct(mysqli_result $result, int $index): false|object
{
    throw new Error('mysqli: not implemented');
}

function mysqli_fetch_fields(mysqli_result $result): array
{
    throw new Error('mysqli: not implemented');
}

function mysqli_fetch_lengths(mysqli_result $result): array|false
{
    throw new Error('mysqli: not implemented');
}

function mysqli_fetch_object(mysqli_result $result, string $class = 'stdClass', array $constructor_args = []): false|object|null
{
    throw new Error('mysqli: not implemented');
}

function mysqli_fetch_row(mysqli_result $result): array|false|null
{
    throw new Error('mysqli: not implemented');
}

function mysqli_field_count(mysqli $mysql): int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_field_seek(mysqli_result $result, int $index): true
{
    throw new Error('mysqli: not implemented');
}

function mysqli_field_tell(mysqli_result $result): int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_free_result(mysqli_result $result): void
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_charset(mysqli $mysql): ?object
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_client_info(?mysqli $mysql = null): string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_client_stats(): array
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_client_version(): int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_connection_stats(mysqli $mysql): array
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_host_info(mysqli $mysql): string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_links_stats(): array
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_proto_info(mysqli $mysql): int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_server_info(mysqli $mysql): string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_server_version(mysqli $mysql): int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_get_warnings(mysqli $mysql): false|mysqli_warning
{
    throw new Error('mysqli: not implemented');
}

function mysqli_info(mysqli $mysql): ?string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_init(): false|mysqli
{
    throw new Error('mysqli: not implemented');
}

function mysqli_insert_id(mysqli $mysql): int|string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_kill(mysqli $mysql, int $process_id): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_more_results(mysqli $mysql): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_multi_query(mysqli $mysql, string $query): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_next_result(mysqli $mysql): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_num_fields(mysqli_result $result): int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_num_rows(mysqli_result $result): int|string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_options(mysqli $mysql, int $option, $value): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_ping(mysqli $mysql): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_poll(?array &$read, ?array &$error, array &$reject, int $seconds, int $microseconds = 0): false|int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_prepare(mysqli $mysql, string $query): false|mysqli_stmt
{
    throw new Error('mysqli: not implemented');
}

function mysqli_query(mysqli $mysql, string $query, int $result_mode = 0): bool|mysqli_result
{
    throw new Error('mysqli: not implemented');
}

function mysqli_real_connect(mysqli $mysql, ?string $hostname = null, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null, int $flags = 0): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_real_escape_string(mysqli $mysql, string $string): string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_real_query(mysqli $mysql, string $query): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_reap_async_query(mysqli $mysql): bool|mysqli_result
{
    throw new Error('mysqli: not implemented');
}

function mysqli_refresh(mysqli $mysql, int $flags): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_release_savepoint(mysqli $mysql, string $name): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_report(int $flags): true
{
    throw new Error('mysqli: not implemented');
}

function mysqli_rollback(mysqli $mysql, int $flags = 0, ?string $name = null): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_savepoint(mysqli $mysql, string $name): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_select_db(mysqli $mysql, string $database): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_set_charset(mysqli $mysql, string $charset): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_set_opt(mysqli $mysql, int $option, $value): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_sqlstate(mysqli $mysql): string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_ssl_set(mysqli $mysql, ?string $key, ?string $certificate, ?string $ca_certificate, ?string $ca_path, ?string $cipher_algos): true
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stat(mysqli $mysql): false|string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_affected_rows(mysqli_stmt $statement): int|string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_attr_get(mysqli_stmt $statement, int $attribute): int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_attr_set(mysqli_stmt $statement, int $attribute, int $value): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_bind_param(mysqli_stmt $statement, string $types, mixed &...$vars): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_bind_result(mysqli_stmt $statement, mixed &...$vars): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_close(mysqli_stmt $statement): true
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_data_seek(mysqli_stmt $statement, int $offset): void
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_errno(mysqli_stmt $statement): int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_error(mysqli_stmt $statement): string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_error_list(mysqli_stmt $statement): array
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_execute(mysqli_stmt $statement, ?array $params = null): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_fetch(mysqli_stmt $statement): ?bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_field_count(mysqli_stmt $statement): int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_free_result(mysqli_stmt $statement): void
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_get_result(mysqli_stmt $statement): false|mysqli_result
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_get_warnings(mysqli_stmt $statement): false|mysqli_warning
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_init(mysqli $mysql): false|mysqli_stmt
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_insert_id(mysqli_stmt $statement): int|string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_more_results(mysqli_stmt $statement): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_next_result(mysqli_stmt $statement): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_num_rows(mysqli_stmt $statement): int|string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_param_count(mysqli_stmt $statement): int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_prepare(mysqli_stmt $statement, string $query): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_reset(mysqli_stmt $statement): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_result_metadata(mysqli_stmt $statement): false|mysqli_result
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_send_long_data(mysqli_stmt $statement, int $param_num, string $data): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_sqlstate(mysqli_stmt $statement): string
{
    throw new Error('mysqli: not implemented');
}

function mysqli_stmt_store_result(mysqli_stmt $statement): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_store_result(mysqli $mysql, int $mode = 0): false|mysqli_result
{
    throw new Error('mysqli: not implemented');
}

function mysqli_thread_id(mysqli $mysql): int
{
    throw new Error('mysqli: not implemented');
}

function mysqli_thread_safe(): bool
{
    throw new Error('mysqli: not implemented');
}

function mysqli_use_result(mysqli $mysql): false|mysqli_result
{
    throw new Error('mysqli: not implemented');
}

function mysqli_warning_count(mysqli $mysql): int
{
    throw new Error('mysqli: not implemented');
}
