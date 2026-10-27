<?php

namespace App\Http\Controllers;

use App\Services\OptionSets;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Administration > Form options: edit the dropdown lists (option sets) and
 * the supplemental intake fields, with a preview of the intake.
 *
 * Options are never deleted, only retired, so saved records keep their
 * meaning. Renaming or retiring an option that open cases or saved intakes
 * use needs an explicit confirmation after the usage count is shown.
 */
class OptionSetController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('options.manage');
        OptionSets::ensureDefaults();
        $catalogue = OptionSets::catalogue();
        $rows = DB::table('reference_data')->whereIn('set_name', array_keys($catalogue))->orderBy('sort_order')->orderBy('label')
            ->get(['id', 'set_name', 'code', 'label', 'definition', 'weight', 'sort_order', 'active', 'updated_at'])->groupBy('set_name');

        return Inertia::render('Admin/FormOptions', [
            'sets' => collect($catalogue)->map(fn ($meta, $set) => ['key' => $set, 'title' => $meta['title'], 'description' => $meta['description'] ?? null, 'kind' => $meta['kind'] ?? 'text',
                'weighted' => (bool) ($meta['weighted'] ?? false), 'options' => ($rows[$set] ?? collect())->values()])->values(),
            'riskRule' => OptionSets::riskRule(),
            'fields' => DB::table('form_fields')->where('form', 'intake')->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    private function meta(string $set): array
    {
        $meta = OptionSets::catalogue()[$set] ?? null;
        abort_unless($meta, 404);

        return $meta;
    }

    public function usage(Request $request, string $set, string $code)
    {
        $this->authorize('options.manage');
        $this->meta($set);

        return response()->json(OptionSets::usage($set, $code), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, string $set)
    {
        $this->authorize('options.manage');
        $meta = $this->meta($set);
        $data = $request->validate(['label' => 'required|string|min:2|max:160', 'definition' => 'nullable|string|max:300', 'weight' => 'nullable|numeric|min:0|max:20']);
        $code = OptionSets::textCode($data['label']);
        if (DB::table('reference_data')->where('set_name', $set)->where(fn ($q) => $q->where('code', $code)->orWhereRaw('lower(label) = ?', [mb_strtolower($data['label'])]))->exists()) {
            throw ValidationException::withMessages(['label' => 'This option is already in the list (it may be retired; turn it back on instead).']);
        }
        $order = (int) DB::table('reference_data')->where('set_name', $set)->max('sort_order') + 10;
        DB::table('reference_data')->insert(['set_name' => $set, 'code' => $code, 'label' => $data['label'], 'definition' => $data['definition'] ?? null,
            'weight' => ($meta['weighted'] ?? false) ? ($data['weight'] ?? 1) : null, 'sort_order' => $order, 'active' => true, 'source' => 'Added by ' . $request->user()->name,
            'created_at' => now(), 'updated_at' => now()]);
        AuditLog::record('option.created', 'option_set', null, ['set' => $set, 'code' => $code], $request->user()->id, 'success',
            ['description' => 'Option added to ' . $meta['title'], 'record_ref' => $set, 'new_value' => $data['label']]);

        return back()->with('success', 'Option added.');
    }

    public function update(Request $request, string $set, string $code)
    {
        $this->authorize('options.manage');
        $meta = $this->meta($set);
        $row = DB::table('reference_data')->where('set_name', $set)->where('code', $code)->first();
        abort_unless($row, 404);
        $data = $request->validate(['label' => 'required|string|min:2|max:160', 'definition' => 'nullable|string|max:300', 'weight' => 'nullable|numeric|min:0|max:20',
            'sort_order' => 'nullable|integer|min:0|max:100000', 'active' => 'required|boolean', 'confirmed' => 'nullable|boolean']);
        $renamed = $data['label'] !== $row->label;
        $retired = $row->active && !$data['active'];
        if ($retired && DB::table('reference_data')->where('set_name', $set)->where('active', true)->count() <= 1) {
            throw ValidationException::withMessages(['active' => 'Keep at least one option in the list.']);
        }
        if (($renamed || $retired) && !($data['confirmed'] ?? false)) {
            $usage = OptionSets::usage($set, $code);
            if ($usage['cases'] || $usage['intakes']) {
                return response()->json(['message' => 'This option is in use. Confirm to save the change.', 'usage' => $usage], 409);
            }
        }
        DB::table('reference_data')->where('id', $row->id)->update(['label' => $data['label'], 'definition' => $data['definition'] ?? null,
            'weight' => ($meta['weighted'] ?? false) ? ($data['weight'] ?? $row->weight) : $row->weight, 'sort_order' => $data['sort_order'] ?? $row->sort_order,
            'active' => $data['active'], 'updated_at' => now()]);
        AuditLog::record('option.updated', 'option_set', null, ['set' => $set, 'code' => $code, 'active' => $data['active']], $request->user()->id, 'success',
            ['description' => ($retired ? 'Option retired in ' : 'Option changed in ') . $meta['title'], 'record_ref' => $set, 'old_value' => $row->label . ($row->active ? '' : ' (retired)'),
                'new_value' => $data['label'] . ($data['active'] ? '' : ' (retired)')]);

        return $request->expectsJson() ? response()->json(['saved' => true]) : back()->with('success', 'Option saved.');
    }

    public function updateRiskRule(Request $request)
    {
        $this->authorize('options.manage');
        $data = $request->validate(['moderate' => 'required|numeric|min:0|max:50', 'high' => 'required|numeric|gt:moderate|max:50', 'critical' => 'required|numeric|gt:high|max:50']);
        $before = OptionSets::riskRule();
        foreach ($data as $code => $points) {
            DB::table('reference_data')->where('set_name', OptionSets::RISK_RULE)->where('code', $code)->update(['weight' => $points, 'updated_at' => now()]);
        }
        AuditLog::record('option.risk_rule_updated', 'option_set', null, ['rule' => $data], $request->user()->id, 'success', ['description' => 'Risk level points changed', 'record_ref' => OptionSets::RISK_RULE,
            'old_value' => json_encode($before), 'new_value' => json_encode($data)]);

        return back()->with('success', 'Risk level points saved.');
    }

    /** Supplemental intake fields (shown in the intake under "Additional information"). */
    public function storeField(Request $request)
    {
        $this->authorize('options.manage');
        $data = $request->validate(['label' => 'required|string|min:2|max:120', 'type' => ['required', Rule::in(['text', 'textarea', 'select', 'date', 'number', 'yes_no'])],
            'options' => 'nullable|array|max:40', 'options.*' => 'string|max:120', 'required' => 'boolean', 'help_text' => 'nullable|string|max:255', 'id' => 'nullable|integer']);
        if ($data['type'] === 'select' && count(array_filter($data['options'] ?? [], fn ($o) => trim($o) !== '')) < 2) {
            throw ValidationException::withMessages(['options' => 'A dropdown needs at least two choices.']);
        }
        $values = ['label' => $data['label'], 'type' => $data['type'], 'required' => $data['required'] ?? false, 'help_text' => $data['help_text'] ?? null,
            'options' => json_encode(array_values(array_filter(array_map('trim', $data['options'] ?? []), 'strlen'))), 'updated_at' => now()];
        if (!empty($data['id'])) {
            abort_unless(DB::table('form_fields')->where('id', $data['id'])->where('form', 'intake')->exists(), 404);
            DB::table('form_fields')->where('id', $data['id'])->update($values);
            $id = (int) $data['id'];
        } else {
            $key = 'x_' . OptionSets::textCode($data['label']);
            abort_if(DB::table('form_fields')->where('form', 'intake')->where('key', $key)->exists(), 422, 'A field with this name already exists.');
            $id = DB::table('form_fields')->insertGetId($values + ['form' => 'intake', 'key' => $key, 'active' => true, 'sort_order' => (int) DB::table('form_fields')->max('sort_order') + 10, 'created_at' => now()]);
        }
        AuditLog::record('form_field.saved', 'form_field', $id, ['form' => 'intake', 'type' => $data['type']], $request->user()->id, 'success', ['description' => 'Intake field saved: ' . $data['label']]);

        return back()->with('success', 'Field saved. It appears in the intake under Additional information.');
    }

    public function toggleField(Request $request, int $field)
    {
        $this->authorize('options.manage');
        $row = DB::table('form_fields')->where('id', $field)->where('form', 'intake')->first();
        abort_unless($row, 404);
        DB::table('form_fields')->where('id', $field)->update(['active' => !$row->active, 'updated_at' => now()]);
        AuditLog::record('form_field.saved', 'form_field', $field, ['active' => !$row->active], $request->user()->id, 'success', ['description' => ($row->active ? 'Intake field hidden: ' : 'Intake field shown: ') . $row->label]);

        return back()->with('success', $row->active ? 'Field hidden from the intake. Answers already saved are kept.' : 'Field shown in the intake again.');
    }
}
