<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PointsBatchNumberService
{
    public function make(User|int $user, int $insuranceCompanyId, string $periodDate): string
    {
        $userId = $user instanceof User ? (int) $user->id : $user;

        if ($userId < 0 || $userId > 99) {
            throw ValidationException::withMessages([
                'user' => ['ID používateľa musí mať pre číslo dávky hodnotu od 0 do 99.'],
            ]);
        }

        $insuranceCode = DB::table('insurance_companies')
            ->where('id', $insuranceCompanyId)
            ->value('branch_code');

        $insuranceCode = trim((string) $insuranceCode);

        if (! in_array($insuranceCode, ['25', '24', '27'], true)) {
            throw ValidationException::withMessages([
                'insurance' => ['Poisťovňa musí mať nastavený dvojmiestny kód 25, 24 alebo 27.'],
            ]);
        }

        return str_pad((string) $userId, 2, '0', STR_PAD_LEFT)
            . Carbon::parse($periodDate)->format('m')
            . $insuranceCode;
    }
}
