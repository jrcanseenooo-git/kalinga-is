<?php

namespace App\Http\Controllers;

use App\Services\CaseAccessScope;
use App\Services\DashboardTodos;
use App\Services\SmartSuggestions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kalinga Assist: what needs the signed-in user's attention, for the assistant
 * panel that opens from any page. Rule-based (App\Services\SmartSuggestions
 * and DashboardTodos), no external AI and nothing sent anywhere. Items carry
 * case numbers only, never names or narratives.
 */
class AssistController extends Controller
{
    private const LIMIT = 8;

    public function __construct(private readonly CaseAccessScope $scope) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $items = [];
        if ($user->role === 'social_worker') {
            $cases = DB::table('cases')->leftJoin('clients', 'clients.id', '=', 'cases.client_id')->whereNull('cases.deleted_at');
            $this->scope->scopeCases($cases, $user);
            foreach (app(SmartSuggestions::class)->forCases($cases) as $item) {
                $items[] = ['tone' => $item['tone'], 'title' => $item['title'], 'text' => $item['case_number'] . ': ' . $item['text'],
                    'href' => route('cases.show', $item['case_id']) . '?section=' . $item['section']];
            }
        }
        foreach (app(DashboardTodos::class)->groups($user) as $group) {
            foreach ($group['items'] as $item) {
                $items[] = ['tone' => $item['tone'], 'title' => $item['title'], 'text' => $item['text'], 'href' => $item['href']];
            }
        }
        $rank = ['urgent' => 0, 'action' => 1, 'info' => 2];
        usort($items, fn ($a, $b) => ($rank[$a['tone']] ?? 3) <=> ($rank[$b['tone']] ?? 3));

        return response()->json(['items' => array_slice($items, 0, self::LIMIT), 'total' => count($items)], 200, ['Cache-Control' => 'private, no-store']);
    }
}
