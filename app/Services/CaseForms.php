<?php

namespace App\Services;

use App\Support\AuditLog;
use App\Support\PhilippineDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Kalinga forms the case manager works on directly: Case Summary Report,
 * Intervention Plan (a matrix) and Social Case Study Report. Narrative
 * sections are written in paragraphs; identifying information, family
 * composition and the plan inside the case study come from the record.
 *
 * Saved as the case manager types (with a revision number, so two devices
 * cannot overwrite each other); "Finalize" keeps a numbered copy in
 * case_form_versions and the form stays editable for the next version.
 *
 * Sequence of Kalinga forms (owner, 2026-10-28): Intake Sheet, Case Summary
 * Report, Social Case Study Report, Intervention Plan and Referrals, Progress
 * Notes. The Social Case Study Report starts from what the Case Summary
 * Report says (see carryFromSummary).
 * OWNER REVIEW: section titles and guide questions.
 */
final class CaseForms
{
    public const TYPES = ['summary' => 'Case Summary Report', 'study' => 'Social Case Study Report', 'plan' => 'Intervention Plan and Referrals'];

    /** Printed order of all Kalinga forms (report type => title). */
    public const SEQUENCE = ['intake' => 'Kalinga Intake Sheet', 'summary' => 'Kalinga Case Summary Report', 'study' => 'Kalinga Social Case Study Report',
        'plan' => 'Kalinga Intervention Plan and Referrals', 'notes' => 'Kalinga Progress Notes'];

    /**
     * Social Case Study Report sections that start from the Case Summary
     * Report (study section => summary section). The case manager adds to or
     * updates them; once the case study section has its own text it is kept.
     */
    public const CARRY_FROM_SUMMARY = ['presented_problem' => 'presenting_problem', 'background_client' => 'case_history', 'assessment' => 'assessment', 'recommendations' => 'recommendations'];

    /** Printed columns of the Intervention Plan matrix. */
    public const PLAN_COLUMNS = ['problem' => 'Problem / need', 'objective' => 'Objective', 'activities' => 'Activities', 'responsible' => 'Person/s responsible',
        'timeframe' => 'Time frame', 'expected_output' => 'Expected output'];

    /**
     * Status of a plan row (not printed). Target start / end dates are also
     * kept per row, for the calendar and for monitoring only (owner,
     * 2026-10-28): the printed plan shows the free-text Time frame.
     */
    public const PLAN_ROW_STATUSES = ['not_started' => 'Not started', 'in_progress' => 'In progress', 'done' => 'Done'];

    /** The Intervention Plan is submitted (first finalized) within this many days of the intake. */
    public const PLAN_DUE_DAYS = 7;

    /** type => list of [key, title, kind (text|auto), guide] */
    private const SECTIONS = [
        'summary' => [
            ['identifying', 'Identifying information', 'auto', null],
            ['incidence', 'CEFMU incidence', 'auto', null],
            ['presenting_problem', 'Presenting problem', 'text', 'What happened, when and how the case reached the office. Write facts, not opinions.'],
            ['case_history', 'Brief case history', 'text', "The child's situation before and since the marriage or union; family, schooling, health and community context."],
            ['assessment', 'Assessment', 'text', 'Your professional assessment: risks, needs, strengths and protective factors. Refer to the KaVAT result where it helps.'],
            ['recommendations', 'Recommendations', 'text', 'What should happen next, by whom.'],
            ['status', 'Current status', 'auto', null],
        ],
        'study' => [
            ['identifying', 'I. Identifying information', 'auto', null],
            ['family', 'II. Family composition', 'auto', null],
            ['presented_problem', 'III. Presented problem', 'text', 'The problem as presented: what the child and family need, and why the case was opened.'],
            ['sources', 'IV. Source of information', 'text', 'For example: client, mother, barangay official, school records.'],
            ['background_client', 'V. Background information - A. The client', 'text', "The child's history: childhood, schooling, relationships, the marriage or union, health and wellbeing."],
            ['background_family', 'V. Background information - B. Family', 'text', 'Parents and siblings, relationships within the family, how the family supports or affects the child.'],
            ['background_community', 'V. Background information - C. Community and environment', 'text', 'Community norms, culture, services and influences around the child (optional).'],
            ['plan', 'VI. Intervention and treatment plan', 'auto', null],
            ['assessment', 'VII. Assessment', 'text', 'Your analysis of the case, the core issue and what drives it (for example using an ecological or systems view).'],
            ['recommendations', 'VII. Recommendations', 'text', 'Your recommendations for the child and family.'],
        ],
    ];

