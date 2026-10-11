<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['application_id', 'type', 'due_date', 'status'])]
class Deadline extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;
}
