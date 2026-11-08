<?php

namespace App\Services;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Helper to map bulk-deletion service results to HTTP responses and perform logging.
 * Model-agnostic — controllers can call this to get a ready-to-return response.
 */
class BulkDeletionResponder
{
    /**
     * Map service result to HTTP response. Accepts optional message overrides.
     *
     * @param  array  $result  Service result: ['queued'=>bool,'deleted'=>int,'error'=>null|string]
     * @param  array  $messages  Optional overrides for keys: queued, success, forbidden, not_found, no_ids, internal
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    public function respond(array $result, string $modelClass, array $ids, ?int $userId = null, array $messages = [])
    {
        $defaults = [
            'queued' => 'Vymazávanie bolo zaradené do fronty',
            'success' => 'Záznamy boli vymazané',
            'forbidden' => 'Nemáte oprávnenie vymazať niektoré záznamy',
            'not_found' => 'Niektoré záznamy neboli nájdené',
            'database_conflict' => 'Záznamy nie je možné vymazať, pretože sa používajú v iných údajoch',
            'no_ids' => 'Nie sú poskytnuté žiadne id',
            'internal' => 'Chyba pri vymazávaní',
        ];

        $m = array_merge($defaults, $messages);

        // queued -> accepted JSON
        if (! empty($result['queued'])) {
            Log::info('Bulk deletion queued', ['model' => $modelClass, 'count' => count($ids), 'user_id' => $userId]);

            return response()->json(['message' => $m['queued'], 'data' => null], Response::HTTP_ACCEPTED);
        }

        // error mapping
        if (! empty($result['error'])) {
            $code = $result['error'];
            switch ($code) {
                case 'forbidden':
                    Log::warning('Bulk deletion forbidden', ['model' => $modelClass, 'user_id' => $userId]);

                    return response()->json(['message' => $m['forbidden'], 'errors' => null], Response::HTTP_FORBIDDEN);
                case 'not_found':
                    Log::warning('Bulk deletion not found', ['model' => $modelClass, 'user_id' => $userId]);

                    return response()->json(['message' => $m['not_found'], 'errors' => null], Response::HTTP_NOT_FOUND);
                case 'database_conflict':
                    Log::warning('Bulk deletion database conflict', ['model' => $modelClass, 'user_id' => $userId]);

                    return response()->json(['message' => $m['database_conflict'], 'errors' => null], Response::HTTP_CONFLICT);
                case 'no_ids':
                    Log::warning('Bulk deletion called with no ids', ['model' => $modelClass, 'user_id' => $userId]);

                    return response()->json(['message' => $m['no_ids'], 'errors' => null], Response::HTTP_BAD_REQUEST);
                case 'internal':
                default:
                    Log::error('Bulk deletion failed', ['model' => $modelClass, 'user_id' => $userId, 'error' => $result['error']]);

                    return response()->json(['message' => $m['internal'], 'errors' => null], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        // success: return 204 No Content
        Log::info('Bulk deletion completed', ['model' => $modelClass, 'deleted' => $result['deleted'] ?? 0, 'user_id' => $userId]);

        return response()->noContent();
    }
}
