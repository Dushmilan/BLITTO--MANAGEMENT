<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['application_id', 'inventor_name', 'inventor_email'])]
class Inventor extends Model
{
    use HasUuids;

    public $timestamps = false;
}
