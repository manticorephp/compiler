<?php
// json's error model: a failed encode answers false, a failed decode answers
// null, and json_last_error()/json_last_error_msg() say why. Before this the
// whole API was absent, INF/NAN encoded as the number 0, and $depth was a
// parameter that changed nothing.

echo json_encode([1, 2]), " ", json_last_error(), " ", json_last_error_msg(), "\n";

// Depth: php counts CONTAINERS and fails the whole call.
var_dump(json_encode([[[1]]], 0, 2));
echo json_last_error(), " ", json_last_error_msg(), "\n";
var_dump(json_encode([[[1]]], 0, 8));
echo json_last_error(), "\n";

var_dump(json_decode('[[[1]]]', true, 2));
echo json_last_error(), " ", json_last_error_msg(), "\n";
var_dump(json_decode('[[[1]]]', true, 8));
echo json_last_error(), "\n";

// INF / NAN cannot be represented.
var_dump(json_encode(INF));
echo json_last_error(), " ", json_last_error_msg(), "\n";
var_dump(json_encode(NAN));
echo json_last_error(), "\n";
var_dump(json_encode([1.0, INF]));
echo json_last_error(), "\n";

// A good call clears the slot again.
echo json_encode(["ok" => true]), " ", json_last_error(), "\n";

// JSON_THROW_ON_ERROR raises and leaves the slot at NONE.
try {
    json_encode(NAN, JSON_THROW_ON_ERROR);
    echo "no throw\n";
} catch (JsonException $e) {
    echo "caught ", $e->getMessage(), " code=", $e->getCode(), " last=", json_last_error(), "\n";
}

try {
    json_decode('[[[1]]]', true, 2, JSON_THROW_ON_ERROR);
    echo "no throw\n";
} catch (JsonException $e) {
    echo "caught ", $e->getMessage(), " code=", $e->getCode(), "\n";
}

// json_validate reports without keeping the result.
var_dump(json_validate('{"a":1}'));
var_dump(json_validate('[[[1]]]', 2));
echo json_last_error(), "\n";

// The constants exist and carry php's values.
echo JSON_ERROR_NONE, JSON_ERROR_DEPTH, JSON_ERROR_STATE_MISMATCH,
     JSON_ERROR_CTRL_CHAR, JSON_ERROR_SYNTAX, JSON_ERROR_UTF8, "\n";
echo JSON_ERROR_RECURSION, " ", JSON_ERROR_INF_OR_NAN, " ",
     JSON_ERROR_UNSUPPORTED_TYPE, " ", JSON_ERROR_INVALID_PROPERTY_NAME, " ",
     JSON_ERROR_UTF16, " ", JSON_ERROR_NON_BACKED_ENUM, "\n";
