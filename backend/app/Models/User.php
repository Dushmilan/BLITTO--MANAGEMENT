<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email', 'role', 'user_code', 'password_hash'])]
class User extends Model
{
    use HasUuids;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;
}
