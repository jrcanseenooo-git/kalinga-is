<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Editable form options kept in reference_data (an "option set" per list).
 *
 * DEFAULTS are written once when a set or option is missing; after that the
 * administrator's changes (labels, order, points, retiring an option) win.
 * Options are never deleted, so saved records keep their meaning.
 */
final class OptionSets
{
    public const RISK_FACTORS = 'intake_risk_factors';

    public const RISK_RULE = 'risk_level_rule';

    public const RISK_LEVELS = ['low', 'moderate', 'high', 'critical'];

    /**
     * Starting values. Risk factors count 1 point each, except immediate
     * danger (4 points, critical on its own).
     * OWNER REVIEW: the Kalinga team sets the points and the thresholds.
     */
    private const DEFAULTS = [
        self::RISK_FACTORS => [
            ['immediate_danger', "Immediate danger to the child's safety", 4, 'The child is not safe now, for example threats, violence or no safe place to stay tonight.'],
            ['planned_or_impending_union', 'Planned or impending union', 1, 'A marriage or living-together arrangement is being planned or arranged for the child.'],
            ['pressure_after_pregnancy', 'Pressure to enter a union after pregnancy', 1, 'The child is being pushed to marry or live with a partner because of a pregnancy.'],
            ['force_or_control', 'Force, coercion, or controlling behaviour', 1, 'Someone uses threats, force or control over where the child goes, who they see or what they decide.'],
            ['age_or_power_imbalance', 'Age or power imbalance', 1, 'The partner is much older, or has much more money, authority or influence than the child.'],
            ['family_or_community_pressure', 'Family or community pressure', 1, 'Family members or the community expect or push the child to marry or stay in the union.'],
            ['economic_hardship', 'Economic hardship or dependence', 1, 'The child or family depends on the partner or the union for money, food or shelter.'],
            ['school_interruption', 'School interruption or dropout', 1, 'The child stopped, missed or is about to leave school or training.'],
            ['health_psychosocial_need', 'Health, SRH, or psychosocial support need', 1, 'The child needs health, sexual and reproductive health or mental health support.'],
        ],
        // Lowest total points for each level; below "moderate" is low.
        self::RISK_RULE => [
            ['moderate', 'Moderate from', 1, null],
            ['high', 'High from', 2, null],
            ['critical', 'Critical from', 4, null],
        ],
    ];

    /** @return array<string, array<string, mixed>> the editable sets (config/option_sets.php) */
    public static function catalogue(): array
    {
        return config('option_sets', []);
    }

