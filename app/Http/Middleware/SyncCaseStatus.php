<?php

namespace App\Http\Middleware;

use App\Services\CaseStatusAutomation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a successful change to a case (any write on a route with a {case}
 * or {referral}), bring the case status in line with the recorded work.
 * See App\Services\CaseStatusAutomation. Never fails the request.
 */
class SyncCaseStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->isMethodSafe() || !$request->user() || $response->getStatusCode() >= 400) {
            return $response;
        }
        try {
            $route = $request->route();
            $case = $route?->parameter('case');
            if (!$case && ($referral = $route?->parameter('referral'))) {
                $case = DB::table('referrals')->where('id', (int) $referral)->value('case_id');
            }
            foreach (['case_id', 'payload.case_id'] as $key) {
                if (!$case && is_numeric($request->input($key))) {
                    $case = (int) $request->input($key);
                }
            }
            if ($case && is_numeric($case)) {
                CaseStatusAutomation::sync((int) $case, (int) $request->user()->id);
            }
        } catch (\Throwable $error) {
            report($error);
        }

        return $response;
    }
}
