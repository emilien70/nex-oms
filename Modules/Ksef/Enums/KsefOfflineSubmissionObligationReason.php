<?php

namespace Modules\Ksef\Enums;

enum KsefOfflineSubmissionObligationReason: string
{
    case Offline24Base = 'OFFLINE24_BASE';
    case OrdinaryFailureExtension = 'ORDINARY_FAILURE_EXTENSION';
    case SubsequentFailureReset = 'SUBSEQUENT_FAILURE_RESET';
    case TotalFailureNoSubmission = 'TOTAL_FAILURE_NO_SUBMISSION';
    case SubmissionAlreadyAccepted = 'SUBMISSION_ALREADY_ACCEPTED';
    case SubmissionPendingResult = 'SUBMISSION_PENDING_RESULT';
    case TransmissionUncertain = 'TRANSMISSION_UNCERTAIN';
    case SubmissionRejected = 'SUBMISSION_REJECTED';
    case TransportModeMismatch = 'TRANSPORT_MODE_MISMATCH';
    case SubmissionIntegrityFailure = 'SUBMISSION_INTEGRITY_FAILURE';
    case LatarniaEvidenceInsufficient = 'LATARNIA_EVIDENCE_INSUFFICIENT';
    case LatarniaUnsupportedEnvironment = 'LATARNIA_UNSUPPORTED_ENVIRONMENT';
    case AmbiguousLatarniaHistory = 'AMBIGUOUS_LATARNIA_HISTORY';
    case UnsupportedProcedure = 'UNSUPPORTED_PROCEDURE';
}
