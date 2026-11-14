<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    // Named, not derived: the default is Str::snake(Str::pluralStudly(class_basename())) per query.
    protected $table = 'notes';

    protected $fillable = ['title', 'body', 'done'];

    protected $casts = ['done' => 'boolean'];
}
