<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class LogActivity extends Model
{
    use HasUuids;

    protected $table = 'log_activity';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'userId',
        'tableName',
        'action',
        'oldValues',
        'newValues'
    ];

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }
}
