<?php

namespace App\Services;

use App\Models\Procedure;
use Illuminate\Support\Facades\DB;

class ProcedureService
{
    public function __construct(private ProcedurePriceHistoryService $priceHistory) {}

    public function createWithPrices(array $data, ?int $companyId = null): Procedure
    {
        return DB::transaction(function () use ($data, $companyId) {
            $procedure = Procedure::create([
                'code' => $data['code'],
                'description' => $data['description'],
            ]);
            $procedure->roles()->sync($data['role_ids']);

            if (! empty($data['prices']) && $companyId) {
                $this->priceHistory->applyPrices(
                    $procedure->id,
                    $data['prices'],
                    $companyId,
                    auth()->id(),
                    true,
                );
            }

            return Procedure::with(['insuranceCompaniesPricesMinimal', 'roles'])->find($procedure->id);
        });
    }

    public function updateWithPrices(Procedure $procedure, array $data, ?int $companyId = null): Procedure
    {
        return DB::transaction(function () use ($procedure, $data, $companyId) {
            $updates = array_intersect_key($data, array_flip(['code', 'description']));
            if (! empty($updates)) {
                $procedure->update($updates);
            }

            if (array_key_exists('role_ids', $data)) {
                $procedure->roles()->sync($data['role_ids']);
            }

            if (! empty($data['prices']) && $companyId) {
                $this->priceHistory->applyPrices(
                    $procedure->id,
                    $data['prices'],
                    $companyId,
                    auth()->id(),
                );
            }

            return Procedure::with(['insuranceCompaniesPricesMinimal', 'roles'])->find($procedure->id);
        });
    }

    public function destroy(Procedure $procedure): void
    {
        // remove prices and delete procedure
        DB::table('procedure_company_prices')->where('procedure_id', $procedure->id)->delete();
        $procedure->delete();
    }

    public function destroyMany(array $ids): void
    {
        DB::table('procedure_company_prices')->whereIn('procedure_id', $ids)->delete();
        Procedure::whereIn('id', $ids)->delete();
    }

}
