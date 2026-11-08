<?php

namespace App\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Generic service to perform safe bulk deletions of Eloquent models.
 *
 * Usage:
 *   $svc = new BulkDeletionService();
 *   $result = $svc->delete(Model::class, $ids);
 *
 * Returns array with keys:
 *  - 'queued' => bool
 *  - 'deleted' => int (if not queued)
 *  - 'error' => string|null
 */
class BulkDeletionService
{
    public int $chunkSize;

    public int $queueThreshold;

    public int $forceSyncMax;

    public function __construct(?int $chunkSize = null, ?int $queueThreshold = null, ?int $forceSyncMax = null)
    {
        $this->chunkSize = $chunkSize ?? config('bulk_delete.chunk_size', 100);
        $this->queueThreshold = $queueThreshold ?? config('bulk_delete.queue_threshold', 500);
        $this->forceSyncMax = $forceSyncMax ?? config('bulk_delete.force_sync_max', 2000);
    }

    /**
     * Delete given ids for the model class. If the id count exceeds the queue threshold
     * the method will dispatch the job (caller must handle queued flow) and return ['queued' => true].
     * Otherwise it performs transactional, chunked model deletions and preserves model events.
     *
     * @param  string  $modelClass  Fully-qualified model class
     * @param  \Closure|null  $onChunk  Optional callback invoked per-chunk with (Collection $chunk)
     */
    public function delete(string $modelClass, array $ids, ?\Closure $onChunk = null, bool $forceSync = false): array
    {
        $count = count($ids);
        if ($count === 0) {
            return ['queued' => false, 'deleted' => 0, 'error' => null];
        }

        if ($count > $this->queueThreshold && ! $forceSync) {
            // Caller should dispatch job. We just indicate queued unless forceSync is true.
            return ['queued' => true, 'deleted' => 0, 'error' => null];
        }

        $deletedCount = 0;
        try {
            foreach (array_chunk($ids, $this->chunkSize) as $chunkIds) {
                DB::transaction(function () use ($modelClass, $chunkIds, $onChunk, &$deletedCount) {
                    // lock and verify existence for this chunk
                    $items = $modelClass::whereIn('id', $chunkIds)->lockForUpdate()->get();
                    if ($items->count() !== count($chunkIds)) {
                        throw new \RuntimeException('Some items not found for delete');
                    }

                    if ($onChunk) {
                        $onChunk($items);
                    }

                    foreach ($items as $item) {
                        $item->delete();
                        $deletedCount++;
                    }
                });
            }

            return ['queued' => false, 'deleted' => $deletedCount, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('BulkDeletionService failed', ['error' => $e->getMessage(), 'ids' => $ids, 'model' => $modelClass]);

            return ['queued' => false, 'deleted' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * For queued jobs: process deletions in chunks without trying to re-dispatch job.
     * This method is resilient and will attempt deletes while logging errors per-item.
     *
     * @return array ['processed' => int, 'failed' => int]
     */
    public function processQueued(string $modelClass, array $ids): array
    {
        $processed = 0;
        $failed = 0;

        foreach (array_chunk($ids, $this->chunkSize) as $chunkIds) {
            $items = $modelClass::whereIn('id', $chunkIds)->get();
            foreach ($items as $item) {
                try {
                    $item->delete();
                    $processed++;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('BulkDeletionService: failed to delete item in job', ['id' => $item->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    /**
     * Authorize the provided ids for deletion for the given user.
     * Throws AuthorizationException if any item is not deletable by the user.
     * Throws RuntimeException if any id is missing.
     *
     * @param  \App\Models\User  $user
     */
    public function authorizeIds($user, string $modelClass, array $ids): void
    {
        foreach (array_chunk($ids, $this->chunkSize) as $chunkIds) {
            $items = $modelClass::whereIn('id', $chunkIds)->get();
            if ($items->count() !== count($chunkIds)) {
                throw new \RuntimeException('Some items not found');
            }

            foreach ($items as $item) {
                if (! Gate::forUser($user)->allows('delete', $item)) {
                    throw new AuthorizationException('Not allowed to delete some items');
                }
            }
        }
    }

    /**
     * Orchestrator: pure, model-agnostic API to handle a bulk-delete request.
     * Does not emit HTTP responses or dispatch jobs; returns a structured
     * result the controller can map to HTTP responses.
     *
     * @return array ['queued'=>bool, 'deleted'=>int, 'error'=>string|null]
     */
    /**
     * Handle a bulk-delete request.
     *
     * @param  bool  $preAuthorized  Set true when the caller (FormRequest) already ran per-item authorization.
     * @return array ['queued'=>bool,'deleted'=>int,'error'=>null|'no_ids'|'not_found'|'forbidden'|'internal']
     */
    public function handleBulkDelete(\App\Models\User $user, string $modelClass, array $ids, bool $preAuthorized = false): array
    {
        $count = is_array($ids) ? count($ids) : 0;
        if ($count === 0) {
            return ['queued' => false, 'deleted' => 0, 'error' => 'no_ids'];
        }

        // Queue path decision: if above threshold and the user cannot force sync, prepare to queue.
        if ($count > $this->queueThreshold && ! Gate::forUser($user)->allows('forceBulkDelete', $modelClass)) {
            if (! $preAuthorized) {
                try {
                    // Lightweight check: ensure items exist and the user can delete them.
                    $this->authorizeIds($user, $modelClass, $ids);
                } catch (AuthorizationException $e) {
                    Log::warning('BulkDeletionService pre-queue authorization denied', ['model' => $modelClass, 'user_id' => $user->id, 'error' => $e->getMessage()]);

                    return ['queued' => false, 'deleted' => 0, 'error' => 'forbidden'];
                } catch (\Throwable $e) {
                    Log::warning('BulkDeletionService pre-queue check failed', ['model' => $modelClass, 'user_id' => $user->id, 'error' => $e->getMessage()]);

                    return ['queued' => false, 'deleted' => 0, 'error' => 'not_found'];
                }
            }

            return ['queued' => true, 'deleted' => 0, 'error' => null];
        }

        // Synchronous path: perform deletions in chunked, transactional passes and
        // check per-item policy within the transaction to avoid double-fetch.
        try {
            $deleted = 0;
            foreach (array_chunk($ids, $this->chunkSize) as $chunkIds) {
                DB::transaction(function () use ($modelClass, $chunkIds, $user, &$deleted) {
                    $items = $modelClass::whereIn('id', $chunkIds)->lockForUpdate()->get();
                    if ($items->count() !== count($chunkIds)) {
                        throw new \RuntimeException('Some items not found for delete');
                    }

                    foreach ($items as $item) {
                        if (! Gate::forUser($user)->allows('delete', $item)) {
                            throw new AuthorizationException('Not allowed to delete some items');
                        }
                        $item->delete();
                        $deleted++;
                    }
                });
            }

            return ['queued' => false, 'deleted' => $deleted, 'error' => null];
        } catch (AuthorizationException $e) {
            Log::warning('BulkDeletionService forbidden during sync delete', ['model' => $modelClass, 'user_id' => $user->id, 'error' => $e->getMessage()]);

            return ['queued' => false, 'deleted' => 0, 'error' => 'forbidden'];
        } catch (QueryException $e) {
            Log::warning('BulkDeletionService database conflict during sync delete', ['model' => $modelClass, 'user_id' => $user->id, 'error' => $e->getMessage()]);

            return ['queued' => false, 'deleted' => 0, 'error' => 'database_conflict'];
        } catch (\RuntimeException $e) {
            Log::warning('BulkDeletionService not found during sync delete', ['model' => $modelClass, 'user_id' => $user->id, 'error' => $e->getMessage()]);

            return ['queued' => false, 'deleted' => 0, 'error' => 'not_found'];
        } catch (\Throwable $e) {
            Log::error('BulkDeletionService failed during sync delete', ['model' => $modelClass, 'user_id' => $user->id, 'error' => $e->getMessage()]);

            return ['queued' => false, 'deleted' => 0, 'error' => 'internal'];
        }
    }
}
