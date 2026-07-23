<?php

namespace App\Services\Audit;

/**
 * Auth audit event types reserved per amendment 2.11 (implemented in S01).
 * Reserved here in S00 so the names are fixed before any auth code exists.
 */
enum AuthEventType: string
{
    case Login = 'login';
    case Logout = 'logout';
    case FailedLogin = 'failed_login';
    case Lockout = 'lockout';
    case ResetRequested = 'reset_requested';
    case ResetCompleted = 'reset_completed';
    case InvitationAccepted = 'invitation_accepted';
    case EmailVerified = 'email_verified';
}
