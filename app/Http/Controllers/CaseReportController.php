<?php

namespace App\Http\Controllers;

use App\Services\CaseAccessScope;
use App\Services\CaseReports;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Printable case reports (see App\Services\CaseReports for the content and
 * privacy rules). Printed or saved as PDF from the browser.
 *
 * Access: the reports.social.generate permission and case scope. Every
 * generation is written to the audit log with the report type.
 */
class CaseReportController extends Controller
{
    public function __construct(private readonly CaseAccessScope $scope, private readonly CaseReports $reports) {}

    public function show(Request $request, int $case, string $type)
    {
        abort_unless(isset(CaseReports::TYPES[$type]), 404);
        $this->authorize('reports.social.generate');
        $record = DB::table('cases')->whereNull('deleted_at')->find($case);
        abort_unless($record, 404);
        $client = DB::table('clients')->find($record->client_id);
        abort_unless($client && $this->scope->allowsCase($record, $request->user(), $client->municipality, $client->province), 403);

        $period = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $data = $this->reports->build($record, $client, $type, $request->user(), $type === 'notes' ? $period : []);

        AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'case_report.generated', 'auditable_type' => 'case', 'auditable_id' => $case,
            'changes' => json_encode(['type' => $type] + array_filter($period)), 'created_at' => now(), 'updated_at' => now()]);

        return Inertia::render('Reports/CaseReport', $data)->toResponse($request)
            ->withHeaders(['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache']);
    }
}
