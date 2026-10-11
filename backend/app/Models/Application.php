<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'title',
    'inventor_name',
    'inventor_email',
    'status',
    'application_number',
    'nipo_reference',
    'technology_area',
])]
class Application extends Model
{
    use HasUuids;

    /** Mirrors the `status` column default so in-memory instances match the DB. */
    protected $attributes = [
        'status' => 'DRAFT',
    ];
}
