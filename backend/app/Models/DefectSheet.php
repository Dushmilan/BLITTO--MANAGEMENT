<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['application_id', 'sheet_number', 'description'])]
class DefectSheet extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;
}
