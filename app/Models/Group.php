<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Group extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'logo_path',
        'description',
        'founded_year',
        'address',
        'phone',
        'email',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'founded_year' => 'integer',
    ];

    public function tenants()
    {
        return $this->hasMany(Tenant::class);
    }

    public function members()
    {
        return $this->hasMany(GroupMember::class);
    }

    public function activeTenants()
    {
        return $this->tenants()->where('status', 'active');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function getEstablishmentCountAttribute(): int
    {
        return $this->tenants()->count();
    }
}
