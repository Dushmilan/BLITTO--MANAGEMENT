<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['application_id', 'kind', 'body'])]
class OfficeAction extends Model
{
    use HasUuids;

    public const CREATED_AT = 'received_at';

    public const UPDATED_AT = null;
}
