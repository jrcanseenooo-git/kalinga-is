<?php

namespace App\Services;

use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

/** Controlled, explainable identity candidate and merge operations. */
class IdentityIntegrity
{
    public const ACTIVE = 'active';

    public function candidateKey(int $a, int $b): string
    {
        [$a, $b] = $a < $b ? [$a, $b] : [$b, $a];

        return "{$a}:{$b}";
    }

    public function signature(object $client): string
    {
        return hash('sha256', implode('|', [
            mb_strtolower(trim((string) $client->first_name)), mb_strtolower(trim((string) ($client->middle_name ?? ''))),
            mb_strtolower(trim((string) $client->last_name)), (string) $client->birthdate, (string) $client->sex,
            mb_strtolower(trim((string) $client->municipality)), mb_strtolower(trim((string) $client->barangay)),
            mb_strtolower(trim((string) ($client->primary_caregiver_name ?? ''))),
        ]));
    }

    /**
     * Bounded deterministic blocking. Signals are reviewer aids, not identity scoring.
     */
    public function generateForClient(int $clientId, ?int $actorId = null): void
    {
        $subject = DB::table('clients')->where('id', $clientId)->whereNull('deleted_at')->first();
        if (!$subject || ($subject->identity_status ?? self::ACTIVE) !== self::ACTIVE) {
            return;
        }
        $query = DB::table('clients')->whereNull('deleted_at')->where('identity_status', self::ACTIVE)->where('id', '!=', $clientId)
            ->where(function ($q) use ($subject) {
                $q->whereRaw('LOWER(last_name) = ?', [mb_strtolower((string) $subject->last_name)]);
                if ($subject->birthdate) {
                    $q->orWhere('birthdate', $subject->birthdate);
                }
            })->limit(100);
        foreach ($query->get() as $other) {
            $signals = $this->signals($subject, $other);
            // One commonplace field (such as a surname alone) is not enough to queue a person.
            if (count($signals['matches']) < 2) {
                continue;
            }
            [$a, $b] = $subject->id < $other->id ? [$subject, $other] : [$other, $subject];
            $context = $this->sameJurisdiction($subject, $other) ? 'same_scope' : 'cross_jurisdiction';
            DB::table('identity_duplicate_candidates')->updateOrInsert(['candidate_key' => $this->candidateKey($a->id, $b->id)], [
                'client_a_id' => $a->id, 'client_b_id' => $b->id, 'status' => 'potential_duplicate', 'matching_signals' => json_encode($signals),
                'jurisdiction_context' => $context, 'created_by' => $actorId, 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
    }

    public function signals(object $a, object $b): array
    {
        $matches = [];
        $conflicts = [];
        if ($this->normalizedName($a) === $this->normalizedName($b)) {
            $matches[] = 'exact_normalized_name';
        } elseif (mb_strtolower(trim((string) $a->last_name)) === mb_strtolower(trim((string) $b->last_name))) {
            $matches[] = 'same_normalized_surname';
        }
        if ($a->birthdate && $b->birthdate && $a->birthdate === $b->birthdate) {
            $matches[] = 'exact_dob';
        } elseif ($a->birthdate && $b->birthdate) {
            $conflicts[] = 'conflicting_dob';
        }
        if ($a->sex && $b->sex && $a->sex === $b->sex) {
            $matches[] = 'same_sex';
        } elseif ($a->sex && $b->sex) {
            $conflicts[] = 'conflicting_sex';
        }
        if ($a->municipality && $a->municipality === $b->municipality) {
            $matches[] = 'same_municipality';
        }
        if ($a->barangay && $a->barangay === $b->barangay) {
            $matches[] = 'same_barangay';
        }
        if ($a->primary_caregiver_name && $a->primary_caregiver_name === $b->primary_caregiver_name) {
            $matches[] = 'same_caregiver_hint';
        }
        $identifierMatch = DB::table('client_identifiers as a')->join('client_identifiers as b', function ($join) {
            $join->on('a.identifier_type', '=', 'b.identifier_type')->on('a.normalized_hash', '=', 'b.normalized_hash');
        })->where('a.client_id', $a->id)->where('b.client_id', $b->id)->whereNotNull('a.normalized_hash')->exists();
        if ($identifierMatch) {
            $matches[] = 'matching_verified_identifier';
        }

        return ['matches' => $matches, 'conflicts' => $conflicts, 'generated_by' => 'deterministic_blocking_v1'];
    }

    public function canonicalClient(int $clientId): ?object
    {
        $client = DB::table('clients')->where('id', $clientId)->first();
        $seen = [];
        while ($client && $client->identity_status === 'merged' && $client->canonical_client_id && !isset($seen[$client->id])) {
            $seen[$client->id] = true;
            $client = DB::table('clients')->where('id', $client->canonical_client_id)->first();
        }

        return $client;
    }

    /**
     * The controller accepts this value only by POST so it cannot end up in a
     * URL, browser history, or access log. The database matches a keyed
     * normalized representation and never returns the supplied identifier.
     */
    public function identifierHash(string $identifier): string
    {
        $normalized = mb_strtolower(preg_replace('/[^[:alnum:]]/u', '', trim($identifier)) ?? '');

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    /** @return array<int, object> Canonical clients without identifier values. */
    public function canonicalClientsForIdentifier(string $identifierType, string $identifier): array
    {
        $hash = $this->identifierHash($identifier);

        return DB::table('client_identifiers')
            ->where('identifier_type', $identifierType)
            ->where('normalized_hash', $hash)
            ->pluck('client_id')
            ->map(fn ($clientId) => $this->canonicalClient((int) $clientId))
            ->filter()
            ->unique('id')
            ->values()
            ->all();
    }

    public function execute(int $mergeId, int $actorId): object
    {
        return DB::transaction(function () use ($mergeId, $actorId) {
            $merge = DB::table('client_identity_merges')->where('id', $mergeId)->lockForUpdate()->first();
            abort_unless($merge, 404);
            if ($merge->status === 'executed') {
                return $merge;
            } // safe retry
            abort_unless($merge->status === 'approved', 422, 'Only an approved merge may be executed.');
            $source = DB::table('clients')->where('id', $merge->source_client_id)->lockForUpdate()->first();
            $survivor = DB::table('clients')->where('id', $merge->survivor_client_id)->lockForUpdate()->first();
            abort_unless($source && $survivor && $source->id !== $survivor->id, 422, 'Invalid merge identities.');
            abort_unless($source->identity_status === self::ACTIVE && $survivor->identity_status === self::ACTIVE, 409, 'A source or survivor identity is no longer active.');
            if ($this->signature($source) !== $merge->source_signature || $this->signature($survivor) !== $merge->survivor_signature) {
                DB::table('client_identity_merges')->where('id', $mergeId)->update(['status' => 'stale', 'updated_at' => now()]);
                abort(409, 'Identity fields changed after approval; re-review is required.');
            }
            foreach (DB::table('client_identity_merge_field_decisions')->where('merge_id', $mergeId)->get() as $decision) {
                if ($decision->resolution === 'source_value') {
                    DB::table('clients')->where('id', $survivor->id)->update([$decision->field_name => $decision->frozen_value, 'updated_at' => now()]);
                }
            }
            foreach (DB::table('cases')->where('client_id', $source->id)->lockForUpdate()->get(['id']) as $record) {
                $this->affect($mergeId, 'case', $record->id, $source->id, $survivor->id);
            }
            foreach (DB::table('reports')->where('client_id', $source->id)->lockForUpdate()->get(['id']) as $record) {
                $this->affect($mergeId, 'report', $record->id, $source->id, $survivor->id);
            }
            DB::table('cases')->where('client_id', $source->id)->update(['client_id' => $survivor->id, 'updated_at' => now()]);
            DB::table('reports')->where('client_id', $source->id)->update(['client_id' => $survivor->id, 'updated_at' => now()]);
            DB::table('clients')->where('id', $source->id)->update(['identity_status' => 'merged', 'canonical_client_id' => $survivor->id, 'identity_updated_at' => now(), 'updated_at' => now()]);
            DB::table('client_identity_merges')->where('id', $mergeId)->update(['status' => 'executed', 'executed_by' => $actorId, 'executed_at' => now(), 'updated_at' => now()]);
            if ($merge->candidate_id) {
                DB::table('identity_duplicate_candidates')->where('id', $merge->candidate_id)->update(['status' => 'merged', 'updated_at' => now()]);
            }
            AuditLog::insert(['user_id' => $actorId, 'event' => 'identity_merge.executed', 'auditable_type' => 'client_identity_merge', 'auditable_id' => $mergeId, 'changes' => json_encode(['source_client_id' => $source->id, 'survivor_client_id' => $survivor->id, 'affected_cases' => DB::table('client_identity_merge_affected_records')->where('merge_id', $mergeId)->where('record_type', 'case')->count()]), 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('client_identity_merges')->find($mergeId);
        });
    }

    private function affect(int $mergeId, string $type, int $id, int $from, int $to): void
    {
        DB::table('client_identity_merge_affected_records')->insertOrIgnore(['merge_id' => $mergeId, 'record_type' => $type, 'record_id' => $id, 'from_client_id' => $from, 'to_client_id' => $to, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function recordIdentifierConflicts(int $mergeId, int $sourceClientId, int $survivorClientId): void
    {
        foreach (DB::table('client_identifiers')->where('client_id', $sourceClientId)->get() as $sourceIdentifier) {
            $survivorIdentifier = DB::table('client_identifiers')
                ->where('client_id', $survivorClientId)
                ->where('identifier_type', $sourceIdentifier->identifier_type)
                ->when($sourceIdentifier->normalized_hash, fn ($query, $hash) => $query->where('normalized_hash', '!=', $hash))
                ->first();
            if (!$survivorIdentifier) {
                continue;
            }
            DB::table('client_identity_merge_identifier_conflicts')->updateOrInsert([
                'merge_id' => $mergeId,
                'source_identifier_id' => $sourceIdentifier->id,
                'survivor_identifier_id' => $survivorIdentifier->id,
            ], [
                'identifier_type' => $sourceIdentifier->identifier_type,
                'resolution' => 'preserve_source_provenance',
                'updated_at' => now(), 'created_at' => now(),
            ]);
        }
    }

    private function normalizedName(object $client): string
    {
        return implode(' ', array_filter([mb_strtolower(trim((string) $client->first_name)), mb_strtolower(trim((string) ($client->middle_name ?? ''))), mb_strtolower(trim((string) $client->last_name)), mb_strtolower(trim((string) ($client->suffix ?? '')))]));
    }

    private function sameJurisdiction(object $a, object $b): bool
    {
        return trim((string) $a->municipality) !== '' && $a->municipality === $b->municipality && $a->province === $b->province;
    }
}
