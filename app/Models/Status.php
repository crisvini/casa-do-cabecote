<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class Status extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'color'];

    protected $appends = ['slug']; // calculado a partir do name

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */
    public function servicesAsCurrent()
    {
        return $this->hasMany(Service::class, 'current_status_id');
    }

    public function flows()
    {
        return $this->hasMany(ServiceStatusFlow::class);
    }

    public function logs()
    {
        return $this->hasMany(ServiceStatusLog::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */
    public function getSlugAttribute(): string
    {
        // exemplo: "RETÍFICA" -> "retifica"
        return Str::slug($this->name, '-');
    }
}
