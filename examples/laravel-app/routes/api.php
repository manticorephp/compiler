<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function (Request $request) {
    return response()->json([
        'ok' => true,
        'name' => $request->query('name', 'world'),
        'items' => collect([3, 1, 2])->sort()->values()->all(),
    ]);
});

Route::post('/echo', function (Request $request) {
    return response()->json(['got' => $request->all()]);
});

use App\Models\Note;
use Illuminate\Support\Facades\DB;

Route::get('/db/version', fn () => ['sqlite' => DB::selectOne('select sqlite_version() as v')->v]);

Route::get('/db/count', fn () => ['notes' => DB::table('notes')->count()]);

Route::get('/notes', fn () => Note::orderBy('id')->get());

Route::get('/notes/{id}', fn (int $id) => Note::findOrFail($id));

Route::post('/notes', function (Request $request) {
    $note = Note::create([
        'title' => (string) $request->input('title', 'untitled'),
        'body' => $request->input('body'),
        'done' => (bool) $request->input('done', false),
    ]);

    return response()->json($note, 201);
});

Route::patch('/notes/{id}', function (Request $request, int $id) {
    $note = Note::findOrFail($id);
    $note->update($request->only(['title', 'body', 'done']));

    return $note;
});

Route::delete('/notes/{id}', function (int $id) {
    Note::findOrFail($id)->delete();

    return response()->noContent();
});

