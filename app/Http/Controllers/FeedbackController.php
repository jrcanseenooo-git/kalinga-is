<?php

namespace App\Http\Controllers;

use App\Services\OperationalNotification;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * User feedback: anyone signed in can send a suggestion, problem report or
 * question about the system; reviewers (System Administrator, Project
 * Manager) answer it and the sender is notified. Users are asked not to put
 * case details in feedback; the page address is kept without its query.
 */
class FeedbackController extends Controller
{
    public const CATEGORIES = ['suggestion' => 'Suggestion', 'problem' => 'Something is not working', 'question' => 'Question', 'praise' => 'Something works well'];

    public const STATUSES = ['new' => 'New', 'in_review' => 'Being looked at', 'planned' => 'Planned', 'resolved' => 'Resolved', 'closed' => 'Closed'];

    public function __construct(private readonly OperationalNotification $notifications) {}

    public function store(Request $request)
    {
        $data = $request->validate(['category' => ['required', Rule::in(array_keys(self::CATEGORIES))], 'subject' => 'required|string|min:3|max:160',
            'message' => 'required|string|min:5|max:3000', 'page' => 'nullable|string|max:300', 'rating' => 'nullable|integer|min:1|max:5']);
        // Keep only the path of the page (no query string, which may hold search words).
        $page = $data['page'] ?? null ? mb_substr((string) parse_url($data['page'], PHP_URL_PATH), 0, 160) : null;
        $id = DB::table('user_feedback')->insertGetId(['user_id' => $request->user()->id, 'category' => $data['category'], 'subject' => $data['subject'], 'message' => $data['message'],
            'page' => $page, 'rating' => $data['rating'] ?? null, 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::record('feedback.submitted', 'feedback', $id, ['category' => $data['category']], $request->user()->id, 'success', ['description' => 'Feedback sent: ' . self::CATEGORIES[$data['category']]]);

        return $request->expectsJson() ? response()->json(['id' => $id]) : back()->with('success', 'Thank you. Your feedback was sent.');
    }

    /** The user's own feedback and the replies. */
    public function mine(Request $request)
    {
        return response()->json(['items' => DB::table('user_feedback')->where('user_id', $request->user()->id)->latest()->limit(20)
            ->get(['id', 'category', 'subject', 'status', 'response', 'responded_at', 'created_at'])], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request)
    {
        $this->authorize('feedback.review');
        $data = $request->validate(['status' => ['nullable', Rule::in(array_keys(self::STATUSES))], 'category' => ['nullable', Rule::in(array_keys(self::CATEGORIES))]]);
        $items = DB::table('user_feedback')->join('users', 'users.id', '=', 'user_feedback.user_id')
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('user_feedback.status', $status))
            ->when($data['category'] ?? null, fn ($q, $category) => $q->where('user_feedback.category', $category))
            ->orderByRaw("CASE WHEN user_feedback.status = 'new' THEN 0 ELSE 1 END")->latest('user_feedback.created_at')
            ->paginate(30, ['user_feedback.*', 'users.name as sender', 'users.role as sender_role'])->withQueryString();

        return Inertia::render('Admin/Feedback', [
            'items' => $items, 'filters' => ['status' => $data['status'] ?? '', 'category' => $data['category'] ?? ''],
            'categories' => self::CATEGORIES, 'statuses' => self::STATUSES,
            'counts' => DB::table('user_feedback')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'averageRating' => round((float) DB::table('user_feedback')->whereNotNull('rating')->avg('rating'), 1),
        ]);
    }

    public function respond(Request $request, int $feedback)
    {
        $this->authorize('feedback.review');
        $row = DB::table('user_feedback')->find($feedback);
        abort_unless($row, 404);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(self::STATUSES))], 'response' => 'nullable|string|max:3000']);
        DB::table('user_feedback')->where('id', $feedback)->update(['status' => $data['status'], 'response' => $data['response'] ?? $row->response,
            'responded_by' => $request->user()->id, 'responded_at' => now(), 'updated_at' => now()]);
        if (!empty($data['response']) && $data['response'] !== $row->response) {
            $this->notifications->send((int) $row->user_id, 'feedback.reply', 'Reply to your feedback', '"' . mb_strimwidth($row->subject, 0, 80, '...') . '": ' . self::STATUSES[$data['status']] . '.', route('dashboard') . '?feedback=1');
        }
        AuditLog::record('feedback.answered', 'feedback', $feedback, ['status' => $data['status']], $request->user()->id, 'success',
            ['description' => 'Feedback answered', 'old_value' => self::STATUSES[$row->status] ?? $row->status, 'new_value' => self::STATUSES[$data['status']]]);

        return back()->with('success', 'Feedback updated.');
    }
}
