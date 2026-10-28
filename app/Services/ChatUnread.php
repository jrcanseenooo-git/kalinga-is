<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Unread message counts for the menu badges: Kalinga Connect (group circles
 * the user belongs to, plus private messages to the user) and Project team
 * chat. Counts only; no message content.
 */
final class ChatUnread
{
    public static function connect(User $user): int
    {
        $circles = DB::table('lgu_coordination_members')->where('user_id', $user->id)->whereNull('left_at')->where('joined_at', '<=', now())->pluck('circle_id');
        $group = $circles->isEmpty() ? 0 : DB::table('lgu_coordination_messages as message')
            ->leftJoin('lgu_coordination_message_reads as read', fn ($join) => $join->on('read.message_id', '=', 'message.id')->where('read.user_id', $user->id))
            ->whereIn('message.circle_id', $circles)->where('message.author_id', '!=', $user->id)->whereNull('read.read_at')
            ->count();
        $direct = DB::table('coordination_direct_messages')->where('recipient_id', $user->id)->whereNull('read_at')->whereIn('circle_id', $circles)->count();

        return $group + $direct;
    }

    public static function team(User $user): int
    {
        return DB::table('connect_messages as message')
            ->join('connect_thread_members as member', fn ($join) => $join->on('member.thread_id', '=', 'message.thread_id')->where('member.user_id', $user->id)->whereNull('member.left_at'))
            ->where('message.sender_id', '!=', $user->id)
            ->whereRaw('message.id > COALESCE(member.last_read_message_id, 0)')
            ->count();
    }
}
