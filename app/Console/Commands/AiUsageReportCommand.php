<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiUsageReportCommand extends Command
{
    protected $signature = 'ai:usage
        {--month= : YYYY-MM (defaults to current month)}
        {--company= : Filter by company ID}
        {--user= : Filter by user ID}
        {--billing-context= : Filter: customer or internal_backfill}
        {--group-by=workflow : workflow, company or user}';

    protected $description = 'Summarise model token usage and estimated USD charges, without patient data.';

    public function handle(): int
    {
        $month = $this->option('month') ?: now()->format('Y-m');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->error('Month must be YYYY-MM.');
            return self::FAILURE;
        }

        $groupBy = (string) $this->option('group-by');
        $dimensions = [
            'workflow' => 'workflow',
            'company' => 'company_id',
            'user' => 'user_id',
        ];

        if (! isset($dimensions[$groupBy])) {
            $this->error('Use --group-by=workflow, company or user.');
            return self::FAILURE;
        }

        $dimension = $dimensions[$groupBy];
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->copy()->addMonth();
        $query = DB::table('ai_usage_events')->where('created_at', '>=', $start)->where('created_at', '<', $end);

        foreach (['company' => 'company_id', 'user' => 'user_id'] as $option => $column) {
            if ($this->option($option) !== null) {
                if (! ctype_digit((string) $this->option($option))) {
                    $this->error("Invalid --{$option} value.");
                    return self::FAILURE;
                }
                $query->where($column, (int) $this->option($option));
            }
        }

        $billingContext = $this->option('billing-context');
        if ($billingContext !== null) {
            if (! in_array($billingContext, ['customer', 'internal_backfill'], true)) {
                $this->error('Use --billing-context=customer or internal_backfill.');
                return self::FAILURE;
            }
            $query->where('billing_context', $billingContext);
        }

        // Only a whitelisted dimension reaches the raw SQL string.
        $records = $query->selectRaw("{$dimension} AS group_key, model, status,
                COUNT(*) AS requests,
                COALESCE(SUM(prompt_tokens), 0) AS input_tokens,
                COALESCE(SUM(candidate_tokens), 0) AS output_tokens,
                COALESCE(SUM(thought_tokens), 0) AS thought_tokens,
                COALESCE(SUM(estimated_cost_usd), 0) AS cost_usd,
                SUM(CASE WHEN estimated_cost_usd IS NULL AND status IN ('succeeded', 'invalid_output')
                    THEN 1 ELSE 0 END) AS unpriced")
            ->groupBy($dimension, 'model', 'status')
            ->orderBy($dimension)
            ->get();

        $rows = $records->map(fn ($record) => [
            $record->group_key ?? '(unattributed)',
            $record->model,
            $record->status,
            (int) $record->requests,
            (int) $record->input_tokens,
            (int) $record->output_tokens,
            (int) $record->thought_tokens,
            number_format((float) $record->cost_usd, 6, '.', ''),
            (int) $record->unpriced,
        ])->all();

        $this->table([ucfirst($groupBy), 'Model', 'Status', 'Calls', 'Input', 'Output', 'Thoughts', 'Est. USD', 'Unpriced'], $rows);
        $this->warn('USD values are only estimates, not invoices. Unknown rates appear as Unpriced. Failed calls may still incur charges.');

        return self::SUCCESS;
    }
}
