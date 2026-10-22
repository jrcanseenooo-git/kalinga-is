<?php

namespace App\Http\Controllers;

use App\Services\CaseAccessScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function __construct(private readonly CaseAccessScope $scope) {}

    public function __invoke(Request $request)
    {
        $this->authorize('manage-casework');

        $query = trim((string) $request->validate(['q' => 'required|string|min:2|max:100'])['q']);
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], strtolower($query));
        $like = '%' . $escaped . '%';
        $cases = DB::table('cases')
            ->join('clients', 'clients.id', '=', 'cases.client_id')
            ->whereNull('cases.deleted_at')
            ->where(function ($client) {
                $client->whereNull('clients.identity_status')->orWhere('clients.identity_status', 'active');
            })
            ->where(fn ($case) => $case
                ->whereRaw('LOWER(cases.case_number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(clients.first_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(clients.last_name) LIKE ?', [$like]));

        $this->scope->scopeCases($cases, $request->user());

        $reports = DB::table('reports')->whereRaw('LOWER(reference_no) LIKE ?', [$like]);

        $municipality = trim((string) $request->user()->municipality);
        if ($municipality === '') {
            $reports->whereRaw('1 = 0');
        } else {
            $reports
                ->where('is_public_submission', true)
                ->where('endorsed_municipality', $municipality);
        }

        return response()->json([
            'cases' => $cases->limit(10)->get(['cases.id', 'cases.case_number', 'cases.status', 'clients.first_name', 'clients.last_name']),
            'reports' => $reports->limit(10)->get(['id', 'reference_no', 'status', 'priority']),
            'providers' => DB::table('service_providers')
                ->whereRaw('LOWER(name) LIKE ?', [$like])
                ->limit(10)
                ->get(['id', 'name', 'service_type']),
        ]);
    }
}
