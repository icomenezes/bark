<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = [
        'company_name', 'logo_url', 'favicon_url',
        'primary_color', 'accent_color',
        'support_email', 'support_whatsapp', 'whatsapp_enabled',
        'platform_certificate_id',
    ];

    protected $casts = [
        'whatsapp_enabled' => 'boolean',
    ];

    public function platformCertificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'platform_certificate_id');
    }

    public static function current(): self
    {
        $id = Cache::get('settings_id');

        if ($id) {
            $model = self::find($id);
            if ($model) return $model;
        }

        Cache::forget('settings_id');

        $model = self::firstOrCreate([], [
            'company_name'  => config('app.name'),
            'primary_color' => '#1e40af',
            'accent_color'  => '#3b82f6',
        ]);

        Cache::put('settings_id', $model->id, 300);

        return $model;
    }

    public static function clearCache(): void
    {
        Cache::forget('settings_id');
    }
}
