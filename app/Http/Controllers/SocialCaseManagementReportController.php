<?php

namespace App\Http\Controllers;

use App\Services\CaseAccessScope;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Per-case, permission-scoped report. Restricted modules are intentionally excluded pending template approval. */
class SocialCaseManagementReportController extends Controller
{
    public function __construct(private readonly CaseAccessScope $scope) {}

    private function report(Request $request, int $case): array
    {
        $this->authorize('reports.social.generate');
        $record = DB::table('cases')->whereNull('deleted_at')->find($case);
        abort_unless($record, 404);
        $client = DB::table('clients')->find($record->client_id);
        abort_unless($client && $this->scope->allowsCase($record, $request->user(), $client->municipality, $client->province), 403);

        return [
            'report_status' => 'draft',
            'template_version' => 'working-summary-v1',
            'approval_notice' => 'DRAFT - Not approved for official release',
            'prepared_by' => $request->user()->name,
            'source' => ['case_id' => $case, 'updated_at' => $record->updated_at],
            'case' => collect($record)->only(['case_number', 'status', 'stage', 'priority', 'category', 'opened_at', 'closed_at']),
            'client' => collect($client)->only(['first_name', 'last_name', 'birthdate', 'sex', 'municipality', 'province', 'region']),
            'plan' => DB::table('case_plans')->where('case_id', $case)->where('is_current', true)->first(['version', 'status', 'overall_objective', 'review_due_at']),
            'referrals' => DB::table('referrals')->where('case_id', $case)->orderByDesc('created_at')->get(['service_category', 'status', 'referred_at', 'completed_at']),
            'aftercare' => DB::table('case_aftercare_plans')->where('case_id', $case)->latest()->first(['status', 'initial_follow_up_at', 'created_at']),
            'template_notice' => 'POLICY/TEMPLATE VALIDATION REQUIRED: Health/SRH, Child Participation, MDT, restricted documents, and unrestricted narrative content are excluded unless an approved template and explicit permission authorize them.',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function show(Request $request, int $case)
    {
        $report = $this->report($request, $case);
        $this->audit($request, $case, 'social_case_report.generated');

        return Inertia::render('Reports/SocialCaseManagement', ['report' => $report, 'caseId' => $case])
            ->toResponse($request)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache']);
    }

    public function download(Request $request, int $case)
    {
        $report = $this->report($request, $case);
        $this->audit($request, $case, 'social_case_report.downloaded');
        $lines = [
            'Kalinga CEFMU Social Case Management Report',
            'Document status,' . $this->csvCell($report['approval_notice']),
            'Template version,' . $this->csvCell($report['template_version']),
            'Prepared by,' . $this->csvCell($report['prepared_by']),
            'Source case ID,' . $case, 'Source updated,' . $this->csvCell($report['source']['updated_at']),
            'Case number,' . $this->csvCell($report['case']['case_number']), 'Status,' . $this->csvCell($report['case']['status']), 'Stage,' . $this->csvCell($report['case']['stage']),
            'Client,' . $this->csvCell($report['client']['first_name'] . ' ' . $report['client']['last_name']), 'LGU,' . $this->csvCell($report['client']['municipality']),
            'Generated,' . $this->csvCell($report['generated_at']), 'Notice,' . $this->csvCell($report['template_notice']),
        ];
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $report['case']['case_number']);

        return response(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="draft-social-case-management-' . $filename . '.csv"', 'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache']);
    }

    private function csvCell(mixed $value): string
    {
        $str = (string) $value;
        if (preg_match('/^[=+\-@]/', ltrim($str)) === 1 || preg_match('/^[\t\r\n]/', $str) === 1) {
            $str = "'" . $str;
        }

        return '"' . str_replace('"', '""', $str) . '"';
    }

    private function audit(Request $request, int $case, string $event): void
    {
        AuditLog::insert(['user_id' => $request->user()->id, 'event' => $event, 'auditable_type' => 'case', 'auditable_id' => $case, 'changes' => json_encode(['restricted_content_excluded' => true]), 'created_at' => now(), 'updated_at' => now()]);
    }
}
