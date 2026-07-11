<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceController extends Controller
{
    private const IST_ZONE = 'Asia/Kolkata';

    private function istNow(): Carbon   { return Carbon::now(self::IST_ZONE); }
    private function istToday(): string { return $this->istNow()->toDateString(); }

    private function maxSessions(): int
    {
        return AttendanceSetting::get()->max_sessions_per_day;
    }

    // ── Employee self-service ────────────────────────────────────────────────

    public function index(Request $request)
    {
        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();
        if (! $employee) return response()->json([]);

        $logs = Attendance::where('employee_id', $employee->id)
            ->orderBy('attendance_date', 'desc')
            ->take(30)
            ->get();

        $today      = $this->istToday();
        $maxSessions = $this->maxSessions();

        return response()->json($logs->map(fn ($log) => $this->formatLog($log, $today, $maxSessions)));
    }

    public function clockIn(Request $request)
    {
        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();

        if (! $employee) {
            return response()->json(['message' => 'No employee profile linked to your account. Contact HR.'], 422);
        }

        $today       = $this->istToday();
        $now         = $this->istNow()->format('H:i:s');
        $maxSessions = $this->maxSessions();

        $log = Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $today)
            ->first();

        if ($log) {
            $sessions    = $log->sessions ?? [];
            $lastSession = end($sessions) ?: null;

            if ($lastSession && $lastSession['out'] === null) {
                return response()->json(['message' => 'You are already clocked in. Please clock out first.'], 400);
            }
            if (count($sessions) >= $maxSessions) {
                return response()->json(['message' => "Daily limit of {$maxSessions} sessions reached."], 400);
            }

            $sessions[] = ['in' => $now, 'out' => null, 'duration_minutes' => null];
            $log->update([
                'sessions'  => $sessions,
                'check_in'  => $sessions[0]['in'],
                'check_out' => null,
            ]);
        } else {
            $sessions = [['in' => $now, 'out' => null, 'duration_minutes' => null]];

            try {
                $log = Attendance::create([
                    'employee_id'     => $employee->id,
                    'attendance_date' => $today,
                    'check_in'        => $now,
                    'capture_method'  => 'Web Check-in',
                    'location_gps'    => $request->location_gps,
                    'status'          => 'Present',
                    'duration_minutes'=> 0,
                    'sessions'        => $sessions,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                return response()->json(['message' => 'Already checked in today.'], 400);
            }
        }

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'clock_in',
            'subject_type' => 'Attendance',
            'subject_id'   => $log->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Clocked in successfully.', 'id' => $log->id], 201);
    }

    public function clockOut(Request $request)
    {
        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();

        if (! $employee) {
            return response()->json(['message' => 'No employee profile linked to your account.'], 422);
        }

        $logs = Attendance::where('employee_id', $employee->id)
            ->orderBy('attendance_date', 'desc')
            ->take(3)
            ->get();

        $log = null;
        foreach ($logs as $l) {
            $sessions = $l->sessions ?? [];
            $last     = end($sessions);
            if ($last && $last['out'] === null) { $log = $l; break; }
        }

        if (! $log) {
            return response()->json(['message' => 'You are not clocked in.'], 422);
        }

        $sessions    = $log->sessions ?? [];
        $lastIdx     = count($sessions) - 1;
        $nowIst      = $this->istNow();
        $nowTime     = $nowIst->format('H:i:s');

        $sessionDate  = $log->attendance_date->toDateString();
        $checkInAt    = Carbon::parse($sessionDate . ' ' . $sessions[$lastIdx]['in'], self::IST_ZONE);
        $durationMin  = (int) $checkInAt->diffInMinutes($nowIst);

        $sessions[$lastIdx]['out']              = $nowTime;
        $sessions[$lastIdx]['duration_minutes'] = $durationMin;

        $totalMinutes = array_sum(array_column(
            array_filter($sessions, fn($s) => $s['duration_minutes'] !== null),
            'duration_minutes'
        ));

        $log->update([
            'sessions'         => $sessions,
            'check_in'         => $sessions[0]['in'],
            'check_out'        => $nowTime,
            'duration_minutes' => $totalMinutes,
        ]);

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'clock_out',
            'subject_type' => 'Attendance',
            'subject_id'   => $log->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Clocked out successfully.', 'duration_minutes' => $durationMin]);
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    public function getSettings(Request $request)
    {
        $this->requireHrAdmin($request);
        return response()->json(AttendanceSetting::get());
    }

    public function updateSettings(Request $request)
    {
        $this->requireHrAdmin($request);

        $data = $request->validate([
            'max_sessions_per_day'   => 'required|integer|min:1|max:20',
            'work_start_time'        => 'required|date_format:H:i',
            'work_end_time'          => 'required|date_format:H:i',
            'lunch_start'            => 'required|date_format:H:i',
            'lunch_end'              => 'required|date_format:H:i',
            'standard_hours_minutes' => 'required|integer|min:60|max:720',
        ]);

        $settings = AttendanceSetting::get();
        $settings->update([
            'max_sessions_per_day'   => $data['max_sessions_per_day'],
            'work_start_time'        => $data['work_start_time'] . ':00',
            'work_end_time'          => $data['work_end_time'] . ':00',
            'lunch_start'            => $data['lunch_start'] . ':00',
            'lunch_end'              => $data['lunch_end'] . ':00',
            'standard_hours_minutes' => $data['standard_hours_minutes'],
        ]);

        return response()->json($settings->fresh());
    }

    // ── Admin CRUD ───────────────────────────────────────────────────────────

    public function adminIndex(Request $request)
    {
        $this->requireHrAdmin($request);

        $query = Attendance::with('employee.user')
            ->orderBy('attendance_date', 'desc');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->employee_id);
        }
        if ($request->filled('month') && $request->filled('year')) {
            $query->whereMonth('attendance_date', $request->month)
                  ->whereYear('attendance_date',  $request->year);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $records = $query->take(500)->get();

        return response()->json($records->map(fn ($r) => [
            'id'               => $r->id,
            'employee_id'      => $r->employee_id,
            'employee_name'    => $r->employee?->user?->name ?? $r->employee?->full_name ?? '—',
            'employee_code'    => $r->employee?->employee_code ?? '—',
            'attendance_date'  => $r->attendance_date->toDateString(),
            'check_in'         => $r->check_in,
            'check_out'        => $r->check_out,
            'status'           => $r->status,
            'duration_minutes' => $r->duration_minutes,
            'sessions'         => $r->sessions ?? [],
            'session_count'    => count($r->sessions ?? []),
            'capture_method'   => $r->capture_method,
            'regularized'      => $r->regularized,
        ]));
    }

    public function adminStore(Request $request)
    {
        $this->requireHrAdmin($request);

        $data = $request->validate([
            'employee_id'     => 'required|exists:employees,id',
            'attendance_date' => 'required|date',
            'check_in'        => 'nullable|date_format:H:i',
            'check_out'       => 'nullable|date_format:H:i',
            'status'          => 'required|in:Present,Absent,Half Day,On Leave,LOP,Weekend,Holiday',
            'duration_minutes'=> 'nullable|integer|min:0',
            'sessions'        => 'nullable|array',
            'capture_method'  => 'nullable|string',
            'regularization_reason' => 'nullable|string',
        ]);

        // Build sessions from check_in/check_out if not provided
        if (empty($data['sessions']) && !empty($data['check_in'])) {
            $cin = $data['check_in'] . ':00';
            $cout = !empty($data['check_out']) ? $data['check_out'] . ':00' : null;
            $dur = null;
            if ($cin && $cout) {
                $dur = (int) Carbon::parse('1970-01-01 ' . $cin)->diffInMinutes(Carbon::parse('1970-01-01 ' . $cout));
            }
            $data['sessions'] = [['in' => $cin, 'out' => $cout, 'duration_minutes' => $dur]];
            if ($dur !== null) $data['duration_minutes'] = $dur;
        }

        if (!empty($data['check_in'])) $data['check_in'] .= ':00';
        if (!empty($data['check_out'])) $data['check_out'] .= ':00';

        $record = Attendance::create(array_merge($data, [
            'capture_method' => $data['capture_method'] ?? 'Admin Entry',
            'regularized'    => true,
            'regularization_reason' => $data['regularization_reason'] ?? 'Admin entry',
        ]));

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'admin_attendance_create',
            'subject_type' => 'Attendance',
            'subject_id'   => $record->id,
            'ip_address'   => $request->ip(),
        ]);

        return response()->json($record, 201);
    }

    public function adminUpdate(Request $request, $id)
    {
        $this->requireHrAdmin($request);

        $record = Attendance::findOrFail($id);

        $data = $request->validate([
            'attendance_date' => 'sometimes|date',
            'check_in'        => 'nullable|date_format:H:i',
            'check_out'       => 'nullable|date_format:H:i',
            'status'          => 'sometimes|in:Present,Absent,Half Day,On Leave,LOP,Weekend,Holiday',
            'duration_minutes'=> 'nullable|integer|min:0',
            'sessions'        => 'nullable|array',
            'regularization_reason' => 'nullable|string',
        ]);

        if (isset($data['check_in'])) $data['check_in'] .= ':00';
        if (isset($data['check_out'])) $data['check_out'] .= ':00';

        // Recompute sessions if check_in/check_out changed and sessions not explicitly provided
        if ((isset($data['check_in']) || isset($data['check_out'])) && !isset($data['sessions'])) {
            $cin  = $data['check_in']  ?? $record->check_in;
            $cout = $data['check_out'] ?? $record->check_out;
            $dur  = null;
            if ($cin && $cout) {
                $dur = (int) Carbon::parse('1970-01-01 ' . $cin)->diffInMinutes(Carbon::parse('1970-01-01 ' . $cout));
            }
            $data['sessions'] = [['in' => $cin, 'out' => $cout, 'duration_minutes' => $dur]];
            if ($dur !== null) $data['duration_minutes'] = $dur;
        }

        $record->update(array_merge($data, ['regularized' => true]));

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'admin_attendance_update',
            'subject_type' => 'Attendance',
            'subject_id'   => $record->id,
            'ip_address'   => $request->ip(),
        ]);

        return response()->json($record->fresh());
    }

    public function adminDestroy(Request $request, $id)
    {
        $this->requireHrAdmin($request);

        $record = Attendance::findOrFail($id);
        $record->delete();

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'admin_attendance_delete',
            'subject_type' => 'Attendance',
            'subject_id'   => $id,
            'ip_address'   => $request->ip(),
        ]);

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Reset today's session count for a specific employee so they can clock in again.
     * HR/admin only. Clears the sessions array on today's record (keeps the record itself).
     */
    public function resetToday(Request $request, $employeeId)
    {
        $this->requireHrAdmin($request);

        $employee = Employee::findOrFail($employeeId);
        $today    = $this->istToday();

        $log = Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $today)
            ->first();

        if ($log) {
            $log->update([
                'sessions'         => [],
                'check_in'         => null,
                'check_out'        => null,
                'duration_minutes' => 0,
                'status'           => 'Present',
            ]);
        }
        // If no record exists today, nothing to reset — that's fine.

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'admin_reset_attendance_today',
            'subject_type' => 'Employee',
            'subject_id'   => $employee->id,
            'ip_address'   => $request->ip(),
        ]);

        return response()->json(['message' => "Today's sessions reset for {$employee->full_name}."]);
    }

    // ── Report ───────────────────────────────────────────────────────────────

    public function report(Request $request)
    {
        $this->requireHrAdmin($request);

        $month = (int) ($request->month ?? now()->month);
        $year  = (int) ($request->year  ?? now()->year);

        $settings    = AttendanceSetting::get();
        $workStart   = $settings->work_start_time;   // "09:30:00"
        $workEnd     = $settings->work_end_time;     // "18:00:00"
        $lunchStart  = $settings->lunch_start;       // "13:00:00"
        $lunchEnd    = $settings->lunch_end;         // "14:30:00"
        $stdMinutes  = $settings->standard_hours_minutes;

        // Calendar: all days in month
        $start        = Carbon::create($year, $month, 1, 0, 0, 0, self::IST_ZONE);
        $end          = $start->copy()->endOfMonth();
        $allDays      = [];
        $workingDays  = 0;

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $isWeekend   = in_array($d->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY]);
            $allDays[]   = [
                'date'     => $d->toDateString(),
                'day'      => $d->format('D'),
                'weekend'  => $isWeekend,
            ];
            if (!$isWeekend) $workingDays++;
        }

        // Pull all attendance records for this month
        $records = Attendance::with('employee.user')
            ->whereMonth('attendance_date', $month)
            ->whereYear('attendance_date',  $year)
            ->when($request->filled('employee_id'), fn($q) => $q->where('employee_id', (int) $request->employee_id))
            ->get()
            ->groupBy('employee_id');

        // Get all employees (or just the requested one)
        $empQuery = Employee::with('user');
        if ($request->filled('employee_id')) {
            $empQuery->where('id', (int) $request->employee_id);
        }
        $employees = $empQuery->get();

        $employeeReports = $employees->map(function ($emp) use ($records, $allDays, $workingDays, $workStart, $workEnd, $lunchStart, $lunchEnd, $stdMinutes) {
            $empRecords = $records->get($emp->id, collect());
            $byDate     = $empRecords->keyBy(fn($r) => $r->attendance_date->toDateString());

            $presentDays     = 0;
            $lateDays        = 0;
            $overtimeDays    = 0;
            $noLunchDays     = 0;
            $outOfHoursDays  = 0;
            $totalMinutes    = 0;
            $dailyRows       = [];

            foreach ($allDays as $day) {
                $rec = $byDate->get($day['date']);

                if ($day['weekend']) {
                    $dailyRows[] = array_merge($day, ['status' => 'Weekend', 'check_in' => null, 'check_out' => null, 'duration_minutes' => 0, 'sessions_count' => 0, 'late' => false, 'overtime' => false, 'no_lunch' => false, 'out_of_hours' => false]);
                    continue;
                }

                if (!$rec) {
                    $dailyRows[] = array_merge($day, ['status' => 'Absent', 'check_in' => null, 'check_out' => null, 'duration_minutes' => 0, 'sessions_count' => 0, 'late' => false, 'overtime' => false, 'no_lunch' => false, 'out_of_hours' => false]);
                    continue;
                }

                $sessions  = $rec->sessions ?? [];
                $checkIn   = $rec->check_in;
                $checkOut  = $rec->check_out;
                $durMin    = $rec->duration_minutes ?? 0;
                $status    = $rec->status;

                if ($status === 'Present') $presentDays++;
                $totalMinutes += $durMin;

                $late        = $checkIn  && $checkIn  > $workStart;
                $overtime    = $checkOut && $checkOut > $workEnd;
                $noLunch     = $status === 'Present' && !$this->hasLunchBreak($sessions, $lunchStart, $lunchEnd);
                $outOfHours  = $overtime || ($checkIn && $checkIn < '06:00:00');

                if ($late)       $lateDays++;
                if ($overtime)   $overtimeDays++;
                if ($noLunch)    $noLunchDays++;
                if ($outOfHours) $outOfHoursDays++;

                $dailyRows[] = [
                    'date'          => $day['date'],
                    'day'           => $day['day'],
                    'weekend'       => false,
                    'status'        => $status,
                    'check_in'      => $checkIn,
                    'check_out'     => $checkOut,
                    'duration_minutes' => $durMin,
                    'sessions_count'=> count($sessions),
                    'late'          => $late,
                    'overtime'      => $overtime,
                    'no_lunch'      => $noLunch,
                    'out_of_hours'  => $outOfHours,
                ];
            }

            return [
                'employee_id'     => $emp->id,
                'employee_code'   => $emp->employee_code ?? '—',
                'name'            => $emp->user?->name ?? $emp->full_name ?? '—',
                'working_days'    => $workingDays,
                'present_days'    => $presentDays,
                'absent_days'     => max(0, $workingDays - $presentDays),
                'late_days'       => $lateDays,
                'overtime_days'   => $overtimeDays,
                'no_lunch_days'   => $noLunchDays,
                'out_of_hours_days' => $outOfHoursDays,
                'total_hours'     => round($totalMinutes / 60, 1),
                'daily_rows'      => $dailyRows,
            ];
        });

        return response()->json([
            'month'        => $month,
            'year'         => $year,
            'month_label'  => Carbon::create($year, $month, 1)->format('F Y'),
            'working_days' => $workingDays,
            'settings'     => [
                'work_start_time' => $workStart,
                'work_end_time'   => $workEnd,
                'lunch_start'     => $lunchStart,
                'lunch_end'       => $lunchEnd,
                'standard_hours'  => round($stdMinutes / 60, 1),
            ],
            'employees' => $employeeReports->values(),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formatLog(Attendance $log, string $today, int $maxSessions): array
    {
        $sessions    = $log->sessions ?? [];
        $lastSession = end($sessions) ?: null;
        $isToday     = $log->attendance_date->toDateString() === $today;

        return [
            'id'               => $log->id,
            'attendance_date'  => $log->attendance_date->toDateString(),
            'status'           => $log->status,
            'duration_minutes' => $log->duration_minutes,
            'sessions'         => $sessions,
            'session_count'    => count($sessions),
            'has_open_session' => $isToday && $lastSession && isset($lastSession['out']) && $lastSession['out'] === null,
            'can_clock_in'     => $isToday && (!$lastSession || $lastSession['out'] !== null) && count($sessions) < $maxSessions,
            'can_clock_out'    => $isToday && $lastSession && $lastSession['out'] === null,
            'is_today'         => $isToday,
        ];
    }

    /**
     * Return true if the sessions array contains a clock-out within the lunch window,
     * or if the employee was not working during the lunch window at all.
     * Return false (= flag) if they worked straight through without clocking out.
     */
    private function hasLunchBreak(array $sessions, string $lunchStart, string $lunchEnd): bool
    {
        // Was the employee even working during the lunch window?
        $workingDuringLunch = false;
        foreach ($sessions as $s) {
            $in  = $s['in'];
            $out = $s['out'] ?? '23:59:59';
            if ($in < $lunchEnd && $out > $lunchStart) {
                $workingDuringLunch = true;
                break;
            }
        }

        if (!$workingDuringLunch) return true; // not working at all → no issue

        // Was there any clock-out during the lunch window?
        foreach ($sessions as $s) {
            $out = $s['out'] ?? null;
            if ($out && $out >= $lunchStart && $out <= $lunchEnd) {
                return true; // stepped out during lunch
            }
        }

        return false; // working through lunch, no break detected
    }

    private function requireHrAdmin(Request $request): void
    {
        $role = $request->user()->role;
        if (!in_array($role, ['super_admin', 'partner', 'hr', 'finance'])) {
            abort(403, 'Access denied.');
        }
    }
}
