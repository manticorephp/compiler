<?php

final class AppConfig
{
    /**
     * @param array{
     *     debug?: bool, // Whether debug is enabled
     *     env?: string, # Current environment
     *     /* block comment *\/
     *     worker_loop_max?: int, // Use 0 or a negative integer to never restart the worker. Default: 1000
     * } $options
     *
     * @return array{
     *     env: string, // Resolved environment
     *     debug: bool, /* inline comment *\/
     * }
     */
    public static function process(array $options): array
    {
        $env = $options['env'] ?? 'prod';
        $debug = $options['debug'] ?? false;
        $workerMax = $options['worker_loop_max'] ?? 1000;
        echo "env: $env, debug: " . ($debug ? '1' : '0') . ", max: $workerMax\n";
        return ['env' => $env, 'debug' => $debug];
    }
}

$res = AppConfig::process(['env' => 'dev', 'debug' => true, 'worker_loop_max' => 500]);
var_dump($res['env'], $res['debug']);

$res2 = AppConfig::process([]);
var_dump($res2['env'], $res2['debug']);