    /** Adds missing sets and options; never changes existing ones. */
    public static function ensureDefaults(): void
    {
        foreach (self::catalogue() as $set => $meta) {
            if (!is_array($meta['defaults'] ?? null) || DB::table('reference_data')->where('set_name', $set)->exists()) {
                continue;
            }
            $order = 0;
            foreach ($meta['defaults'] as $code => $label) {
                $order += 10;
                DB::table('reference_data')->insert(['set_name' => $set, 'code' => ($meta['kind'] ?? 'text') === 'text' ? self::textCode($label) : $code, 'label' => $label,
                    'sort_order' => $order, 'active' => true, 'source' => 'Kalinga default', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach (self::DEFAULTS as $set => $options) {
            foreach ($options as $order => [$code, $label, $weight, $definition]) {
                if (!DB::table('reference_data')->where('set_name', $set)->where('code', $code)->exists()) {
                    DB::table('reference_data')->insert(['set_name' => $set, 'code' => $code, 'label' => $label, 'definition' => $definition, 'weight' => $weight,
                        'sort_order' => ($order + 1) * 10, 'active' => true, 'source' => 'Kalinga default', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    /** @return list<array{code: string, label: string, definition: ?string, weight: float}> active options in order */
    public static function options(string $set): array
    {
        return DB::table('reference_data')->where('set_name', $set)->where('active', true)->orderBy('sort_order')->orderBy('label')
            ->get(['code', 'label', 'definition', 'weight'])
            ->map(fn ($row) => ['code' => $row->code, 'label' => $row->label, 'definition' => $row->definition, 'weight' => (float) ($row->weight ?? 0)])->all();
    }

    /** Stable code for an option stored as its label text. */
    public static function textCode(string $label): string
    {
        return mb_substr(Str::slug($label, '_') ?: md5($label), 0, 90);
    }

    /** Active codes of a set (for validating new records). */
    public static function codes(string $set): array
    {
        return DB::table('reference_data')->where('set_name', $set)->where('active', true)->pluck('code')->all();
    }

    /**
     * Active lists for the browser: { set => [{ value, label, hint }] }. Text
     * sets use the label as the value (that is what records store).
     */
    public static function forClient(): array
    {
        $catalogue = self::catalogue();
        $rows = DB::table('reference_data')->whereIn('set_name', array_keys($catalogue))->where('active', true)->orderBy('sort_order')->orderBy('label')
            ->get(['set_name', 'code', 'label', 'definition'])->groupBy('set_name');
        $out = [];
        foreach ($catalogue as $set => $meta) {
            if (empty($meta['client']) || !$rows->has($set)) {
                continue;
            }
            $out[$meta['client']] = $rows[$set]->map(fn ($row) => array_filter(['value' => ($meta['kind'] ?? 'text') === 'text' ? $row->label : $row->code, 'label' => $row->label, 'hint' => $row->definition]))->values()->all();
        }

        return $out;
    }

    /**
     * How many open cases (and saved intakes, for intake lists) use an option,
     * shown to the administrator before renaming or retiring it.
     *
     * @return array{cases: int, intakes: int}
     */
    public static function usage(string $set, string $code): array
    {
        $meta = self::catalogue()[$set] ?? null;
        $row = DB::table('reference_data')->where('set_name', $set)->where('code', $code)->first();
        if (!$meta || !$row) {
            return ['cases' => 0, 'intakes' => 0];
        }
        $value = ($meta['kind'] ?? 'text') === 'text' ? $row->label : $row->code;
        $caseIds = collect();
        foreach ($meta['usage'] ?? [] as $use) {
            [$table, $column] = $use;
            $json = ($use[2] ?? null) === 'json';
            $query = DB::table($table);
            $json ? $query->where($column, 'like', '%"' . str_replace(['%', '_'], ['\%', '\_'], $value) . '"%') : $query->where($column, $value);
            $ids = match ($table) {
                'cases' => $query->whereNull('deleted_at')->where('status', '!=', 'CLOSED')->pluck('id'),
                'clients' => DB::table('cases')->whereIn('client_id', $query->select('id'))->whereNull('deleted_at')->where('status', '!=', 'CLOSED')->pluck('id'),
                'relationships' => DB::table('cases')->whereIn('client_id', $query->select('client_id'))->whereNull('deleted_at')->where('status', '!=', 'CLOSED')->pluck('id'),
                default => DB::table('cases')->whereIn('id', $query->whereNotNull('case_id')->select('case_id'))->whereNull('deleted_at')->where('status', '!=', 'CLOSED')->pluck('id'),
            };
            $caseIds = $caseIds->merge($ids);
        }
        // Saved intakes are encrypted; count the ones that use the option without keeping their content.
        $intakes = 0;
        foreach (DB::table('intake_drafts')->where('expires_at', '>', now())->pluck('payload') as $payload) {
            try {
                if (str_contains(Crypt::decryptString($payload), json_encode($value))) {
                    $intakes++;
                }
            } catch (\Throwable) {
                // An unreadable draft is not counted.
            }
        }

        return ['cases' => $caseIds->unique()->count(), 'intakes' => $intakes];
    }

    /** code => label for every option, retired ones included (for showing saved records). */
    public static function labels(string $set): array
    {
        return DB::table('reference_data')->where('set_name', $set)->pluck('label', 'code')->all();
    }

    /** @return array{moderate: float, high: float, critical: float} */
    public static function riskRule(): array
    {
        $rule = DB::table('reference_data')->where('set_name', self::RISK_RULE)->pluck('weight', 'code');

        return ['moderate' => (float) ($rule['moderate'] ?? 1), 'high' => (float) ($rule['high'] ?? 2), 'critical' => (float) ($rule['critical'] ?? 4)];
    }

    /** Suggested initial risk level from the risk factors ticked. */
    public static function suggestedRiskLevel(array $codes): array
    {
        $weights = DB::table('reference_data')->where('set_name', self::RISK_FACTORS)->whereIn('code', $codes ?: ['-'])->pluck('weight', 'code');
        $points = (float) $weights->sum(fn ($weight) => (float) $weight);
        $rule = self::riskRule();
        $level = $points >= $rule['critical'] ? 'critical' : ($points >= $rule['high'] ? 'high' : ($points >= $rule['moderate'] ? 'moderate' : 'low'));

        return ['level' => $level, 'points' => $points];
    }
}
