<?php

namespace App\Services\Vertex\Training;

use InvalidArgumentException;

class VertexTrainingTasks
{
    public function __construct(
        private readonly DekurzTrainingTask $dekurz,
        private readonly ProposalTrainingTask $proposal,
    ) {}

    public function get(string $pipeline): VertexTrainingTask
    {
        return match ($pipeline) {
            'dekurz' => $this->dekurz,
            'proposal' => $this->proposal,
            default => throw new InvalidArgumentException("Neznámy Vertex pipeline: {$pipeline}"),
        };
    }

    /**
     * @return array<int, VertexTrainingTask>
     */
    public function all(): array
    {
        return [$this->dekurz, $this->proposal];
    }

    /**
     * @return array<int, string>
     */
    public function pipelines(): array
    {
        return array_map(fn (VertexTrainingTask $task) => $task->pipeline(), $this->all());
    }
}
