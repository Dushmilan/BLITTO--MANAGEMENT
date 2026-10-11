<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['application_id', 'office_action_id', 'body'])]
class Response extends Model
{
    use HasUuids;

    public const CREATED_AT = 'filed_at';

    public const UPDATED_AT = null;
}
