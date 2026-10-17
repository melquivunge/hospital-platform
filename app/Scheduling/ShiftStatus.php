<?php

namespace App\Scheduling;

/**
 * draft → published; draft or published → cancelled. Same list as shifts_status_check.
 */
enum ShiftStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Cancelled = 'cancelled';
}
