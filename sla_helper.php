<?php
/** 
 * sla_helper.php    
 * ─────────────────────────────────────────────────────────────────────────────
 * UniKL Helpdesk — SLA calculation helper
 *
 * Rules
 *   • Working days  : Monday – Friday
 *   • Working hours : 08:00 – 17:00 (Asia/Kuala_Lumpur)
 *   • SLA window    : 8 working hours per ticket (all priorities share one SLA
 *                     window; extend this file if you ever need per-priority
 *                     windows)
 *   • When a closed ticket is RE-OPENED, sla_start_at is reset to NOW so the
 *     assignee gets a fresh 8-hour window.
 *
 * Public API
 *   addWorkingHours(DateTime $start, int $hours) : DateTime   — SLA deadline
 *   getSlaStatus(string $slaStartAt, ?string $resolvedAt)     — status array
 *   workingMinutesBetween(DateTime $from, DateTime $to) : int — elapsed mins
 * ─────────────────────────────────────────────────────────────────────────────
 */

define('SLA_TZ',            'Asia/Kuala_Lumpur');
define('SLA_WORK_START',    8);   // 08:00
define('SLA_WORK_END',      17);  // 17:00  (exclusive — work stops at 17:00)
define('SLA_WORK_HOURS',    8);   // total SLA window in working hours
define('SLA_WORK_DAY_MINS', (SLA_WORK_END - SLA_WORK_START) * 60);  // 540 min

// ─── helpers ─────────────────────────────────────────────────────────────────

/**
 * Is $dt on a working day (Mon–Fri)?
 */
function sla_isWorkingDay(DateTime $dt): bool
{
    $dow = (int)$dt->format('N'); // 1=Mon … 7=Sun
    return $dow >= 1 && $dow <= 5;
}

/**
 * Advance $dt to the next working-day start (08:00 Mon–Fri).
 * If $dt is already within working hours it is returned unchanged.
 */
function sla_snapToWorkStart(DateTime $dt): DateTime
{
    $d = clone $dt;

    // If it's a weekend, roll to Monday 08:00
    while (!sla_isWorkingDay($d)) {
        $d->modify('+1 day');
        $d->setTime(SLA_WORK_START, 0, 0);
    }

    $h = (int)$d->format('H');
    $m = (int)$d->format('i');
    $s = (int)$d->format('s');

    // Before 08:00 on a working day → snap to 08:00
    if ($h < SLA_WORK_START) {
        $d->setTime(SLA_WORK_START, 0, 0);
        return $d;
    }

    // After 17:00 on a working day → next working day 08:00
    if ($h >= SLA_WORK_END) {
        $d->modify('+1 day');
        $d->setTime(SLA_WORK_START, 0, 0);
        // recurse in case that +1 day lands on a weekend
        return sla_snapToWorkStart($d);
    }

    return $d; // already inside working hours
}

// ─── main API ────────────────────────────────────────────────────────────────

/**
 * Add $hours working hours to $start and return the SLA deadline.
 *
 * Algorithm
 *   1. Snap start to next valid work moment.
 *   2. Calculate remaining minutes in today's work block.
 *   3. If remaining ≥ needed → deadline is today.
 *   4. Otherwise consume today's block, jump to next day 08:00, repeat.
 *
 * @param DateTime $start   The sla_start_at moment (will be cloned).
 * @param int      $hours   Working hours to add (default SLA_WORK_HOURS).
 * @return DateTime          The deadline in Asia/Kuala_Lumpur timezone.
 */
function addWorkingHours(DateTime $start, int $hours = SLA_WORK_HOURS): DateTime
{
    $d = clone $start;
    $d->setTimezone(new DateTimeZone(SLA_TZ));

    // Snap to first valid work moment
    $d = sla_snapToWorkStart($d);

    $minutesLeft = $hours * 60;

    while ($minutesLeft > 0) {
        // Minutes remaining in today's work block from current position
        $endOfDay = clone $d;
        $endOfDay->setTime(SLA_WORK_END, 0, 0);

        $blockMins = (int)(($endOfDay->getTimestamp() - $d->getTimestamp()) / 60);

        if ($minutesLeft <= $blockMins) {
            // Deadline falls within this working day
            $d->modify("+{$minutesLeft} minutes");
            $minutesLeft = 0;
        } else {
            // Consume this full block and roll to next day
            $minutesLeft -= $blockMins;
            $d->modify('+1 day');
            $d->setTime(SLA_WORK_START, 0, 0);
            $d = sla_snapToWorkStart($d); // skip weekends
        }
    }

    return $d;
}

/**
 * Count working minutes elapsed between two DateTime objects.
 * Used to show "time spent" on the ticket.
 *
 * @param DateTime $from
 * @param DateTime $to
 * @return int  Minutes of working time elapsed.
 */
function workingMinutesBetween(DateTime $from, DateTime $to): int
{
    $a = clone $from;
    $b = clone $to;
    $a->setTimezone(new DateTimeZone(SLA_TZ));
    $b->setTimezone(new DateTimeZone(SLA_TZ));

    if ($a >= $b) return 0;

    $a = sla_snapToWorkStart($a);
    if ($a >= $b) return 0;

    $total = 0;

    while (true) {
        if (!sla_isWorkingDay($a)) {
            $a->modify('+1 day');
            $a->setTime(SLA_WORK_START, 0, 0);
            continue;
        }

        $endOfDay = clone $a;
        $endOfDay->setTime(SLA_WORK_END, 0, 0);

        $blockEnd = $b < $endOfDay ? $b : $endOfDay;

        if ($a < $blockEnd) {
            $total += (int)(($blockEnd->getTimestamp() - $a->getTimestamp()) / 60);
        }

        if ($b <= $endOfDay) break;

        $a->modify('+1 day');
        $a->setTime(SLA_WORK_START, 0, 0);
    }

    return $total;
}

