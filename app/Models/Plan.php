<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = ['name', 'max_pdfs_per_month', 'max_envelopes_per_month'];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
