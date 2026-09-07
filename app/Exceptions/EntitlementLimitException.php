<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;

/**
 * Raised when an operation would exceed a plan entitlement (e.g. creating one more user
 * than the plan's `users` allowance). Carries machine-readable context so the frontend can
 * explain exactly which allowance was hit without parsing a message string.
 *
 * Being over a limit never removes existing data - this is only ever thrown to stop further
 * growth of a resource.
 */
class EntitlementLimitException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $feature,
        public readonly ?int $limit,
        public readonly int $current,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request)
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'ENTITLEMENT_LIMIT_REACHED',
            'feature' => $this->feature,
            'limit' => $this->limit,
            'current' => $this->current,
        ], 403);
    }
}
