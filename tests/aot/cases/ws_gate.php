<?php

// MANTICORE-ONLY: php has no Http\WebSocket. Expected output written by hand.
// A program that names Http\WebSocket gets the module (and Http\ with it).

use Http\WebSocket\Opcode;

echo Opcode::TEXT, ' ', Opcode::BINARY, ' ', Opcode::CLOSE, ' ', Opcode::PING, ' ', Opcode::PONG, "\n";
echo \Http\WebSocket\Opcode::GUID, "\n";
