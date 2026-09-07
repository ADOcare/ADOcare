<?php

namespace App\Enums;

/**
 * Application access state - distinct from billing/subscription state. Billing state answers
 * "what subscription does this Company have?"; this answers "what is it allowed to do right now?".
 */
enum AccessState: string
{
    case FULL = 'full';
    case READ_ONLY = 'read_only';
    case BLOCKED = 'blocked';
}
