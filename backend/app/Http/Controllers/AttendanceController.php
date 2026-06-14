<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceController extends Controller
{
    private const MAX_SESSIONS  = 6;
    private const IST_ZONE      = 'Asia/Kolkata';

    private function istNow(): Carbon  { return Carbon::now(self::IST_ZONE); }
    private function istToday(): string { return $this->istNow()->toDateString(); }

    public function index(Request $request)
    {
        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();
        if (! $employee) return response()->json([]);

        $logs = Attendance::where('employee_id', $employee->id)
            ->orderBy('attendance_date', 'desc')
            ->take(30)
            ->get();

        $today = $this->istToday();

        return response()->json($logs->map(function ($log) use ($today) {
            $sessions = $log->sessions ?? [];
            $lastSession = end($sessions) ?: null;
            $isToday = $log->attendance_date->toDateString() === $today;

            return [
                'id'               => $log->id,
                'attendance_date'  => $log->attendance_date->toDateString(),
                'status'           => $log->status,
                'duration_minutes' => $log->duration_minutes,
                'sessions'         => $sessions,
                'session_count'    => count($sessions),
                'has_open_session' => $isToday && $lastSession && isset($lastSession['out']) && $lastSession['out'] === null,
                'can_clock_in'     => $isToday && (!$lastSession || $lastSession['out'] !== null) && count($sessions) < self::MAX_SESSIONS,
                'can_clock_out'    => $isToday && $lastSession && $lastSession['out'] === null,
                'is_today'         => $isToday,
            ];
        }));
    }

    public function clockIn(Request $request)
    {
        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();

        if (! $employee) {
            return response()->json(['message' => 'No employee profile linked to your account. Contact HR.'], 422);
        }

        $today = $this->istToday();
        $now   = $this->istNow()->format('H:i:s');

        // Find or create today's attendance record
        $log = Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $today)
            ->first();

        if ($log) {
            $sessions    = $log->sessions ?? [];
            $lastSession = end($sessions) ?: null;

            if ($lastSession && $lastSession['out'] === null) {
                return response()->json(['message' => 'You are already clocked in. Please clock out first.'], 400);
            }

            if (count($sessions) >= self::MAX_SESSIONS) {
                return response()->json(['message' => 'Daily limit of ' . self::MAX_SESSIONS . ' sessions reached.'], 400);
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

        // Find the attendance record with an open session (last session has no out)
        $logs = Attendance::where('employee_id', $employee->id)
            ->orderBy('attendance_date', 'desc')
            ->take(3)
            ->get();

        $log = null;
        foreach ($logs as $l) {
            $sessions = $l->sessions ?? [];
            $last = end($sessions);
            if ($last && $last['out'] === null) {
                $log = $l;
                break;
            }
        }

        if (! $log) {
            return response()->json(['message' => 'You are not clocked in.'], 422);
        }

        $sessions    = $log->sessions ?? [];
        $lastIdx     = count($sessions) - 1;
        $nowIst      = $this->istNow();
        $nowTime     = $nowIst->format('H:i:s');

        // Calculate duration for this session
        $sessionDate = $log->attendance_date->toDateString();
        $checkInAt   = Carbon::parse($sessionDate . ' ' . $sessions[$lastIdx]['in'], self::IST_ZONE);
        $durationMin = (int) $checkInAt->diffInMinutes($nowIst);

        $sessions[$lastIdx]['out']              = $nowTime;
        $sessions[$lastIdx]['duration_minutes'] = $durationMin;

        // Total duration = sum of all completed sessions
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
}
