<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Departments
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete(); // HOD
            $table->timestamps();
        });

        // Designations / Job Roles
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('grade_band')->nullable(); // Grade band e.g. A, B, C, L1, L2
            $table->timestamps();
        });

        // Employee Info Master
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code')->unique(); // EMP-YYYY-XXXX
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // links to standard login user
            $table->string('full_name');
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->string('nationality')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('blood_group')->nullable();
            $table->text('emergency_contact')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('work_email')->unique();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->text('current_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->text('aadhaar_ssn_encrypted')->nullable();
            $table->text('pan_tax_id')->nullable();
            $table->string('uan_pf_number')->nullable();
            $table->string('esi_number')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_ifsc_code')->nullable();
            $table->date('date_of_joining');
            $table->date('confirmation_date')->nullable();
            $table->string('employment_type')->default('Full-time'); // Full-time, Part-time, Contract, Intern, Consultant
            $table->string('employment_status')->default('Active'); // Active, On Probation, Suspended, On Leave, Resigned, Terminated
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->foreignId('reporting_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dotted_line_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('work_location')->default('Office'); // Office, Remote, Hybrid
            $table->string('shift')->default('General');
            $table->string('biometric_id')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('resume_path')->nullable();
            $table->json('id_documents')->nullable(); // Scan paths
            $table->timestamps();
        });

        // Attendance Log
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->date('attendance_date');
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->string('capture_method')->default('Web Check-in'); // Biometric, Web Check-in, GPS Mobile, QR Code
            $table->string('location_gps')->nullable();
            $table->string('status')->default('Present'); // Present, Absent, Half Day, On Leave, LOP, Weekend, Holiday
            $table->integer('duration_minutes')->default(0);
            $table->boolean('regularized')->default(false);
            $table->text('regularization_reason')->nullable();
            $table->timestamps();
        });

        // Leave Requests
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->string('leave_type'); // Earned Leave, Casual Leave, Sick Leave, Maternity, Loss of Pay
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('total_days', 4, 1);
            $table->text('reason')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('status')->default('Pending'); // Pending, Approved, Rejected, Cancelled
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comments')->nullable();
            $table->timestamps();
        });

        // Leave Balances
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->integer('year');
            $table->decimal('earned_leave', 5, 2)->default(0.00);
            $table->decimal('casual_leave', 5, 2)->default(0.00);
            $table->decimal('sick_leave', 5, 2)->default(0.00);
            $table->decimal('maternity_leave', 5, 2)->default(0.00);
            $table->decimal('lop_days', 5, 2)->default(0.00);
            $table->timestamps();
        });

        // Asset Catalog & Allocations
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_tag')->unique(); // QR code/bar code identifier
            $table->string('name');
            $table->string('category'); // Laptop, Monitor, Phone, License
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('status')->default('Available'); // Available, Allocated, Maintenance, Disposed, Damaged
            $table->foreignId('assigned_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('allocated_date')->nullable();
            $table->date('returned_date')->nullable();
            $table->timestamps();
        });

        // Expense Claims
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->string('category'); // Travel, Accommodation, Meals, Communication, Supplies, etc.
            $table->decimal('amount', 15, 2);
            $table->string('currency')->default('INR');
            $table->date('claim_date');
            $table->text('description')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('status')->default('Pending'); // Pending, Approved, Rejected, Paid
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Grievance Redressal
        Schema::create('grievances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->onDelete('cascade'); // Nullable if anonymous
            $table->boolean('anonymous')->default(false);
            $table->string('category'); // Harassment, Discrimination, Pay, Work Environment, Other
            $table->text('description');
            $table->string('status')->default('Open'); // Open, Investigation, Resolved, Closed, Appealed
            $table->foreignId('assigned_hr_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievances');
        Schema::dropIfExists('expense_claims');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('designations');
        Schema::dropIfExists('departments');
    }
};
