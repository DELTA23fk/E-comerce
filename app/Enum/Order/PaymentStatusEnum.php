<?php

namespace App\Enum\Order;

enum PaymentStatusEnum:string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case REFUNDED = 'refunded';
    case CANCELLED = 'cancelled';
    case IN_PROCESS = 'in_process';
    case IN_MEDIATION = 'in_mediation';
    case CHARGED_BACK = 'charged_back';
}
