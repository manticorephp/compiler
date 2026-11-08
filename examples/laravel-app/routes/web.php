<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/hello', fn () => 'hello from manticore');

Route::get('/counter', function (Illuminate\Http\Request $request) {
    $n = (int) $request->session()->get('n', 0) + 1;
    $request->session()->put('n', $n);

    return response()->json(['n' => $n, 'now' => now()->format('Y')]);
});
