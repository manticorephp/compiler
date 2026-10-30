<?php
date_default_timezone_set('UTC');
echo json_encode(new DateTimeImmutable('2024-01-01 00:00:00')), "\n";
echo json_encode(['d' => new DateTime('2024-01-01 00:00:00')]), "\n";
