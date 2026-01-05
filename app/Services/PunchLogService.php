<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Employee;
use Carbon\Carbon;

/**
 * PunchLogService - Fetches punch logs from SQL Express database
 * 
 * Based on etimetracklite1 database structure:
 * - Table: DeviceLogs_{month}_{year} (e.g., DeviceLogs_1_2026)
 * - Columns: LogDate, UserId, DeviceId
 * - Joined with Employees table on UserId = EmployeeCodeInDevice
 */
class PunchLogService
{
    protected $connection = 'sqlexpress';

    public function __construct()
    {
        // Configuration is loaded from .env
    }

    /**
     * Test database connection
     */
    public function testConnection(): bool
    {
        try {
            DB::connection($this->connection)->getPdo();
            return true;
        } catch (\Exception $e) {
            Log::error('SQL Express connection failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the dynamic DeviceLogs table name based on current month/year
     */
    protected function getDeviceLogsTableName(Carbon $date = null): string
    {
        $date = $date ?? Carbon::now();
        $month = $date->month;
        $year = $date->year;
        return "DeviceLogs_{$month}_{$year}";
    }

    /**
     * Fetch punch logs from SQL Express
     * Queries the DeviceLogs_{month}_{year} table joined with Employees
     */
    public function fetchPunchLogs(Carbon $since = null, Carbon $until = null): array
    {
        try {
            $tableName = $this->getDeviceLogsTableName();

            // Check if table exists
            $tableExists = DB::connection($this->connection)
                ->select("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?", [$tableName]);

            if ($tableExists[0]->cnt == 0) {
                Log::warning("DeviceLogs table {$tableName} does not exist");
                return [];
            }

            // Build query - join DeviceLogs with Employees
            $query = DB::connection($this->connection)
                ->table($tableName . ' as L')
                ->join('Employees as E', 'L.UserId', '=', 'E.EmployeeCodeInDevice')
                ->select([
                    'L.LogDate as punch_time',
                    'L.DeviceId as device_id',
                    'E.EmployeeCode as employee_code',
                    'E.EmployeeId as legacy_id',
                    'E.EmployeeName as employee_name',
                ]);

            // Apply date filters
            if ($since) {
                $query->where('L.LogDate', '>=', $since);
            } else {
                // Default: last 7 days
                $query->where('L.LogDate', '>', Carbon::now()->subDays(7));
            }

            if ($until) {
                $query->where('L.LogDate', '<=', $until);
            }

            $results = $query->orderBy('L.LogDate', 'asc')->get()->toArray();

            Log::info("Fetched " . count($results) . " punch logs from SQL Express");
            return $results;

        } catch (\Exception $e) {
            Log::error('Failed to fetch punch logs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Sync punch logs to local attendance/leave tables
     */
    public function syncToAttendance(Carbon $since = null): array
    {
        $stats = [
            'fetched' => 0,
            'check_ins' => 0,
            'check_outs' => 0,
            'skipped' => 0,
            'errors' => 0,
            'employees_not_found' => 0,
        ];

        $punchLogs = $this->fetchPunchLogs($since);
        $stats['fetched'] = count($punchLogs);

        // Group punches by employee and date
        $punchGroups = [];
        foreach ($punchLogs as $punch) {
            $punchTime = Carbon::parse($punch->punch_time);
            $punchDate = $punchTime->format('Y-m-d');
            $key = $punch->employee_code . '_' . $punchDate;

            if (!isset($punchGroups[$key])) {
                $punchGroups[$key] = [
                    'employee_code' => $punch->employee_code,
                    'legacy_id' => $punch->legacy_id,
                    'employee_name' => $punch->employee_name,
                    'date' => $punchDate,
                    'punches' => [],
                ];
            }
            $punchGroups[$key]['punches'][] = $punchTime;
        }

        // Process each group (first punch = in, last punch = out)
        foreach ($punchGroups as $group) {
            try {
                $result = $this->processPunchGroup($group);
                $stats['check_ins'] += $result['check_ins'];
                $stats['check_outs'] += $result['check_outs'];
                $stats['skipped'] += $result['skipped'];
                if ($result['employee_not_found']) {
                    $stats['employees_not_found']++;
                }
            } catch (\Exception $e) {
                Log::error('Error processing punch group: ' . $e->getMessage(), $group);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Process a group of punches for one employee on one date
     */
    protected function processPunchGroup(array $group): array
    {
        $result = ['check_ins' => 0, 'check_outs' => 0, 'skipped' => 0, 'employee_not_found' => false];

        // Find employee by employee_code (maps to pin_code or id)
        $employee = Employee::where('pin_code', $group['employee_code'])
            ->orWhere('id', $group['legacy_id'])
            ->first();

        if (!$employee) {
            Log::warning("Employee not found for code: {$group['employee_code']}");
            $result['employee_not_found'] = true;
            return $result;
        }

        $punches = $group['punches'];
        sort($punches); // Sort by time

        $firstPunch = $punches[0]; // Check-in
        $lastPunch = end($punches); // Check-out

        $date = $group['date'];
        $firstPunchTime = $firstPunch->format('H:i:s');
        $lastPunchTime = $lastPunch->format('H:i:s');

        // Process Check-in (First punch)
        $existingAttendance = Attendance::where('attendance_date', $date)
            ->where('emp_id', $employee->id)
            ->where('type', 0)
            ->first();

        if (!$existingAttendance) {
            $attendance = new Attendance();
            $attendance->uid = 0;
            $attendance->emp_id = $employee->id;
            $attendance->state = 1;
            $attendance->attendance_time = $firstPunchTime;
            $attendance->attendance_date = $date;
            $attendance->type = 0;
            $attendance->status = 1;

            // Check for late time
            if ($employee->schedules->first()) {
                $scheduleTimeIn = $employee->schedules->first()->time_in;
                if ($firstPunchTime > $scheduleTimeIn) {
                    $attendance->status = 0; // Late
                    $this->recordLateTime($employee, $firstPunch);
                }
            }

            $attendance->save();
            $result['check_ins']++;
            Log::info("Check-in recorded for {$employee->name} on {$date} at {$firstPunchTime}");
        } else {
            $result['skipped']++;
        }

        // Process Check-out (Last punch, if different from first and it's later in the day)
        if (count($punches) > 1 && $firstPunchTime !== $lastPunchTime) {
            $existingLeave = Leave::where('leave_date', $date)
                ->where('emp_id', $employee->id)
                ->where('type', 1)
                ->first();

            if (!$existingLeave) {
                $leave = new Leave();
                $leave->uid = 0;
                $leave->emp_id = $employee->id;
                $leave->state = 1;
                $leave->leave_time = $lastPunchTime;
                $leave->leave_date = $date;
                $leave->type = 1;
                $leave->status = 1;

                // Check for overtime
                if ($employee->schedules->first()) {
                    $scheduleTimeOut = $employee->schedules->first()->time_out;
                    if ($lastPunchTime >= $scheduleTimeOut) {
                        $this->recordOvertime($employee, $lastPunch);
                    } else {
                        $leave->status = 0; // Early leave
                    }
                }

                $leave->save();
                $result['check_outs']++;
                Log::info("Check-out recorded for {$employee->name} on {$date} at {$lastPunchTime}");
            } else {
                $result['skipped']++;
            }
        }

        return $result;
    }

    /**
     * Record late time for employee
     */
    protected function recordLateTime(Employee $employee, Carbon $timestamp): void
    {
        try {
            $attendanceTime = new \DateTime($timestamp);
            $checkinTime = new \DateTime($employee->schedules->first()->time_in);
            $difference = $checkinTime->diff($attendanceTime)->format('%H:%I:%S');

            $latetime = new \App\Models\Latetime();
            $latetime->emp_id = $employee->id;
            $latetime->duration = $difference;
            $latetime->latetime_date = $timestamp->format('Y-m-d');
            $latetime->save();
        } catch (\Exception $e) {
            Log::error('Failed to record late time: ' . $e->getMessage());
        }
    }

    /**
     * Record overtime for employee
     */
    protected function recordOvertime(Employee $employee, Carbon $timestamp): void
    {
        try {
            $leaveTime = new \DateTime($timestamp);
            $checkoutTime = new \DateTime($employee->schedules->first()->time_out);
            $difference = $checkoutTime->diff($leaveTime)->format('%H:%I:%S');

            $overtime = new \App\Models\Overtime();
            $overtime->emp_id = $employee->id;
            $overtime->duration = $difference;
            $overtime->overtime_date = $timestamp->format('Y-m-d');
            $overtime->save();
        } catch (\Exception $e) {
            Log::error('Failed to record overtime: ' . $e->getMessage());
        }
    }

    /**
     * Get statistics from SQL Express
     */
    public function getStats(): array
    {
        try {
            $tableName = $this->getDeviceLogsTableName();

            $totalCount = DB::connection($this->connection)
                ->table($tableName)
                ->count();

            $todayCount = DB::connection($this->connection)
                ->table($tableName)
                ->whereRaw('LogDate >= CAST(GETDATE() AS DATE)')
                ->count();

            $latest = DB::connection($this->connection)
                ->table($tableName)
                ->orderBy('LogDate', 'desc')
                ->first();

            return [
                'table' => $tableName,
                'total_records' => $totalCount,
                'today_records' => $todayCount,
                'latest_punch' => $latest ? $latest->LogDate : null,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
