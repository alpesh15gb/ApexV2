<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Employee;
use Carbon\Carbon;

/**
 * HikvisionService - Fetches attendance events from MySQL Hikvision database
 * 
 * Based on hik_attendance_logs table structure:
 * - Table: hik_attendance_logs
 * - Columns: emp_code, person_name, auth_datetime, direction, device_name, 
 *            access_date, access_time, emp_dept, card_no, processed
 */
class HikvisionService
{
    protected $connection = 'hikvision';
    protected $table = 'hik_attendance_logs';

    public function __construct()
    {
        // Table name can be overridden via env
        $this->table = env('HIKVISION_TABLE', 'hik_attendance_logs');
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
            Log::error('Hikvision MySQL connection failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch attendance events from Hikvision MySQL
     */
    public function fetchAttendanceEvents(Carbon $since = null, bool $unprocessedOnly = false): array
    {
        try {
            $query = DB::connection($this->connection)
                ->table($this->table)
                ->select([
                    'id',
                    'emp_code',
                    'person_name',
                    'auth_datetime',
                    'direction',
                    'device_name',
                    'access_date',
                    'access_time',
                    'emp_dept',
                    'card_no',
                    'processed',
                ]);

            // Filter by date
            if ($since) {
                $query->where('auth_datetime', '>=', $since);
            } else {
                // Default: last 30 days
                $query->where('auth_datetime', '>', Carbon::now()->subDays(30));
            }

            // Only unprocessed records if requested
            if ($unprocessedOnly) {
                $query->where(function ($q) {
                    $q->where('processed', false)
                        ->orWhereNull('processed');
                });
            }

            return $query->orderBy('auth_datetime', 'asc')->get()->toArray();

        } catch (\Exception $e) {
            Log::error('Failed to fetch Hikvision events: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Sync Hikvision events to local attendance/leave tables
     */
    public function syncToAttendance(Carbon $since = null, bool $markProcessed = true): array
    {
        $stats = [
            'fetched' => 0,
            'check_ins' => 0,
            'check_outs' => 0,
            'skipped' => 0,
            'errors' => 0,
            'employees_created' => 0,
            'employees_not_found' => 0,
        ];

        $events = $this->fetchAttendanceEvents($since);
        $stats['fetched'] = count($events);

        // Group events by employee code and date
        $eventGroups = [];
        foreach ($events as $event) {
            if (!$event->auth_datetime || !$event->emp_code) {
                continue;
            }

            $eventTime = Carbon::parse($event->auth_datetime);
            $eventDate = $eventTime->format('Y-m-d');
            $key = $event->emp_code . '_' . $eventDate;

            if (!isset($eventGroups[$key])) {
                $eventGroups[$key] = [
                    'emp_code' => $event->emp_code,
                    'person_name' => $event->person_name,
                    'emp_dept' => $event->emp_dept,
                    'date' => $eventDate,
                    'events' => [],
                    'event_ids' => [],
                ];
            }

            $eventGroups[$key]['events'][] = [
                'time' => $eventTime,
                'direction' => $event->direction,
                'device' => $event->device_name,
            ];
            $eventGroups[$key]['event_ids'][] = $event->id;
        }

        // Process each group
        foreach ($eventGroups as $group) {
            try {
                $result = $this->processEventGroup($group);
                $stats['check_ins'] += $result['check_ins'];
                $stats['check_outs'] += $result['check_outs'];
                $stats['skipped'] += $result['skipped'];
                $stats['employees_created'] += $result['employee_created'] ? 1 : 0;

                if ($result['employee_not_found']) {
                    $stats['employees_not_found']++;
                }

                // Mark as processed
                if ($markProcessed && !empty($group['event_ids'])) {
                    $this->markAsProcessed($group['event_ids']);
                }

            } catch (\Exception $e) {
                Log::error('Error processing Hikvision event group: ' . $e->getMessage(), $group);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Process a group of events for one employee on one date
     */
    protected function processEventGroup(array $group): array
    {
        $result = [
            'check_ins' => 0,
            'check_outs' => 0,
            'skipped' => 0,
            'employee_not_found' => false,
            'employee_created' => false,
        ];

        // Find employee by pin_code (employee code)
        $employee = Employee::where('pin_code', $group['emp_code'])->first();

        if (!$employee) {
            // Auto-create employee if configured
            if (env('HIKVISION_AUTO_CREATE_EMPLOYEE', false)) {
                $employee = $this->createEmployee($group);
                if ($employee) {
                    $result['employee_created'] = true;
                    Log::info("Auto-created employee: {$employee->name} ({$group['emp_code']})");
                }
            }

            if (!$employee) {
                Log::warning("Employee not found for Hikvision code: {$group['emp_code']}");
                $result['employee_not_found'] = true;
                return $result;
            }
        }

        $events = $group['events'];

        // Sort by time
        usort($events, function ($a, $b) {
            return $a['time'] <=> $b['time'];
        });

        // Determine first and last punch
        $firstEvent = $events[0];
        $lastEvent = end($events);

        $date = $group['date'];
        $firstTime = $firstEvent['time']->format('H:i:s');
        $lastTime = $lastEvent['time']->format('H:i:s');

        // Use direction if available, otherwise use time-based heuristic
        $hasExplicitIn = false;
        $hasExplicitOut = false;

        foreach ($events as $event) {
            $dir = strtolower($event['direction'] ?? '');
            if (in_array($dir, ['in', 'entry', '0'])) {
                $hasExplicitIn = true;
            }
            if (in_array($dir, ['out', 'exit', '1'])) {
                $hasExplicitOut = true;
            }
        }

        // Process Check-in
        $existingAttendance = Attendance::where('attendance_date', $date)
            ->where('emp_id', $employee->id)
            ->where('type', 0)
            ->first();

        if (!$existingAttendance) {
            $attendance = new Attendance();
            $attendance->uid = 0;
            $attendance->emp_id = $employee->id;
            $attendance->state = 1;
            $attendance->attendance_time = $firstTime;
            $attendance->attendance_date = $date;
            $attendance->type = 0;
            $attendance->status = 1;

            // Check for late time
            if ($employee->schedules->first()) {
                $scheduleTimeIn = $employee->schedules->first()->time_in;
                if ($firstTime > $scheduleTimeIn) {
                    $attendance->status = 0; // Late
                    $this->recordLateTime($employee, $firstEvent['time']);
                }
            }

            $attendance->save();
            $result['check_ins']++;
            Log::info("Hikvision check-in for {$employee->name} on {$date} at {$firstTime}");
        } else {
            $result['skipped']++;
        }

        // Process Check-out (if there are multiple events and time differs)
        if (count($events) > 1 && $firstTime !== $lastTime) {
            $existingLeave = Leave::where('leave_date', $date)
                ->where('emp_id', $employee->id)
                ->where('type', 1)
                ->first();

            if (!$existingLeave) {
                $leave = new Leave();
                $leave->uid = 0;
                $leave->emp_id = $employee->id;
                $leave->state = 1;
                $leave->leave_time = $lastTime;
                $leave->leave_date = $date;
                $leave->type = 1;
                $leave->status = 1;

                // Check for overtime
                if ($employee->schedules->first()) {
                    $scheduleTimeOut = $employee->schedules->first()->time_out;
                    if ($lastTime >= $scheduleTimeOut) {
                        $this->recordOvertime($employee, $lastEvent['time']);
                    } else {
                        $leave->status = 0; // Early leave
                    }
                }

                $leave->save();
                $result['check_outs']++;
                Log::info("Hikvision check-out for {$employee->name} on {$date} at {$lastTime}");
            } else {
                $result['skipped']++;
            }
        }

        return $result;
    }

    /**
     * Create employee from Hikvision data
     */
    protected function createEmployee(array $group): ?Employee
    {
        try {
            $employee = new Employee();
            $employee->name = $group['person_name'] ?? 'HIK-' . $group['emp_code'];
            $employee->pin_code = $group['emp_code'];
            $employee->email = $group['emp_code'] . '@hikvision.local';
            $employee->save();

            return $employee;
        } catch (\Exception $e) {
            Log::error('Failed to create employee: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Mark events as processed
     */
    protected function markAsProcessed(array $eventIds): void
    {
        try {
            DB::connection($this->connection)
                ->table($this->table)
                ->whereIn('id', $eventIds)
                ->update(['processed' => true]);
        } catch (\Exception $e) {
            Log::warning('Failed to mark events as processed: ' . $e->getMessage());
        }
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
     * Get statistics from Hikvision database
     */
    public function getStats(): array
    {
        try {
            $totalCount = DB::connection($this->connection)
                ->table($this->table)
                ->count();

            $todayCount = DB::connection($this->connection)
                ->table($this->table)
                ->whereRaw('DATE(auth_datetime) = CURDATE()')
                ->count();

            $unprocessedCount = DB::connection($this->connection)
                ->table($this->table)
                ->where(function ($q) {
                    $q->where('processed', false)
                        ->orWhereNull('processed');
                })
                ->count();

            $latest = DB::connection($this->connection)
                ->table($this->table)
                ->orderBy('auth_datetime', 'desc')
                ->first();

            return [
                'table' => $this->table,
                'total_records' => $totalCount,
                'today_records' => $todayCount,
                'unprocessed_records' => $unprocessedCount,
                'latest_event' => $latest ? $latest->auth_datetime : null,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
