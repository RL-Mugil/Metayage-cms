<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();
        if (! $employee) return response()->json([]);

        $logs = Attendance::where('employee_id', $employee->id)
            ->orderBy('attendance_date', 'desc')
            ->take(30)
            ->get();

        return response()->json($logs);
    }

    public function clockIn(Request $request)
    {
        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();

        if (! $employee) {
            return response()->json(['message' => 'No employee profile linked to your account. Contact HR.'], 422);
        }

        $today    = Carbon::today()->toDateString();
        $existing = Attendance::where('employee_id', $employee->id)->whereDate('attendance_date', $today)->first();

        if ($existing) {
            return response()->json(['message' => 'Already checked in today'], 400);
        }

        $log = Attendance::create([
            'employee_id'     => $employee->id,
            'attendance_date' => $today,
            'check_in'        => Carbon::now()->toTimeString(),
            'capture_method'  => 'Web Check-in',
            'location_gps'    => $request->location_gps,
            'status'          => 'Present',
        ]);

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'clock_in',
            'subject_type' => 'Attendance',
            'subject_id'   => $log->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json($log, 201);
    }

    public function clockOut(Request $request)
    {
        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();

        if (! $employee) {
            return response()->json(['message' => 'No employee profile linked to your account.'], 422);
        }

        $today = Carbon::today()->toDateString();
        $log   = Attendance::where('employee_id', $employee->id)->whereDate('attendance_date', $today)->firstOrFail();

        $checkoutTime    = Carbon::now()->toTimeString();
        $durationMinutes = (int) abs(Carbon::parse($log->check_in)->diffInMinutes(Carbon::parse($checkoutTime)));

        $log->update(['check_out' => $checkoutTime, 'duration_minutes' => $durationMinutes]);

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'clock_out',
            'subject_type' => 'Attendance',
            'subject_id'   => $log->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json($log);
    }
}