    public static function definition(): array
    {
        $sections = [];
        foreach (self::SECTIONS as $type => $items) {
            $sections[$type] = array_map(fn ($item) => ['key' => $item[0], 'title' => $item[1], 'kind' => $item[2], 'guide' => $item[3]], $items);
        }

        return ['types' => self::TYPES, 'sequence' => self::SEQUENCE, 'sections' => $sections, 'planColumns' => self::PLAN_COLUMNS];
    }

    /** @return array<string, array<string, mixed>> type => form (content, status, revision, versions) */
    public static function forCase(int $caseId): array
    {
        $rows = DB::table('case_forms')->where('case_id', $caseId)->get()->keyBy('form_type');
        $versions = DB::table('case_form_versions')->leftJoin('users', 'users.id', '=', 'case_form_versions.finalized_by')->where('case_form_versions.case_id', $caseId)
            ->orderByDesc('case_form_versions.version')->get(['case_form_versions.form_type', 'case_form_versions.version', 'case_form_versions.created_at', 'users.name as finalized_by'])->groupBy('form_type');
        $forms = [];
        foreach (array_keys(self::TYPES) as $type) {
            $row = $rows->get($type);
            $forms[$type] = [
                'type' => $type, 'title' => self::TYPES[$type], 'content' => $row ? (json_decode($row->content, true) ?: self::empty($type)) : self::empty($type),
                'status' => $row->status ?? 'not_started', 'revision' => (int) ($row->revision ?? 0), 'updated_at' => $row->updated_at ?? null,
                'versions' => ($versions->get($type) ?? collect())->values()->all(),
            ];
        }
        $forms['study'] = self::carryFromSummary($forms['study'], $forms['summary']['content']['sections'] ?? []);

        return $forms;
    }

    /**
     * The Social Case Study Report shows what the Case Summary Report says in
     * the matching sections while the case study section is still empty, so
     * the case manager adds to it or updates it instead of writing it again.
     * The carried text is saved with the next change to the case study.
     * 'carried' lists the sections that currently show Case Summary text.
     */
    public static function carryFromSummary(array $study, array $summary): array
    {
        $carried = [];
        foreach (self::CARRY_FROM_SUMMARY as $target => $source) {
            $text = trim((string) ($summary[$source] ?? ''));
            if ($text !== '' && trim((string) ($study['content']['sections'][$target] ?? '')) === '') {
                $study['content']['sections'][$target] = $text;
                $carried[] = $target;
            }
        }
        $study['carried'] = $carried;

        return $study;
    }

    public static function empty(string $type): array
    {
        if ($type === 'plan') {
            return ['rows' => []];
        }

        return ['sections' => collect(self::SECTIONS[$type])->where(2, 'text')->mapWithKeys(fn ($item) => [$item[0] => ''])->all()];
    }

