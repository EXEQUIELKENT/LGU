<?php
/**
 * priority.php — shared priority/SLA helpers for Case Management.
 *
 * priorityBadge() and effectivePriority() are copies of the functions that
 * already live independently (byte-identical) in pending_reports.php,
 * current_reports.php, and archive_reports.php. Centralized here so
 * case_management.php doesn't need a 4th copy. The three existing pages keep
 * their own local copies for now — swapping them to require this file instead
 * is optional future cleanup, not done here, since it's out of scope for this
 * change and those pages are meant to stay untouched.
 *
 * case_sla_target_days() / case_urgency_badge_html() are new: there is no
 * due-date/deadline column anywhere in the schema, so "urgency" is computed
 * from days-open vs. a priority-keyed target rather than a stored deadline.
 */

if (!function_exists('priorityBadge')) {
    function priorityBadge(?string $lvl): string {
        $styles = [
            'Critical' => ['bg' => '#fce7f3', 'fg' => '#831843', 'bd' => '#f472b6', 'dot' => '#db2777'],
            'High'     => ['bg' => '#fde8e8', 'fg' => '#9b1c1c', 'bd' => '#f87171', 'dot' => '#dc2626'],
            'Medium'   => ['bg' => '#fef3c7', 'fg' => '#92400e', 'bd' => '#fbbf24', 'dot' => '#d97706'],
            'Low'      => ['bg' => '#d1fae5', 'fg' => '#065f46', 'bd' => '#34d399', 'dot' => '#059669'],
        ];
        $lvl = $lvl ?? 'Low';
        $s   = $styles[$lvl] ?? ['bg' => '#e5e7eb', 'fg' => '#374151', 'bd' => '#9ca3af', 'dot' => '#6b7280'];
        return "<span style=\"display:inline-flex;align-items:center;gap:5px;background:{$s['bg']};color:{$s['fg']};"
             . "border:1px solid {$s['bd']};padding:3px 10px 3px 7px;border-radius:999px;font-size:10.5px;"
             . "font-weight:700;letter-spacing:.2px;box-shadow:0 1px 2px rgba(0,0,0,.05);white-space:nowrap;\">"
             . "<span style=\"width:6px;height:6px;border-radius:50%;background:{$s['dot']};display:inline-block;flex-shrink:0;\"></span>{$lvl}</span>";
    }
}

if (!function_exists('effectivePriority')) {
    function effectivePriority(array $row): string {
        return $row['ai_priority'] ?? $row['priority_lvl'] ?? 'Low';
    }
}

if (!function_exists('case_sla_target_days')) {
    // No real deadline data exists anywhere in the schema — these targets are
    // the "how many days is reasonable for this priority" yardstick the
    // urgency badge measures days-open against.
    function case_sla_target_days(string $priority): int {
        $targets = ['Critical' => 3, 'High' => 7, 'Medium' => 14, 'Low' => 30];
        return $targets[$priority] ?? 14;
    }
}

if (!function_exists('case_urgency_badge_html')) {
    /**
     * @param string      $priority   One of Critical/High/Medium/Low.
     * @param string|null $anchorDate The date the clock started (e.g. requests.created_at).
     * @param bool        $isClosed   True once the case has reached a terminal status.
     */
    function case_urgency_badge_html(string $priority, ?string $anchorDate, bool $isClosed): string {
        if ($isClosed) {
            return '<span style="display:inline-flex;align-items:center;gap:5px;background:#f1f5f9;color:#475569;'
                 . 'border:1px solid #cbd5e1;padding:3px 10px;border-radius:999px;font-size:10.5px;font-weight:700;'
                 . 'white-space:nowrap;">Closed</span>';
        }
        if (!$anchorDate) {
            return '<span style="display:inline-flex;align-items:center;gap:5px;background:#f1f5f9;color:#475569;'
                 . 'border:1px solid #cbd5e1;padding:3px 10px;border-radius:999px;font-size:10.5px;font-weight:700;'
                 . 'white-space:nowrap;">—</span>';
        }

        $target = case_sla_target_days($priority);
        try {
            $tz    = new DateTimeZone('Asia/Manila');
            $today = new DateTime('today', $tz);
            $start = new DateTime($anchorDate, $tz);
            $start->setTime(0, 0, 0);
            $daysOpen = (int)$today->diff($start)->format('%a');
        } catch (Exception $e) {
            $daysOpen = 0;
        }
        $ratio = $target > 0 ? $daysOpen / $target : 0;

        if ($ratio >= 1.0) {
            $overdueBy = max(0, $daysOpen - $target);
            $label = "Overdue ({$overdueBy}d)";
            $bg = '#fee2e2'; $fg = '#991b1b'; $bd = '#fca5a5'; $dot = '#dc2626';
        } elseif ($ratio >= 0.7) {
            $label = 'Due Soon';
            $bg = '#fef3c7'; $fg = '#92400e'; $bd = '#fbbf24'; $dot = '#d97706';
        } else {
            $label = 'On Track';
            $bg = '#d1fae5'; $fg = '#065f46'; $bd = '#34d399'; $dot = '#059669';
        }

        return "<span data-urgency-ratio=\"" . htmlspecialchars((string)round($ratio, 3)) . "\" "
             . "style=\"display:inline-flex;align-items:center;gap:5px;background:{$bg};color:{$fg};"
             . "border:1px solid {$bd};padding:3px 10px 3px 7px;border-radius:999px;font-size:10.5px;"
             . "font-weight:700;letter-spacing:.2px;box-shadow:0 1px 2px rgba(0,0,0,.05);white-space:nowrap;\">"
             . "<span style=\"width:6px;height:6px;border-radius:50%;background:{$dot};display:inline-block;flex-shrink:0;\"></span>{$label}</span>";
    }
}
