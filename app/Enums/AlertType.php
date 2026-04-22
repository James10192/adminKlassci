<?php

namespace App\Enums;

enum AlertType: string
{
    case QuotaExceeded = 'quota_exceeded';
    case QuotaCritical = 'quota_critical';
    case SubscriptionExpired = 'subscription_expired';
    case SubscriptionExpiring = 'subscription_expiring';
    case HighAttrition = 'high_attrition';
    case ActiveReliquats = 'active_reliquats';
    case PlanMismatch = 'plan_mismatch';
    case StaleTenant = 'stale_tenant';
    case SslExpiring = 'ssl_expiring';
    case EnrollmentDecline = 'enrollment_decline';
    case UnpaidInvoices = 'unpaid_invoices';
}
