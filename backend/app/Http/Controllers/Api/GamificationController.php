<?php

namespace App\Http\Controllers\Api;

use App\Domain\Gamification\GamificationService;
use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\Challenge;
use App\Models\ChallengeProgress;
use App\Models\StreakDay;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserPreference;
use App\Models\XpEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GamificationController extends Controller
{
    public function __construct(private readonly GamificationService $game) {}

    /** Compact block for the dashboard. */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = $this->game->today($user)->toDateString();
        $weekStart = $this->game->today($user)->startOfWeek(Carbon::MONDAY)->toDateString();

        return response()->json(['data' => [
            'xp_total' => (int) XpEntry::where('user_id', $user->id)->sum('amount'),
            'xp_today' => (int) XpEntry::where('user_id', $user->id)->where('earned_on', $today)->sum('amount'),
            'xp_week' => (int) XpEntry::where('user_id', $user->id)->where('earned_on', '>=', $weekStart)->sum('amount'),
            'streak' => $this->game->streak($user),
            'achievements_unlocked' => UserAchievement::where('user_id', $user->id)->count(),
            'achievements_total' => Achievement::where('is_active', true)->count(),
            'leaderboard_opt_in' => (bool) $user->preferences?->leaderboard_opt_in,
        ]]);
    }

    /** XP history: last 30 days per day and source, plus streak calendar. */
    public function xp(Request $request): JsonResponse
    {
        $user = $request->user();
        $from = $this->game->today($user)->subDays(29)->toDateString();

        return response()->json(['data' => [
            'daily' => XpEntry::where('user_id', $user->id)->where('earned_on', '>=', $from)
                ->groupBy('earned_on', 'source')->orderBy('earned_on')
                ->selectRaw('earned_on, source, sum(amount) as xp')->get()
                ->map(fn ($r) => ['date' => Carbon::parse($r->earned_on)->toDateString(), 'source' => $r->source, 'xp' => (int) $r->xp]),
            'streak_days' => StreakDay::where('user_id', $user->id)->where('date', '>=', $from)->orderBy('date')->get()
                ->map(fn ($d) => ['date' => $d->date->toDateString(), 'kind' => $d->kind]),
        ]]);
    }

    public function achievements(Request $request): JsonResponse
    {
        $user = $request->user();
        $metrics = $this->game->metrics($user);
        $unlocked = UserAchievement::where('user_id', $user->id)->pluck('unlocked_at', 'achievement_id');

        return response()->json(['data' => Achievement::where('is_active', true)->with('translations')->orderBy('position')->get()->map(fn (Achievement $a) => [
            'slug' => $a->slug, 'title' => $a->translate('title'), 'description' => $a->translate('description'), 'icon' => $a->icon,
            'metric' => $a->metric, 'threshold' => $a->threshold, 'xp_reward' => $a->xp_reward,
            'progress' => min($a->threshold, $metrics[$a->metric] ?? 0),
            'unlocked_at' => isset($unlocked[$a->id]) ? Carbon::parse($unlocked[$a->id])->toIso8601String() : null,
        ])]);
    }

    public function challenges(Request $request): JsonResponse
    {
        $user = $request->user();
        $challenges = $this->game->currentChallenges();
        $done = ChallengeProgress::where('user_id', $user->id)->whereIn('challenge_id', $challenges->pluck('id'))->pluck('completed_at', 'challenge_id');

        return response()->json(['data' => $challenges->map(fn (Challenge $c) => [
            'slug' => $c->slug, 'metric' => $c->metric, 'target' => $c->target, 'xp_reward' => $c->xp_reward,
            'progress' => min($c->target, $this->game->challengeProgress($user, $c)),
            'completed' => isset($done[$c->id]) && $done[$c->id] !== null,
            'ends_on' => $c->ends_on->toDateString(),
        ])]);
    }

    /** Weekly XP among learners who opted in. Others never appear, and their XP is not exposed. */
    public function leaderboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $monday = Carbon::now('UTC')->startOfWeek(Carbon::MONDAY);
        $rows = XpEntry::query()
            ->join('user_preferences', 'user_preferences.user_id', '=', 'xp_ledger.user_id')
            ->join('users', 'users.id', '=', 'xp_ledger.user_id')
            ->where('user_preferences.leaderboard_opt_in', true)
            ->where('users.status', User::STATUS_ACTIVE)
            ->whereBetween('xp_ledger.earned_on', [$monday->toDateString(), $monday->copy()->addDays(6)->toDateString()])
            ->groupBy('xp_ledger.user_id', 'users.name', 'user_preferences.leaderboard_alias')
            ->selectRaw('xp_ledger.user_id, users.name, user_preferences.leaderboard_alias as alias, sum(xp_ledger.amount) as xp')
            ->orderByDesc('xp')->orderBy('xp_ledger.user_id')
            ->get();

        $ranked = $rows->values()->map(fn ($r, $i) => [
            'rank' => $i + 1, 'name' => $r->alias ?: self::shortName($r->name), 'xp' => (int) $r->xp, 'is_you' => $r->user_id === $user->id,
        ]);

        return response()->json(['data' => [
            'week_start' => $monday->toDateString(),
            'opted_in' => (bool) $user->preferences?->leaderboard_opt_in,
            'entries' => $ranked->take(config('gamification.leaderboard_size'))->values(),
            'you' => $ranked->firstWhere('is_you', true),
        ]]);
    }

    public function updateLeaderboard(Request $request): JsonResponse
    {
        $data = $request->validate(['opt_in' => ['required', 'boolean'], 'alias' => ['nullable', 'string', 'min:2', 'max:40', 'regex:/^[\pL\pN _.-]+$/u']]);
        DB::transaction(fn () => UserPreference::updateOrCreate(['user_id' => $request->user()->id], [
            'leaderboard_opt_in' => $data['opt_in'], 'leaderboard_alias' => $data['alias'] ?? null,
        ]));
        $request->user()->unsetRelation('preferences');

        return $this->summary($request);
    }

    /** "Nadia Haddad" → "Nadia H." — full names are never published. */
    private static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [$name];

        return count($parts) > 1 ? $parts[0].' '.mb_substr(end($parts), 0, 1).'.' : $parts[0];
    }
}
