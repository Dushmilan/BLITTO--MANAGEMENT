<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email', 'role', 'user_code', 'password_hash'])]
class User extends Model implements AuthenticatableContract
{
    use Authenticatable, HasFactory, HasUuids;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $hidden = ['password_hash'];

    /**
     * The domain credential column (mirrors the Pydantic `User` model); there
     * is no Laravel `password` column, so the auth trait is pointed here.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * The `users` table has no remember-me column (session auth only).
     */
    public function getRememberTokenName(): string
    {
        return '';
    }
}
