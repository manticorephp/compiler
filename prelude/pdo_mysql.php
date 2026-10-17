<?php

/**
 * pdo_mysql — the MySQL driver behind PDO, speaking the wire protocol over a
 * plain socket (no libmysqlclient). Implements the {@see __McPdoDrv} seam.
 */
function __mc_pdo_mysql_open(string $rest, array $options, ?string $user, ?string $pw): __McPdoDrv
{
    throw new PDOException('driver not implemented');
}
