<?php

namespace App\Http\Controllers;

use App\Services\CaseAccessScope;
use App\Services\CaseForms;
use App\Services\CaseIntake;
use App\Services\Kavat;
use App\Services\OptionSets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OfflineWorkspaceController extends Controller
{
    public function __construct(private readonly CaseAccessScope $scope) {}

    public function index(Request $request)
    {
        $this->authorize('offline.use');

        return Inertia::render('Offline/Workspace', ['intakeOptions' => ['riskFactors' => OptionSets::options(OptionSets::RISK_FACTORS), 'riskRule' => OptionSets::riskRule(), 'customFields' => CaseIntake::customFields()]]);
    }

    public function pack(Request $request)
    {
        $user = $request->user();
        $this->authorize('offline.use');
        $device = $this->registerDevice($request);

        $cases = DB::table('cases')
            ->join('clients', 'clients.id', '=', 'cases.client_id')
            ->whereNull('cases.deleted_at');

        $this->scope->scopeCases($cases, $user);

        $rows = $cases->orderByDesc('cases.updated_at')->limit(50)->get([
            'cases.id', 'cases.case_number', 'cases.status', 'cases.priority', 'cases.category', 'cases.offline_version', 'cases.last_activity_at', 'cases.assigned_to',
            'clients.first_name', 'clients.last_name', 'clients.sex', 'clients.region', 'clients.province', 'clients.municipality', 'clients.barangay',
        ]);
        // The Kalinga forms and the KaVAT in progress travel only for the case manager's own open cases,
        // so they can be continued in the field (the pack is encrypted on the device).
        $writable = $user->hasPermission('cases.update');
        $rows->each(function ($case) use ($user, $writable) {
            $own = $writable && (int) $case->assigned_to === (int) $user->id && $case->status !== 'CLOSED';
            $case->forms = $own ? collect(CaseForms::forCase($case->id))->map(fn ($form) => ['content' => $form['content'], 'revision' => $form['revision'], 'status' => $form['status']]) : null;
            $draft = $own ? DB::table('kavat_assessments')->where('case_id', $case->id)->where('status', 'draft')->first(['id', 'revision', 'answers', 'notes', 'occasion', 'administered_at']) : null;
            $case->kavat_draft = $draft ? ['id' => $draft->id, 'revision' => (int) $draft->revision, 'answers' => json_decode($draft->answers, true), 'notes' => $draft->notes,
                'occasion' => $draft->occasion, 'administered_at' => substr((string) $draft->administered_at, 0, 10)] : null;
            $case->kavat_count = $own ? DB::table('kavat_assessments')->where('case_id', $case->id)->where('status', 'final')->count() : 0;
            unset($case->assigned_to);
        });

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'expires_at' => $device->pack_expires_at,
            'user_id' => $user->id,
            'kavatDefinition' => Kavat::definition(),
            'formsDefinition' => CaseForms::definition(),
            'cases' => $rows,
        ]);
    }

    private function registerDevice(Request $request): object
    {
        $deviceId = (string) $request->header('X-Kalinga-Device-ID');
        abort_unless((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $deviceId), 422, 'A registered offline device is required.');
        $existing = DB::table('offline_devices')->where('user_id', $request->user()->id)->where('device_id', $deviceId)->first();
        abort_unless(!$existing || !$existing->revoked_at, 403, 'This offline device has been revoked.');
        $expiresAt = now()->addHours(72);
        DB::table('offline_devices')->updateOrInsert(['user_id' => $request->user()->id, 'device_id' => $deviceId], ['last_seen_at' => now(), 'pack_expires_at' => $expiresAt, 'revoked_at' => null, 'updated_at' => now(), 'created_at' => now()]);

        return (object) ['pack_expires_at' => $expiresAt->toIso8601String()];
    }
}
