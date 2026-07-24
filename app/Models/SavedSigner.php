<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SavedSigner extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'channel', 'email', 'whatsapp', 'auth_method'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(SignerGroup::class, 'signer_group_members');
    }
}
