<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['recipient_email', 'subject', 'body', 'kind'])]
class Notification extends Model
{
    use HasUuids;

    public const CREATED_AT = 'sent_at';

    public const UPDATED_AT = null;
}
