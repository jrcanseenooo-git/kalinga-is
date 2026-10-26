<?php

namespace App\Http\Controllers;

use App\Services\IntakeDrafts;
use App\Services\LguAssignmentScope;
use Illuminate\Http\Request;

/** Saved intakes: autosave and discard (the owner only). */
class IntakeDraftController extends Controller
{
    public function __construct(private readonly LguAssignmentScope $lgus) {}

    public function update(Request $request, string $draft)
    {
        $this->authorize('cases.create');
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $draft) === 1, 404);
        $data = $request->validate(['step' => 'required|integer|min:1|max:4', 'payload' => 'required|array']);
        // Nothing about the child is kept before the privacy notice step is done.
        abort_unless(filter_var($data['payload']['privacy_notice_acknowledged'] ?? false, FILTER_VALIDATE_BOOL), 422, 'Complete the privacy notice step before saving.');
        abort_if(strlen((string) json_encode($data['payload'])) > 9 * 1024 * 1024, 422, 'This intake is too large to save. Retake the consent form photo at a lower size.');
        $saved = IntakeDrafts::save($draft, $request->user()->id, $this->lgus->activeLguIds($request->user())[0] ?? null, $data['step'], $data['payload']);

        return response()->json(['id' => $saved->id, 'saved_at' => $saved->updated_at, 'expires_at' => $saved->expires_at], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function destroy(Request $request, string $draft)
    {
        $this->authorize('cases.create');
        IntakeDrafts::discard($draft, $request->user()->id);

        return $request->expectsJson() && !$request->header('X-Inertia') ? response()->json(['discarded' => true]) : back()->with('success', 'Saved intake discarded.');
    }
}
