<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ServiceStatusLog extends Model
{
    use HasFactory;

    protected $table = 'service_status_logs';

    protected $fillable = [
        'service_id',
        'status_id',
        'user_id',
        'started_at',
        'finished_at',
        'finished_by'
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function finisher()
    {
        return $this->belongsTo(\App\Models\User::class, 'finished_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */
    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
