<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequireSingleCompanyLegacyApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = TenantContext::id();
        $companies = $tenantId === null
            ? collect()
            : DB::table('companies')
                ->where('tenant_id', $tenantId)
                ->limit(2)
                ->get(['id', 'status']);

        $reason = match (true) {
            $tenantId === null => 'tenant_context_missing',
            $companies->isEmpty() => 'tenant_has_no_company',
            $companies->count() > 1 => 'tenant_has_multiple_companies',
            ! (bool) $companies->first()->status => 'company_inactive',
            default => null,
        };

        if ($reason !== null) {
            Log::warning('Legacy tenant API request rejected because company binding is unavailable', [
                'tenant_id' => $tenantId,
                'reason' => $reason,
                'route' => $request->route()?->uri() ?? $request->path(),
            ]);

            return response()->json([
                'message' => 'This legacy API key requires exactly one company for company-specific access.',
                'code' => 'legacy_api_company_binding_required',
            ], 403);
        }

        $request->attributes->set('legacy_api_company_id', (int) $companies->first()->id);

        return $next($request);
    }
}
