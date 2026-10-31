<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VertexTrainingRunStatusNotification extends Notification
{
    use Queueable;

    private const PIPELINE_LABELS = [
        'dekurz' => 'adocare-dekurz',
        'proposal' => 'adocare-proposal',
    ];

    private const EVENT_LABELS = [
        'run_started' => 'Retrénovanie spustené',
        'run_skipped' => 'Retrénovanie nespustené',
        'dataset_ready' => 'Dataset pripravený',
        'dataset_skipped' => 'Retrénovanie preskočené – málo dát',
        'dataset_build_failed' => 'CHYBA – tvorba datasetu zlyhala',
        'dataset_duplicate_skipped' => 'Retrénovanie preskočené – rovnaký dataset',
        'training_started' => 'Tréning modelu spustený',
        'training_polling_resumed' => 'Sledovanie tréningu obnovené',
        'training_request_failed' => 'CHYBA – tréning sa nepodarilo spustiť',
        'training_failed' => 'CHYBA – tréning zlyhal',
        'training_timeout' => 'CHYBA – tréning prekročil časový limit',
        'training_succeeded' => 'Tréning dokončený',
        'deployment_resolution_failed' => 'CHYBA – nasadenie modelu sa nenašlo',
        'evaluation_failed' => 'CHYBA – vyhodnotenie zlyhalo',
        'evaluation_rejected' => 'Nový model zamietnutý (horšie skóre)',
        'ready_for_promotion' => 'Nový model pripravený na nasadenie',
        'promotion_failed' => 'CHYBA – nasadenie modelu zlyhalo',
        'promoted' => 'Nový model nasadený',
        'rolled_back' => 'CHYBA – nový model vrátený späť',
        'health_check_passed' => 'Nový model úspešne nasadený a overený',
        'job_crashed' => 'CHYBA – úloha retrénovania spadla',
    ];

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        private readonly string $event,
        private readonly array $context = []
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $pipeline = (string) ($this->context['pipeline'] ?? 'dekurz');
        $model = self::PIPELINE_LABELS[$pipeline] ?? $pipeline;
        $label = self::EVENT_LABELS[$this->event] ?? $this->event;
        $value = fn (string $key) => ($text = trim((string) ($this->context[$key] ?? ''))) === '' ? '-' : $text;

        $mail = (new MailMessage)
            ->subject("ADOcare AI {$model}: {$label}")
            ->greeting($label)
            ->line("Model: {$model}")
            ->line('Udalosť: '.$this->event)
            ->line('Stav: '.$value('status'));

        foreach ([
            'run_id' => 'Run ID',
            'dataset_version' => 'Verzia datasetu',
            'new_examples' => 'Príklady',
            'tuning_job_name' => 'Tuning job',
            'previous_endpoint' => 'Predchádzajúci endpoint',
            'candidate_endpoint' => 'Nový endpoint',
            'failure_stage' => 'Fáza chyby',
        ] as $key => $title) {
            if ($value($key) !== '-') {
                $mail->line("{$title}: ".$value($key));
            }
        }

        if ($value('candidate_score') !== '-' || $value('current_score') !== '-') {
            $mail->line('Skóre nový / aktuálny: '.$value('candidate_score').' / '.$value('current_score'));
        }

        return $mail->line('Správa: '.$value('message'));
    }
}
