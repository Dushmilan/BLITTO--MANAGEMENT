<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['application_id', 'old_status', 'new_status', 'changed_by', 'reason'])]
class StatusHistory extends Model
{
    use HasUuids;

    /** Not pluralized — `status_history` is the domain's established name. */
    protected $table = 'status_history';

    public const CREATED_AT = 'changed_at';

    public const UPDATED_AT = null;
}
