<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class JournalLine extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'journal_lines';

    protected $fillable = [
        'id',
        'journal_id',
        'account_id',
        'description',
        'debit',
        'credit',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}


