<?php

namespace App\Console\Commands;

use App\Models\DekurzAiFeedback;
use App\Models\Document;
use App\Models\Patient;
use App\Models\ProposalAiFeedback;
use App\Services\Vertex\VertexFeedbackRecorder;
use Illuminate\Console\Command;

class BackfillVertexFeedback extends Command
{
    protected $signature = 'vertex:backfill-feedback
        {--pipeline=* : Pipeline (dekurz, proposal); predvolene všetky}
        {--since= : Spracuje iba dokumenty vytvorené od tohto dátumu (Y-m-d)}';

    protected $description = 'Doplní trénovacie príklady pre Vertex z už uložených Dekurzov a návrhov';

    public function handle(VertexFeedbackRecorder $recorder): int
    {
        $pipelines = $this->option('pipeline') ?: ['dekurz', 'proposal'];

        if (in_array('dekurz', $pipelines, true)) {
            $created = 0;
            $this->documents('dekurz', DekurzAiFeedback::class)->each(function (Document $document) use ($recorder, &$created): void {
                $created += $recorder->recordDekurz($document, null, false) ? 1 : 0;
            });
            $this->info("adocare-dekurz: pridaných {$created} príkladov.");
        }

        if (in_array('proposal', $pipelines, true)) {
            $created = 0;
            $this->documents('proposal', ProposalAiFeedback::class)->each(function (Document $document) use ($recorder, &$created): void {
                $patient = Patient::query()->find($document->patient_id);
                $created += $patient && $recorder->recordProposal($document, $patient, false) ? 1 : 0;
            });
            $this->info("adocare-proposal: pridaných {$created} príkladov.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $feedbackModel
     */
    private function documents(string $type, string $feedbackModel): \Illuminate\Support\LazyCollection
    {
        return Document::query()
            ->where('type', $type)
            ->where('mime_type', 'application/json')
            ->whereNotIn('id', $feedbackModel::query()->select('document_id'))
            ->when($this->option('since'), fn ($query, $since) => $query->where('created_at', '>=', $since))
            ->orderBy('id')
            ->lazyById(200);
    }
}
