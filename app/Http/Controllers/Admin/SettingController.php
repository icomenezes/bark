<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function edit()
    {
        $settings = Setting::current();
        return view('admin.settings.edit', compact('settings'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'company_name'      => ['required', 'string', 'max:100'],
            'primary_color'     => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_color'      => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'support_email'     => ['nullable', 'email', 'max:150'],
            'support_whatsapp'  => ['nullable', 'string', 'max:20'],
            'logo'              => ['nullable', 'image', 'max:1024', 'mimes:png,jpg,jpeg,svg,webp'],
            'favicon'           => ['nullable', 'image', 'max:256', 'mimes:png,ico,svg'],
        ]);

        $settings = Setting::current();
        $data     = $request->only(['company_name', 'primary_color', 'accent_color', 'support_email', 'support_whatsapp']);
        $data['whatsapp_enabled'] = $request->boolean('whatsapp_enabled');

        if ($request->hasFile('logo')) {
            if ($settings->logo_url) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $settings->logo_url));
            }
            $path = $request->file('logo')->store('branding', 'public');
            $data['logo_url'] = '/storage/' . $path;
        }

        if ($request->hasFile('favicon')) {
            if ($settings->favicon_url) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $settings->favicon_url));
            }
            $path = $request->file('favicon')->store('branding', 'public');
            $data['favicon_url'] = '/storage/' . $path;
        }

        $settings->update($data);
        Setting::clearCache();

        return back()->with('success', 'Configurações salvas com sucesso.');
    }
}
