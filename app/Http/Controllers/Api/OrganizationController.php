<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BrandSetting;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** The current agency's profile, branding and audit trail (Admin > Settings). */
class OrganizationController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => app(AuthController::class)->organizationPayload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin');
        $org = tenant()->organization();
        $org->update($request->validate([
            'name' => 'sometimes|string|max:120',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:32',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'country' => 'sometimes|string|max:64',
            'currency' => 'sometimes|string|size:3',
            'timezone' => 'sometimes|timezone',
            'license_number' => 'nullable|string|max:64',
            'default_commission_rate' => 'sometimes|numeric|min:0|max:100',
        ]));
        AuditLogger::log('organization.updated', $org, 'Updated agency settings');

        return $this->show();
    }

    public function brand(): JsonResponse
    {
        $brand = BrandSetting::firstOrNew(['organization_id' => tenant()->id()]);

        return response()->json(['data' => [...tenant()->organization()->brandPayload(), 'reply_to_email' => $brand->reply_to_email]]);
    }

    public function updateBrand(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin');
        $hex = 'regex:/^#[0-9a-fA-F]{6}$/';
        $data = $request->validate([
            'display_name' => 'nullable|string|max:120',
            'tagline' => 'nullable|string|max:190',
            'light_primary_hex' => ['sometimes', $hex],
            'light_accent_hex' => ['sometimes', $hex],
            'dark_primary_hex' => ['sometimes', $hex],
            'dark_accent_hex' => ['sometimes', $hex],
            'reply_to_email' => 'nullable|email',
            'logo' => 'nullable|image|max:2048',
            'remove_logo' => 'sometimes|boolean',
        ]);

        $brand = BrandSetting::firstOrNew(['organization_id' => tenant()->id()]);
        if ($request->hasFile('logo')) {
            if ($brand->logo_path && ! str_starts_with($brand->logo_path, '/')) {
                Storage::disk('public')->delete($brand->logo_path);
            }
            $brand->logo_path = $request->file('logo')->store('brand/'.tenant()->id(), 'public');
        } elseif ($request->boolean('remove_logo')) {
            $brand->logo_path = null;
        }
        $brand->fill(collect($data)->except(['logo', 'remove_logo'])->all())->save();
        AuditLogger::log('brand.updated', $brand, 'Updated branding');

        return $this->brand();
    }

    public function auditLog(Request $request): JsonResponse
    {
        $this->requireRole('owner', 'admin');

        return response()->json(AuditLog::with('user:id,name')
            ->when($request->action, fn ($q, $v) => $q->where('action', 'like', "{$v}%"))
            ->when($request->q, fn ($q, $v) => $q->where('description', 'like', "%{$v}%"))
            ->latest()->paginate($this->perPage()));
    }
}
