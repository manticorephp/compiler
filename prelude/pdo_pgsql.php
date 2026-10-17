<?php

/**
 * pdo_pgsql — the PostgreSQL driver behind PDO, speaking the wire protocol
 * over a plain socket (no libpq). Implements the {@see __McPdoDrv} seam.
 */
function __mc_pdo_pgsql_open(string $rest, array $options, ?string $user, ?string $pw): __McPdoDrv
{
    throw new PDOException('driver not implemented');
}