    /** Validates and normalizes content for a form type. */
    public static function clean(string $type, mixed $content): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['content' => $message]);
        if (!is_array($content)) {
            $fail('The form content is not valid.');
        }
        if ($type === 'plan') {
            $rows = $content['rows'] ?? [];
            if (!is_array($rows) || count($rows) > 60) {
                $fail('An intervention plan can have up to 60 rows.');
            }

            return ['rows' => array_values(array_map(function ($row) use ($fail) {
                if (!is_array($row)) {
                    $fail('A plan row is not valid.');
                }
                $clean = [];
                foreach (array_keys(self::PLAN_COLUMNS) as $column) {
                    $value = $row[$column] ?? '';
                    if (!is_string($value) || mb_strlen($value) > 3000) {
                        $fail('Each plan cell can have up to 3,000 characters.');
                    }
                    $clean[$column] = $value;
                }
                $id = (string) ($row['id'] ?? '');
                $clean['id'] = preg_match('/^[A-Za-z0-9-]{1,40}$/', $id) ? $id : (string) Str::uuid();
                $status = (string) ($row['status'] ?? 'not_started');
                $clean['status'] = isset(self::PLAN_ROW_STATUSES[$status]) ? $status : 'not_started';
                foreach (['target_start', 'target_end'] as $key) {
                    $date = (string) ($row[$key] ?? '');
                    if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date))) {
                        $fail('Target dates must be valid dates.');
                    }
                    $clean[$key] = $date;
                }
                if ($clean['target_start'] !== '' && $clean['target_end'] !== '' && $clean['target_end'] < $clean['target_start']) {
                    $fail('The target end date cannot be before the start date.');
                }

                return $clean;
            }, $rows))];
        }
        $sections = [];
        foreach (collect(self::SECTIONS[$type])->where(2, 'text')->pluck(0) as $key) {
            $value = $content['sections'][$key] ?? '';
            if (!is_string($value) || mb_strlen($value) > 30000) {
                $fail('Each section can have up to 30,000 characters.');
            }
            $sections[$key] = $value;
        }

        return ['sections' => $sections];
    }

    /**
     * Saves the form if the caller edited the latest revision; otherwise
     * returns the conflict so the caller can show both versions.
     *
     * @return array{saved: bool, revision: int, updated_at: ?string, content?: array}
     */
    public static function save(int $caseId, string $type, array $content, int $baseRevision, int $userId): array
    {
        $content = self::clean($type, $content);

        return DB::transaction(function () use ($caseId, $type, $content, $baseRevision, $userId) {
            $row = DB::table('case_forms')->where('case_id', $caseId)->where('form_type', $type)->lockForUpdate()->first();
            if ($row && (int) $row->revision !== $baseRevision) {
                return ['saved' => false, 'revision' => (int) $row->revision, 'updated_at' => $row->updated_at, 'content' => json_decode($row->content, true)];
            }
            $now = now();
            $values = ['content' => json_encode($content), 'status' => 'draft', 'updated_by' => $userId, 'updated_at' => $now];
            if ($row) {
                DB::table('case_forms')->where('id', $row->id)->update($values + ['revision' => $row->revision + 1]);
            } else {
                DB::table('case_forms')->insert($values + ['case_id' => $caseId, 'form_type' => $type, 'revision' => 1, 'created_at' => $now]);
            }
            DB::table('cases')->where('id', $caseId)->update(['last_activity_at' => $now, 'updated_at' => $now]);
            // One audit entry per person, form and half hour (saving happens as they type).
            if (Cache::add("audit:case-form:{$userId}:{$caseId}:{$type}", true, now()->addMinutes(30))) {
                AuditLog::record('case_form.updated', 'case', $caseId, ['case_id' => $caseId, 'form' => $type], $userId, 'success', ['description' => self::TYPES[$type] . ' edited']);
            }

            return ['saved' => true, 'revision' => $row ? $row->revision + 1 : 1, 'updated_at' => $now->toIso8601String()];
        });
    }

    public static function finalize(int $caseId, string $type, int $userId): int
    {
        return DB::transaction(function () use ($caseId, $type, $userId) {
            $row = DB::table('case_forms')->where('case_id', $caseId)->where('form_type', $type)->lockForUpdate()->first();
            if (!$row || !self::hasContent($type, json_decode($row->content, true) ?: [])) {
                throw ValidationException::withMessages(['content' => 'Write the form before finalizing it.']);
            }
            $version = (int) DB::table('case_form_versions')->where('case_id', $caseId)->where('form_type', $type)->max('version') + 1;
            DB::table('case_form_versions')->insert(['case_id' => $caseId, 'form_type' => $type, 'version' => $version, 'content' => $row->content,
                'finalized_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('case_forms')->where('id', $row->id)->update(['status' => 'final', 'finalized_at' => now(), 'updated_at' => now()]);
            AuditLog::record('case_form.finalized', 'case', $caseId, ['case_id' => $caseId, 'form' => $type, 'version' => $version], $userId, 'success',
                ['description' => self::TYPES[$type] . " finalized (version {$version})", 'new_value' => "Version {$version}"]);

            return $version;
        });
    }

    /**
     * The 7-day deadline of the Intervention Plan: due date (from the intake),
     * when the first version was finalized (submitted) and how long it took.
     *
     * @return array{due: string, submitted_at: ?string, days_to_submit: ?int, days_left: ?int, overdue: bool}
     */
    public static function planDeadline(object $case): array
    {
        $intake = Carbon::parse($case->intake_completed_at ?? $case->opened_at ?? $case->created_at ?? now())->timezone('Asia/Manila')->startOfDay();
        $due = $intake->copy()->addDays(self::PLAN_DUE_DAYS);
        $submitted = DB::table('case_form_versions')->where('case_id', $case->id)->where('form_type', 'plan')->orderBy('version')->value('created_at');
        $today = Carbon::parse(PhilippineDate::today(), 'Asia/Manila');
        $submittedDay = $submitted ? Carbon::parse($submitted)->timezone('Asia/Manila')->startOfDay() : null;

        return [
            'due' => $due->toDateString(),
            'submitted_at' => $submitted ? Carbon::parse($submitted)->toIso8601String() : null,
            'days_to_submit' => $submittedDay ? (int) $intake->diffInDays($submittedDay) : null,
            'days_left' => $submittedDay ? null : (int) $today->diffInDays($due, false),
            'overdue' => !$submittedDay && $today->gt($due),
        ];
    }

    public static function hasContent(string $type, array $content): bool
    {
        if ($type === 'plan') {
            return collect($content['rows'] ?? [])->contains(fn ($row) => trim(implode('', array_intersect_key((array) $row, self::PLAN_COLUMNS))) !== '');
        }

        return collect($content['sections'] ?? [])->contains(fn ($text) => trim((string) $text) !== '');
    }

    /**
     * Starting sentences built from the intake, offered (never inserted
     * automatically) for the first narrative sections.
     *
     * @return array<string, string> section key => suggested text
     */
    public static function suggestions(object $case, object $client): array
    {
        $age = $client->birthdate ? Carbon::parse($client->birthdate)->diffInYears(Carbon::parse($case->intake_completed_at ?? $case->opened_at ?? now())) : null;
        $who = trim(implode(', ', array_filter([
            $age !== null ? ((int) $age) . '-year-old' : null,
            $client->sex ? strtolower($client->sex) : null,
        ])));
        $classes = collect(json_decode($case->cefmu_classifications ?? '[]', true) ?: [])
            ->map(fn ($code) => ['early_marriage' => 'early marriage', 'early_union' => 'early union', 'forced_marriage' => 'forced marriage', 'forced_union' => 'forced union'][$code] ?? str_replace('_', ' ', $code))->all();
        $date = Carbon::parse($case->intake_completed_at ?? $case->opened_at ?? now())->format('F j, Y');
        $how = $case->mode_of_admission ? ' through ' . strtolower($case->mode_of_admission) : '';
        $text = "On {$date}, the child, a {$who}, was brought to the attention of the office{$how}";
        $text .= $case->referred_by ? " (referred by {$case->referred_by})." : '.';
        if ($classes) {
            $text .= ' The case was classified as ' . implode(' and ', $classes) . '.';
        }
        $status = ['yes_now' => 'The child is currently married or living with a partner', 'yes_before' => 'The child was previously married or living with a partner', 'no' => 'The child is not married or living with a partner'][$case->union_status ?? ''] ?? null;
        if ($status) {
            $text .= ' ' . $status . ($case->partner_current_age && $case->union_status !== 'no' ? ", who is now {$case->partner_current_age} years old" : '') . '.';
        }

        return ['presenting_problem' => $text, 'presented_problem' => $text, 'sources' => 'Client'];
    }
}
