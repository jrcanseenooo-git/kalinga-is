<?php

namespace App\Http\Controllers;

use App\Services\ReferenceNumberService;
use App\Support\AuditLog;
use App\Support\PhilippineDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicReportController extends Controller
{
    public function __construct(private readonly ReferenceNumberService $references) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'source' => 'required|string|max:100',
            'source_other' => 'required_if:source,Other|nullable|string|max:255',
            'reporter_name' => 'nullable|string|max:255',
            'reporter_contact' => ['nullable', 'regex:/^(?:\\+63|0)9\\d{9}$/'],
            'narrative' => 'required|string|max:5000',
            'subject_first' => 'nullable|string|max:80',
            'subject_last' => 'nullable|string|max:80',
            'subject_birthdate' => 'nullable|date|before_or_equal:' . PhilippineDate::today(),
            'subject_sex' => 'nullable|in:Female,Male',
            'region' => 'nullable|string|max:120',
            'province' => 'nullable|string|max:120',
            'municipality' => 'required|string|max:120',
            'barangay' => 'nullable|string|max:120',
            'priority' => 'required|in:low,normal,high,urgent',
        ]);

        [$id, $reference] = DB::transaction(function () use ($data) {
            $reference = $this->references->nextReport();
            $id = DB::table('reports')->insertGetId($data + [
                'reference_no' => $reference,
                'category' => 'CEFMU',
                'status' => 'FOR_SCREENING',
                'is_public_submission' => true,
                'endorsed_municipality' => $data['municipality'],
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::insert([
                'event' => 'public_report.submitted', 'auditable_type' => 'report', 'auditable_id' => $id,
                'changes' => json_encode(['endorsed_municipality' => $data['municipality']]), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$id, $reference];
        });

        return to_route('public.dashboard')->with('success', 'Your report has been received and endorsed for confidential screening. Reference: ' . $reference);
    }
}
