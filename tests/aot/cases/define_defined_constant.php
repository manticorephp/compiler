<?php
// define() / defined() / constant() with compile-time constant values.
define("APP_NAME", "manticore");
define("MAX_RETRIES", 3);
define("TWO_PI", M_PI * 2);
define("FLAG_ON", true);

echo APP_NAME, "\n";
echo MAX_RETRIES, "\n";
printf("%.5f\n", TWO_PI);
var_dump(FLAG_ON);

var_dump(defined("APP_NAME"));
var_dump(defined("MAX_RETRIES"));
var_dump(defined("DOES_NOT_EXIST"));
var_dump(defined("PHP_EOL"));

echo constant("APP_NAME"), "\n";
echo constant("MAX_RETRIES"), "\n";
echo constant("PHP_INT_SIZE"), "\n";
