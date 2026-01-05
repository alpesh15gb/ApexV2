<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;

use App\Http\Controllers\Controller;
use App\Http\Requests\FingerDevice\StoreRequest;
use App\Http\Requests\FingerDevice\UpdateRequest;

use App\Models\FingerDevices;
use App\Models\Employee;
use App\Models\Attendance;
use App\Models\Leave;

use App\Services\PunchLogService;
use App\Services\HikvisionService;

use Gate;
use Symfony\Component\HttpFoundation\Response;

class BiometricDeviceController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $devices = FingerDevices::all();

        // Get sync status for display
        $punchLogService = new PunchLogService();
        $hikvisionService = new HikvisionService();

        $syncStatus = [
            'sqlexpress_connected' => $punchLogService->testConnection(),
            'hikvision_connected' => $hikvisionService->testConnection(),
            'last_sync' => $this->getLastSyncTimes(),
        ];

        return view('admin.fingerDevices.index', compact('devices', 'syncStatus'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('admin.fingerDevices.create');
    }

    /**
     * Store a newly created resource in storage.
     * Note: Device registration is now for reference only - devices are not directly polled
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreRequest $request): RedirectResponse
    {
        // Create device record for reference (no direct device connection needed)
        FingerDevices::create($request->validated() + [
            'serialNumber' => $request->input('serialNumber', 'DB-SYNC-DEVICE')
        ]);

        flash()->success('Success', 'Device registered successfully! Attendance will sync from external databases.');

        return redirect()->route('finger_device.index');
    }

    public function show(FingerDevices $fingerDevice)
    {
        return view('admin.fingerDevices.show', compact('fingerDevice'));
    }

    public function edit(FingerDevices $fingerDevice)
    {
        return view('admin.fingerDevices.edit', compact('fingerDevice'));
    }

    public function update(UpdateRequest $request, FingerDevices $fingerDevice): RedirectResponse
    {
        $fingerDevice->update($request->validated());

        flash()->success('Success', 'Device Updated successfully!');

        return redirect()->route('finger_device.index');
    }

    public function destroy(FingerDevices $fingerDevice): RedirectResponse
    {
        try {
            $fingerDevice->delete();
        } catch (\Exception $e) {
            toast("Failed to delete {$fingerDevice->name}", 'error');
        }

        flash()->success('Success', 'Device deleted successfully!');

        return back();
    }

    /**
     * Add employee - now a no-op since we sync from external databases
     * Employee mapping is done by employee_id matching
     */
    public function addEmployee(FingerDevices $fingerDevice): RedirectResponse
    {
        flash()->info('Info', 'Employee sync is automatic. Employees are matched by ID from external databases.');

        return back();
    }

    /**
     * Trigger manual sync from external databases
     */
    public function getAttendance(FingerDevices $fingerDevice)
    {
        $results = [];

        // Sync from SQL Express Punch Logs
        try {
            $punchLogService = new PunchLogService();
            if ($punchLogService->testConnection()) {
                $punchStats = $punchLogService->syncToAttendance();
                $results['punch_logs'] = $punchStats;
            } else {
                $results['punch_logs'] = ['error' => 'Connection failed'];
            }
        } catch (\Exception $e) {
            $results['punch_logs'] = ['error' => $e->getMessage()];
        }

        // Sync from Hikvision MySQL
        try {
            $hikvisionService = new HikvisionService();
            if ($hikvisionService->testConnection()) {
                $hikStats = $hikvisionService->syncToAttendance();
                $results['hikvision'] = $hikStats;
            } else {
                $results['hikvision'] = ['error' => 'Connection failed'];
            }
        } catch (\Exception $e) {
            $results['hikvision'] = ['error' => $e->getMessage()];
        }

        // Build summary message
        $message = 'Sync completed! ';

        if (isset($results['punch_logs']['check_ins'])) {
            $message .= "SQL Express: {$results['punch_logs']['fetched']} fetched, ";
            $message .= "{$results['punch_logs']['check_ins']} check-ins, ";
            $message .= "{$results['punch_logs']['check_outs']} check-outs. ";
        } elseif (isset($results['punch_logs']['error'])) {
            $message .= "SQL Express: {$results['punch_logs']['error']}. ";
        }

        if (isset($results['hikvision']['check_ins'])) {
            $message .= "Hikvision: {$results['hikvision']['fetched']} fetched, ";
            $message .= "{$results['hikvision']['check_ins']} check-ins, ";
            $message .= "{$results['hikvision']['check_outs']} check-outs.";
        } elseif (isset($results['hikvision']['error'])) {
            $message .= "Hikvision: {$results['hikvision']['error']}.";
        }

        flash()->success('Success', $message);

        return back();
    }

    /**
     * Test database connections
     */
    public function testConnections()
    {
        $punchLogService = new PunchLogService();
        $hikvisionService = new HikvisionService();

        $status = [
            'sqlexpress' => $punchLogService->testConnection(),
            'hikvision' => $hikvisionService->testConnection(),
        ];

        return response()->json($status);
    }

    /**
     * Get last sync times
     */
    protected function getLastSyncTimes(): array
    {
        $lastAttendance = Attendance::orderBy('created_at', 'desc')->first();
        $lastLeave = Leave::orderBy('created_at', 'desc')->first();

        return [
            'last_attendance' => $lastAttendance ? $lastAttendance->created_at->diffForHumans() : 'Never',
            'last_leave' => $lastLeave ? $lastLeave->created_at->diffForHumans() : 'Never',
        ];
    }
}
