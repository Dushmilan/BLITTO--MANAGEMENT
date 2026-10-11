<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['application_id', 'filename', 'file_size', 'content_ref', 'uploaded_by'])]
class Document extends Model
{
    use HasUuids;

    public const CREATED_AT = 'uploaded_at';

    public const UPDATED_AT = null;
}
