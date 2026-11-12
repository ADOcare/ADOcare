<?php

namespace App\Services\Patients;

use App\Models\Patient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PatientHistoryJournal
{
    public function __construct(private PatientHistoryResolver $resolver) {}

    public function record(
        Patient $patient,
        string $changeType,
        ?int $actorId,
        array $items,
        ?string $reason = null,
        array $metadata = [],
    ): ?int {
        if ($items === [] && ! in_array($changeType, ['creation', 'baseline'], true)) {
            return null;
        }

        $recordedAt = now();
        $snapshot = $this->snapshot($patient);
        $changeId = DB::table('patient_history_changes')->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $actorId,
            'change_type' => $changeType,
            'recorded_at' => $recordedAt,
            'reason' => filled($reason) ? trim($reason) : null,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ]);

        foreach ($items as $item) {
            $category = $item['category'];
            $previous = $item['previous_values'] ?? null;
            $next = $item['new_values'] ?? [];
            $itemMetadata = array_merge($item['metadata'] ?? [], [
                'previous_display_values' => $this->displayValues($category, $previous),
                'new_display_values' => $this->displayValues($category, $next),
            ]);

            DB::table('patient_history_change_items')->insert([
                'history_change_id' => $changeId,
                'category' => $category,
                'previous_values' => $previous === null
                    ? null
                    : json_encode($previous, JSON_THROW_ON_ERROR),
                'new_values' => json_encode($next, JSON_THROW_ON_ERROR),
                'effective_date' => $item['effective_date'] ?? null,
                'metadata' => json_encode($itemMetadata, JSON_THROW_ON_ERROR),
                'created_at' => $recordedAt,
                'updated_at' => $recordedAt,
            ]);
        }

        return $changeId;
    }

    public function revisionsFor(Patient $patient): array
    {
        $changes = DB::table('patient_history_changes')
            ->where('patient_id', $patient->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get();

        if ($changes->isEmpty()) {
            return [];
        }

        $items = DB::table('patient_history_change_items')
            ->whereIn('history_change_id', $changes->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('history_change_id');
        $actors = DB::table('users')
            ->whereIn('id', $changes->pluck('user_id')->filter()->unique())
            ->get(['id', 'title', 'first_name', 'last_name'])
            ->keyBy('id');

        return $changes->map(function ($change) use ($items, $actors): array {
            $actor = $actors->get($change->user_id);

            return [
                'id' => $change->id,
                'change_type' => $change->change_type,
                'recorded_at' => $change->recorded_at,
                'reason' => $change->reason,
                'user' => $actor ? [
                    'id' => $actor->id,
                    'name' => trim(implode(' ', array_filter([
                        $actor->title,
                        $actor->first_name,
                        $actor->last_name,
                    ]))),
                ] : null,
                'snapshot' => $this->withDisplayValues(
                    json_decode($change->snapshot, true, flags: JSON_THROW_ON_ERROR),
                ),
                'metadata' => $change->metadata
                    ? json_decode($change->metadata, true, flags: JSON_THROW_ON_ERROR)
                    : [],
                'items' => $items->get($change->id, collect())->map(fn ($item): array => [
                    'id' => $item->id,
                    'category' => $item->category,
                    'previous_values' => $item->previous_values
                        ? json_decode($item->previous_values, true, flags: JSON_THROW_ON_ERROR)
                        : null,
                    'new_values' => json_decode($item->new_values, true, flags: JSON_THROW_ON_ERROR),
                    'effective_date' => $item->effective_date,
                    'metadata' => $item->metadata
                        ? json_decode($item->metadata, true, flags: JSON_THROW_ON_ERROR)
                        : [],
                ])->values()->all(),
            ];
        })->all();
    }

    public function impactFor(Patient $patient, array $effectiveDates): array
    {
        $pastDates = collect($effectiveDates)
            ->filter(fn ($date) => filled($date) && $date < now()->toDateString())
            ->values();

        if ($pastDates->isEmpty()) {
            return ['has_impact' => false, 'from' => null, 'counts' => []];
        }

        $from = $pastDates->min();
        $to = now()->toDateString();
        $counts = [];

        if (Schema::hasTable('visits')) {
            $counts['visits'] = DB::table('visits')
                ->where('patient_id', $patient->id)
                ->whereBetween('date', [$from, $to])
                ->count();
        }
        if (Schema::hasTable('patient_points')) {
            $counts['patient_points'] = DB::table('patient_points')
                ->where('patient_id', $patient->id)
                ->whereBetween('date', [$from, $to])
                ->count();
        }
        if (Schema::hasTable('point_claim_lines') && Schema::hasTable('patient_points')) {
            $counts['claim_lines'] = DB::table('point_claim_lines as pcl')
                ->join('patient_points as pp', 'pp.id', '=', 'pcl.patient_point_id')
                ->where('pp.patient_id', $patient->id)
                ->whereBetween('pp.date', [$from, $to])
                ->count();
        }
        if (Schema::hasTable('transport_claim_lines')) {
            $counts['transport_claim_lines'] = DB::table('transport_claim_lines')
                ->where('patient_id', $patient->id)
                ->whereExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('patient_points')
                    ->whereColumn('patient_points.id', 'transport_claim_lines.patient_point_id')
                    ->whereBetween('patient_points.date', [$from, $to]))
                ->count();
        }

        return [
            'has_impact' => collect($counts)->sum() > 0,
            'from' => $from,
            'to' => $to,
            'counts' => $counts,
        ];
    }

    public function stateOnDate(Patient $patient, CarbonInterface|string $date): array
    {
        return $this->withDisplayValues($this->resolver->patientOn($patient, $date));
    }

    private function snapshot(Patient $patient): array
    {
        return $this->stateOnDate($patient, now()->toDateString());
    }

    private function withDisplayValues(array $snapshot): array
    {
        foreach (['name', 'identity', 'contact', 'address', 'doctor', 'nurse', 'branch', 'insurance'] as $category) {
            if (! isset($snapshot[$category]) || ! is_array($snapshot[$category])) {
                continue;
            }

            $resolved = $this->displayValues($category, $snapshot[$category]['values'] ?? null);
            if ($resolved === null) {
                continue;
            }

            $stored = $snapshot[$category]['display_values'] ?? [];
            $snapshot[$category]['display_values'] = array_replace(
                $resolved,
                is_array($stored) ? $stored : [],
            );
        }

        return $snapshot;
    }

    private function displayValues(string $category, ?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $display = $values;
        if ($category === 'doctor' && filled($values['doctor_id'] ?? null) && Schema::hasTable('doctors')) {
            $doctor = DB::table('doctors')->find($values['doctor_id']);
            $display['doctor_id'] = $doctor
                ? trim(implode(' ', array_filter([$doctor->title, $doctor->first_name, $doctor->last_name])))
                : $values['doctor_id'];
        } elseif ($category === 'nurse' && filled($values['nurse_id'] ?? null) && Schema::hasTable('users')) {
            $user = DB::table('users')->find($values['nurse_id']);
            $display['nurse_id'] = $user
                ? trim(implode(' ', array_filter([$user->title, $user->first_name, $user->last_name])))
                : $values['nurse_id'];
        } elseif ($category === 'branch' && filled($values['branch_id'] ?? null) && Schema::hasTable('branches')) {
            $branch = DB::table('branches')->find($values['branch_id']);
            $display['branch_id'] = $branch
                ? trim(implode(' · ', array_filter([$branch->code, $branch->city, $branch->address])))
                : $values['branch_id'];
        } elseif ($category === 'address' && filled($values['country_id'] ?? null) && Schema::hasTable('countries')) {
            $display['country_id'] = DB::table('countries')->where('id', $values['country_id'])->value('name')
                ?? $values['country_id'];
        } elseif ($category === 'insurance' && filled($values['insurance_company_id'] ?? null) && Schema::hasTable('insurance_companies')) {
            $display['insurance_company_id'] = DB::table('insurance_companies')
                ->where('id', $values['insurance_company_id'])
                ->value('name') ?? $values['insurance_company_id'];
        }

        return $display;
    }
}
