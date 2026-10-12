<?php

declare(strict_types=1);

use App\Modules\Hrm\Http\Controllers\AttendanceController;
use App\Modules\Hrm\Http\Controllers\CorrectionController;
use App\Modules\Hrm\Http\Controllers\EmployeeController;
use App\Modules\Hrm\Http\Controllers\EmployeeRecordController;
use App\Modules\Hrm\Http\Controllers\HolidayController;
use App\Modules\Hrm\Http\Controllers\LeaveController;
use App\Modules\Hrm\Http\Controllers\MeController;
use App\Modules\Hrm\Http\Controllers\ReportController;
use App\Modules\Hrm\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('hrm')->group(function (): void {
    // Self-service: the signed-in person's own record.
    Route::get('me', [MeController::class, 'show'])->middleware('can:hrm.self.view')->name('hrm.me');

    // Employees. `employees/create` is declared before `{employee}` so it is not read as an id.
    Route::get('employees', [EmployeeController::class, 'index'])->middleware('can:hrm.employees.view')->name('hrm.employees.index');
    Route::get('employees/create', [EmployeeController::class, 'create'])->middleware('can:hrm.employees.create')->name('hrm.employees.create');
    Route::post('employees', [EmployeeController::class, 'store'])->middleware('can:hrm.employees.create')->name('hrm.employees.store');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->middleware('can:hrm.employees.view')->name('hrm.employees.show');
    Route::put('employees/{employee}', [EmployeeController::class, 'update'])->middleware('can:hrm.employees.manage')->name('hrm.employees.update');
    Route::post('employees/{employee}/status', [EmployeeController::class, 'status'])->middleware('can:hrm.employees.lifecycle')->name('hrm.employees.status');

    Route::middleware('can:hrm.employees.manage')->group(function (): void {
        Route::post('employees/{employee}/emergency-contacts', [EmployeeRecordController::class, 'storeContact'])->name('hrm.employees.contacts.store');
        Route::put('employees/{employee}/emergency-contacts/{contact}', [EmployeeRecordController::class, 'updateContact'])->name('hrm.employees.contacts.update');
        Route::delete('employees/{employee}/emergency-contacts/{contact}', [EmployeeRecordController::class, 'destroyContact'])->name('hrm.employees.contacts.destroy');
        Route::post('employees/{employee}/documents', [EmployeeRecordController::class, 'storeDocument'])->middleware('throttle:30,1')->name('hrm.employees.documents.store');
        Route::delete('employees/{employee}/documents/{document}', [EmployeeRecordController::class, 'destroyDocument'])->name('hrm.employees.documents.destroy');
        Route::post('employees/{employee}/assets', [EmployeeRecordController::class, 'storeAsset'])->name('hrm.employees.assets.store');
        Route::post('employees/{employee}/assets/{asset}/return', [EmployeeRecordController::class, 'returnAsset'])->name('hrm.employees.assets.return');
    });
    // Private files: the controller also requires `hrm.personal.view` over the record (or being the employee).
    Route::get('employees/{employee}/documents/{document}', [EmployeeRecordController::class, 'downloadDocument'])
        ->middleware('can:hrm.employees.view')->name('hrm.employees.documents.download');

    // Attendance.
    Route::middleware('can:hrm.attendance.view')->group(function (): void {
        Route::get('attendance', [AttendanceController::class, 'index'])->name('hrm.attendance.index');
        Route::get('attendance/summary', [AttendanceController::class, 'summary'])->name('hrm.attendance.summary');
        Route::get('attendance/corrections', [CorrectionController::class, 'index'])->name('hrm.attendance.corrections.index');
        Route::get('attendance/employees/{employee}', [AttendanceController::class, 'employee'])->name('hrm.attendance.employee');
    });
    Route::middleware('can:hrm.attendance.clock')->group(function (): void {
        Route::post('attendance/clock-in', [AttendanceController::class, 'clockIn'])->middleware('throttle:30,1')->name('hrm.attendance.clock-in');
        Route::post('attendance/clock-out', [AttendanceController::class, 'clockOut'])->middleware('throttle:30,1')->name('hrm.attendance.clock-out');
    });
    Route::middleware('can:hrm.attendance.manage')->group(function (): void {
        Route::post('attendance/entries', [AttendanceController::class, 'storeEntry'])->name('hrm.attendance.entries.store');
        Route::delete('attendance/entries/{entry}', [AttendanceController::class, 'destroyEntry'])->name('hrm.attendance.entries.destroy');
        Route::post('attendance/shift-assignments', [AttendanceController::class, 'assignShift'])->name('hrm.attendance.shift-assignments.store');
    });
    Route::post('attendance/corrections', [CorrectionController::class, 'store'])
        ->middleware('can:hrm.attendance.regularize')->name('hrm.attendance.corrections.store');
    Route::post('attendance/corrections/{correction}/decision', [CorrectionController::class, 'decision'])
        ->middleware('can:hrm.attendance.approve')->name('hrm.attendance.corrections.decision');
    Route::post('attendance/corrections/{correction}/cancel', [CorrectionController::class, 'cancel'])
        ->middleware('can:hrm.attendance.view')->name('hrm.attendance.corrections.cancel');

    // Leave.
    Route::middleware('can:hrm.leave.view')->group(function (): void {
        Route::get('leave', [LeaveController::class, 'index'])->name('hrm.leave.index');
        Route::get('leave/balances', [LeaveController::class, 'balances'])->name('hrm.leave.balances');
        Route::get('leave/calendar', [LeaveController::class, 'calendar'])->name('hrm.leave.calendar');
        Route::get('leave/requests/{leaveRequest}', [LeaveController::class, 'show'])->name('hrm.leave.requests.show');
        Route::post('leave/requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('hrm.leave.requests.cancel');
    });
    Route::get('leave/approvals', [LeaveController::class, 'approvals'])->middleware('can:hrm.leave.approve')->name('hrm.leave.approvals');
    Route::post('leave/requests', [LeaveController::class, 'store'])->middleware(['can:hrm.leave.request', 'throttle:30,1'])->name('hrm.leave.requests.store');
    Route::post('leave/requests/{leaveRequest}/decision', [LeaveController::class, 'decision'])
        ->middleware('can:hrm.leave.approve')->name('hrm.leave.requests.decision');
    Route::post('leave/adjustments', [LeaveController::class, 'adjust'])->middleware('can:hrm.leave.manage')->name('hrm.leave.adjustments.store');

    // Holidays.
    Route::get('holidays', [HolidayController::class, 'index'])->middleware('can:hrm.holidays.view')->name('hrm.holidays.index');
    Route::middleware('can:hrm.holidays.manage')->group(function (): void {
        Route::post('holidays', [HolidayController::class, 'store'])->name('hrm.holidays.store');
        Route::put('holidays/{holiday}', [HolidayController::class, 'update'])->name('hrm.holidays.update');
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('hrm.holidays.destroy');
    });

    // Reports.
    Route::get('reports', [ReportController::class, 'index'])->middleware('can:hrm.reports.view')->name('hrm.reports.index');

    // Settings: numbering, calendar, designations, shifts, leave types and policies.
    Route::middleware('can:hrm.settings.manage')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('hrm.settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('hrm.settings.update');
        Route::post('settings/designations', [SettingsController::class, 'storeDesignation'])->name('hrm.settings.designations.store');
        Route::put('settings/designations/{designation}', [SettingsController::class, 'updateDesignation'])->name('hrm.settings.designations.update');
        Route::post('settings/shifts', [SettingsController::class, 'storeShift'])->name('hrm.settings.shifts.store');
        Route::put('settings/shifts/{shift}', [SettingsController::class, 'updateShift'])->name('hrm.settings.shifts.update');
        Route::post('settings/leave-types', [SettingsController::class, 'storeLeaveType'])->name('hrm.settings.leave-types.store');
        Route::put('settings/leave-types/{leaveType}', [SettingsController::class, 'updateLeaveType'])->name('hrm.settings.leave-types.update');
        Route::post('settings/leave-policies', [SettingsController::class, 'storeLeavePolicy'])->name('hrm.settings.leave-policies.store');
        Route::put('settings/leave-policies/{policy}', [SettingsController::class, 'updateLeavePolicy'])->name('hrm.settings.leave-policies.update');
    });
});
