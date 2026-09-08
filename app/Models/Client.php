<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Client of a tenant.
 *
 * There is no global tenant scope — every query must filter by `tenant_id`
 * explicitly (jobs have no request context).
 *
 * @property int         $id
 * @property int         $tenant_id
 * @property string      $email
 * @property string|null $name
 * @property mixed       $suppressed_at
 */
class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'email',
        'name',
        'suppressed_at',
    ];

    protected $casts = [
        'suppressed_at' => 'datetime',
    ];

    public function enrollments()
    {
        return $this->hasMany(CampaignEnrollment::class);
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed_at !== null;
    }
}
