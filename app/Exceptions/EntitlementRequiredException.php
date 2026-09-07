<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;

/**
 * Raised when a capability is used that the Company's current plan does not include
 * (a boolean entitlement such as `data_migration` or `customization`).
 *
 * This is feature-level only - it never changes the Company's global access state.
 */
class EntitlementRequiredException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $feature,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request)
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'ENTITLEMENT_REQUIRED',
            'feature' => $this->feature,
        ], 403);
    }
}
