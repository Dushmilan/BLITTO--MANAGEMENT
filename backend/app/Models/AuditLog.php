<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'application_id', 'action', 'ip_address'])]
class AuditLog extends Model
{
    use HasUuids;

    public const CREATED_AT = 'timestamp';

    public const UPDATED_AT = null;
}
