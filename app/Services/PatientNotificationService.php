<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Collection;

class PatientNotificationService
{
    public function createEvent(
        Patient $patient,
        string $key,
        string $type,
        string $title,
        string $description,
        array $data = [],
    ): void {
        foreach ($this->recipients($patient) as $user) {
            AppNotification::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'key' => $key,
                ],
                [
                    'type' => $type,
                    'kind' => 'event',
                    'title' => $title,
                    'description' => $description,
                    'data' => $data,
                    'occurred_at' => now(),
                ],
            );
        }
    }

    public function reportIssue(
        Patient $patient,
        string $key,
        string $type,
        string $title,
        string $description,
        array $data = [],
    ): void {
        foreach ($this->recipients($patient) as $user) {
            AppNotification::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'key' => $key,
                ],
                [
                    'type' => $type,
                    'kind' => 'issue',
                    'title' => $title,
                    'description' => $description,
                    'data' => $data,
                    'occurred_at' => now(),
                    'dismissed_at' => null,
                    'resolved_at' => null,
                ],
            );
        }
    }

    public function resolveIssue(Patient $patient, string $key): void
    {
        AppNotification::query()
            ->where('key', $key)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
            ]);
    }

    private function recipients(Patient $patient): Collection
    {
        $patient->loadMissing('branch');

        $userIds = collect([$patient->nurse_id])->filter();
        $companyId = $patient->branch?->company_id;

        if ($companyId) {
            $managerIds = User::query()
                ->where('company_id', $companyId)
                ->whereHas('role', fn ($query) => $query->where('position', 'manager'))
                ->pluck('id');

            $userIds = $userIds->merge($managerIds);
        }

        return User::query()
            ->whereIn('id', $userIds->unique()->values())
            ->get();
    }
}