/**
 * Return a rich SLA status array for display.
 *
 * @param string      $slaStartAt  Value of complaints.sla_start_at  (MySQL datetime string)
 * @param string|null $resolvedAt  Value of complaints.resolved_at   (null if not closed)
 * @param string      $ticketStatus  Current ticket status
 * @return array {
 *   deadline      : DateTime,
 *   deadline_str  : string   "d M Y, H:i",
 *   breached      : bool,
 *   closed_in_sla : bool|null   (null when ticket is not closed),
 *   remaining_mins: int         (negative = overdue),
 *   remaining_str : string      human-readable,
 *   percent_used  : float       0–100+ (>100 = breached),
 *   status_label  : string      "On Track" | "At Risk" | "Breached" | "Closed – Met" | "Closed – Breached",
 *   status_color  : string      hex,
 *   status_bg     : string      hex,
 *   elapsed_mins  : int,
 *   total_mins    : int         SLA_WORK_HOURS * 60,
 * }
 */
function getSlaStatus(string $slaStartAt, ?string $resolvedAt, string $ticketStatus = 'open', ?string $firstResponseAt = null): array
{
    $tz      = new DateTimeZone(SLA_TZ);
    $start   = new DateTime($slaStartAt,  $tz);
    $now     = new DateTime('now',        $tz);
    $isClosed = strtolower($ticketStatus) === 'closed';

    $deadline   = addWorkingHours($start, SLA_WORK_HOURS);
    $totalMins  = SLA_WORK_HOURS * 60;

    // Reference point: use resolved_at for closed tickets, now otherwise
$refPoint = $now;
if ($firstResponseAt) {
    // Clock stops the moment staff changes to in_progress OR closed
    $refPoint = new DateTime($firstResponseAt, $tz);
} elseif ($isClosed && $resolvedAt) {
    // Fallback if first_response_at missing
    $refPoint = new DateTime($resolvedAt, $tz);
}
// If neither: ticket still open with zero staff action → clock runs → breach possible

    $elapsedMins   = workingMinutesBetween($start, $refPoint);
    $remainingMins = $totalMins - $elapsedMins;
    $percentUsed   = $totalMins > 0 ? round(($elapsedMins / $totalMins) * 100, 1) : 0;
    $breached      = ($refPoint > $deadline);

    // ── Closed ticket ───────────────────────────────────────────────────────
    if ($isClosed) {
        $closedInSla = !$breached;
        if ($closedInSla) {
            $label = 'Closed – Met SLA';
            $color = '#059669'; $bg = '#D1FAE5';
        } else {
            $label = 'Closed – Breached';
            $color = '#DC2626'; $bg = '#FEE2E2';
        }
        return [
            'deadline'       => $deadline,
            'deadline_str'   => $deadline->format('d M Y, H:i'),
            'breached'       => $breached,
            'closed_in_sla'  => $closedInSla,
            'remaining_mins' => $remainingMins,
            'remaining_str'  => _slaRemainingStr($remainingMins, true),
            'percent_used'   => min($percentUsed, 100),
            'status_label'   => $label,
            'status_color'   => $color,
            'status_bg'      => $bg,
            'elapsed_mins'   => $elapsedMins,
            'total_mins'     => $totalMins,
        ];
    }

    // ── Open / in-progress ticket ───────────────────────────────────────────
    if ($breached) {
        $label = 'SLA Breached'; $color = '#DC2626'; $bg = '#FEE2E2';
    } elseif ($remainingMins <= 60) {
        $label = 'At Risk';      $color = '#D97706'; $bg = '#FEF3C7';
    } else {
        $label = 'On Track';     $color = '#059669'; $bg = '#D1FAE5';
    }

    return [
        'deadline'       => $deadline,
        'deadline_str'   => $deadline->format('d M Y, H:i'),
        'breached'       => $breached,
        'closed_in_sla'  => null,
        'remaining_mins' => $remainingMins,
        'remaining_str'  => _slaRemainingStr($remainingMins, false),
        'percent_used'   => $percentUsed,
        'status_label'   => $label,
        'status_color'   => $color,
        'status_bg'      => $bg,
        'elapsed_mins'   => $elapsedMins,
        'total_mins'     => $totalMins,
    ];
}

/**
 * Internal: convert remaining minutes to a human-readable string.
 */
function _slaRemainingStr(int $mins, bool $isClosed): string
{
    if ($isClosed) {
        if ($mins >= 0) {
            $h = intdiv($mins, 60); $m = $mins % 60;
            return $h > 0 ? "{$h}h {$m}m remaining at close" : "{$m}m remaining at close";
        } else {
            $over = abs($mins);
            $h = intdiv($over, 60); $m = $over % 60;
            return $h > 0 ? "{$h}h {$m}m overdue at close" : "{$m}m overdue at close";
        }
    }

    if ($mins < 0) {
        $over = abs($mins);
        $h = intdiv($over, 60); $m = $over % 60;
        return $h > 0 ? "{$h}h {$m}m overdue" : "{$m}m overdue";
    }
    $h = intdiv($mins, 60); $m = $mins % 60;
    return $h > 0 ? "{$h}h {$m}m left" : "{$m}m left";
}