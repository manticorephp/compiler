<?php
class Req
{
    public function __construct(public array $query = [], public array $request = []) {}

    public static function make(string $method, array $parameters = []): static
    {
        if ($method === 'POST') {
            $request = $parameters;
            $query = [];
        } else {
            $request = [];
            $query = $parameters;
        }
        return new static($query, $request);
    }
}

final class CliReq extends Req {}

function split(string $method, array $parameters = []): string
{
    if ($method === 'POST') {
        $request = $parameters;
        $query = [];
    } else {
        $request = [];
        $query = $parameters;
    }
    return count($query) . ':' . count($request);
}

echo json_encode(Req::make('GET', ['b' => 2])->query), json_encode(Req::make('POST', ['b' => 2])->request), "\n";
echo json_encode(CliReq::make('GET', ['c' => 3])->query), "\n";
echo split('GET', [1, 2]), ' ', split('POST', ['x' => 1]), "\n";
