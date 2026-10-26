<?php

namespace App\Services;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * CEFMU classification and CEFMU Incidence fields: one definition for the
 * online intake, the offline intake sync and later edits. Labels for screens
 * live in resources/js/data/cefmu.ts with the same codes.
 */
final class CefmuIncidence
{
    public const CLASSIFICATIONS = ['early_marriage', 'early_union', 'forced_marriage', 'forced_union'];

    public const MARRIAGE_CLASSIFICATIONS = ['early_marriage', 'forced_marriage'];

    public const FORMAL_MARRIAGE_FORMS = ['civil_marriage', 'religious_marriage', 'customary_traditional_marriage'];

    public const UNION_FORMS = [...self::FORMAL_MARRIAGE_FORMS, 'informal_cohabitation'];

    public const UNION_STATUSES = ['yes_now', 'yes_before', 'no'];

    public const RESIDENCES = ['parents_house', 'relatives_house', 'partner_parents_house', 'partner_relatives_house', 'with_partner'];

    public const COLUMNS = ['cefmu_classifications', 'union_forms', 'union_status', 'partner_current_age', 'union_duration_months', 'current_residence'];

    /** @return array<string, mixed> */
    public static function rules(bool $classificationRequired = true): array
    {
        return [
            'cefmu_classifications' => [$classificationRequired ? 'required' : 'sometimes', 'array', 'min:1'],
            'cefmu_classifications.*' => ['distinct', Rule::in(self::CLASSIFICATIONS)],
            'union_forms' => ['nullable', 'array'],
            'union_forms.*' => ['distinct', Rule::in(self::UNION_FORMS)],
            'union_status' => ['nullable', Rule::in(self::UNION_STATUSES)],
            'partner_current_age' => ['nullable', 'integer', 'min:10', 'max:99'],
            'union_duration_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'current_residence' => ['nullable', Rule::in(self::RESIDENCES)],
        ];
    }

    /** Cross-field checks, added with $validator->after(...). */
    public static function after(Validator $validator): void
    {
        $data = $validator->getData();
        $classes = (array) ($data['cefmu_classifications'] ?? []);
        $forms = (array) ($data['union_forms'] ?? []);
        if (array_intersect($forms, self::FORMAL_MARRIAGE_FORMS) && !array_intersect($classes, self::MARRIAGE_CLASSIFICATIONS)) {
            $validator->errors()->add('union_forms', 'A formal marriage type needs "Early marriage" or "Forced marriage" in the CEFMU classification.');
        }
        if (($data['union_status'] ?? null) === 'no' && (($data['union_duration_months'] ?? null) || ($data['partner_current_age'] ?? null))) {
            $validator->errors()->add('union_status', 'Partner age and length of the union apply only when the child is or was married or living with a partner.');
        }
    }

    /** @return array<string, mixed> case columns from validated data */
    public static function columns(array $data): array
    {
        $forms = array_values(array_intersect(self::UNION_FORMS, (array) ($data['union_forms'] ?? [])));
        $together = ($data['union_status'] ?? null) !== 'no';

        return [
            'cefmu_classifications' => json_encode(array_values(array_intersect(self::CLASSIFICATIONS, (array) ($data['cefmu_classifications'] ?? [])))),
            'union_forms' => json_encode($forms),
            'union_status' => $data['union_status'] ?? null,
            'partner_current_age' => $together ? ($data['partner_current_age'] ?? null) : null,
            'union_duration_months' => $together ? ($data['union_duration_months'] ?? null) : null,
            'current_residence' => $data['current_residence'] ?? null,
        ];
    }
}
