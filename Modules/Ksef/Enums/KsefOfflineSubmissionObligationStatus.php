<?php

namespace Modules\Ksef\Enums;

enum KsefOfflineSubmissionObligationStatus: string
{
    case Pending = 'PENDING';
    case DueToday = 'DUE_TODAY';
    case Overdue = 'OVERDUE';
    case WaitingForFailureEnd = 'WAITING_FOR_FAILURE_END';
    case NotRequiredTotalFailure = 'NOT_REQUIRED_TOTAL_FAILURE';
    case SubmittedPendingResult = 'SUBMITTED_PENDING_RESULT';
    case Fulfilled = 'FULFILLED';
    case TransmissionUncertain = 'TRANSMISSION_UNCERTAIN';
    case RejectedRemediationRequired = 'REJECTED_REMEDIATION_REQUIRED';
    case TransportModeMismatch = 'TRANSPORT_MODE_MISMATCH';
    case EvidenceUnavailable = 'EVIDENCE_UNAVAILABLE';
    case AmbiguousEventHistory = 'AMBIGUOUS_EVENT_HISTORY';
    case SubmissionIntegrityError = 'SUBMISSION_INTEGRITY_ERROR';
}
