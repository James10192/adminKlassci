<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TenantLimitsController extends Controller
{
    /**
     * Get tenant limits and current usage
     *
     * @param string $code Tenant code
     * @return JsonResponse
     */
    public function show(string $code): JsonResponse
    {
        // Find tenant by code
        $tenant = Tenant::where('code', $code)
            ->where('status', '!=', 'deleted')
            ->first();

        if (!$tenant) {
            return response()->json([
                'error' => 'Tenant not found',
                'message' => "No tenant found with code: {$code}",
            ], 404);
        }

        // Check if subscription is expired
        $isExpired = $tenant->subscription_end_date &&
                     $tenant->subscription_end_date->isPast();

        // Check if over quota
        $isOverQuota = $tenant->isOverQuota();

        // Determine blocked features based on quota status
        $blockedFeatures = [];

        if ($tenant->isOverLimit('users') || $tenant->isOverLimit('staff')) {
            $blockedFeatures[] = 'create_user';
            $blockedFeatures[] = 'create_staff';
        }

        if ($tenant->isOverLimit('students')) {
            $blockedFeatures[] = 'create_student_account';
        }

        if ($tenant->isOverLimit('inscriptions')) {
            $blockedFeatures[] = 'create_inscription';
            $blockedFeatures[] = 'create_reinscription';
        }

        if ($tenant->isOverLimit('storage')) {
            $blockedFeatures[] = 'upload_file';
        }

        if ($isExpired) {
            $blockedFeatures = array_merge($blockedFeatures, [
                'create_user',
                'create_staff',
                'create_student_account',
                'create_inscription',
                'create_reinscription',
                'upload_file',
            ]);
        }

        $daysRemaining = $tenant->subscription_end_date
            ? now()->diffInDays($tenant->subscription_end_date, false)
            : null;

        return response()->json([
            'tenant_code' => $tenant->code,
            'tenant_name' => $tenant->name,
            'plan' => $tenant->plan,
            'status' => $tenant->status,
            'subscription' => [
                'start_date' => $tenant->subscription_start_date?->format('Y-m-d'),
                'end_date' => $tenant->subscription_end_date?->format('Y-m-d'),
                'is_expired' => $isExpired,
                'days_remaining' => $daysRemaining,
                'show_warning' => $isExpired || ($daysRemaining !== null && $daysRemaining <= 30),
                'urgency' => $isExpired || $daysRemaining <= 0 ? 'expired'
                    : ($daysRemaining <= 7 ? 'red'
                    : ($daysRemaining <= 14 ? 'orange'
                    : ($daysRemaining <= 30 ? 'green' : null))),
            ],
            'limits' => [
                'max_users' => $tenant->max_users,
                'max_staff' => $tenant->max_staff,
                'max_students' => $tenant->max_students,
                'max_inscriptions_per_year' => $tenant->max_inscriptions_per_year,
                'max_storage_mb' => $tenant->max_storage_mb,
            ],
            'current_usage' => [
                'users' => $tenant->current_users,
                'staff' => $tenant->current_staff,
                'students' => $tenant->current_students,
                'inscriptions_per_year' => $tenant->current_inscriptions_per_year,
                'storage_mb' => $tenant->current_storage_mb,
            ],
            'usage_percentage' => [
                'users' => $tenant->max_users > 0 ?
                    round(($tenant->current_users / $tenant->max_users) * 100, 2) : 0,
                'staff' => $tenant->max_staff > 0 ?
                    round(($tenant->current_staff / $tenant->max_staff) * 100, 2) : 0,
                'students' => $tenant->max_students > 0 ?
                    round(($tenant->current_students / $tenant->max_students) * 100, 2) : 0,
                'inscriptions' => $tenant->max_inscriptions_per_year > 0 ?
                    round(($tenant->current_inscriptions_per_year / $tenant->max_inscriptions_per_year) * 100, 2) : 0,
                'storage' => $tenant->max_storage_mb > 0 ?
                    round(($tenant->current_storage_mb / $tenant->max_storage_mb) * 100, 2) : 0,
            ],
            'quota_status' => [
                'is_over_quota' => $isOverQuota,
                'users_over_limit' => $tenant->isOverLimit('users'),
                'staff_over_limit' => $tenant->isOverLimit('staff'),
                'students_over_limit' => $tenant->isOverLimit('students'),
                'inscriptions_over_limit' => $tenant->isOverLimit('inscriptions'),
                'storage_over_limit' => $tenant->isOverLimit('storage'),
            ],
            'blocked_features' => array_unique($blockedFeatures),
            'last_stats_update' => $tenant->updated_at->toIso8601String(),
        ], 200);
    }
}
