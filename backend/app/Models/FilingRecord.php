<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'application_id',
    'filed_date',
    'filed_by',
    'nipo_acknowledged_at',
    'nipo_acknowledged_by',
    'defect_sheet_count',
    'granted_at',
    'granted_by',
    'patent_number',
    'rejected_at',
    'rejected_by',
])]
class FilingRecord extends Model
{
    use HasUuids;

    public $timestamps = false;
}
