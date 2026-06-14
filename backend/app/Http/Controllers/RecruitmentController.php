<?php

namespace App\Http\Controllers;

use App\Models\JobCandidate;
use App\Models\JobPosting;
use Illuminate\Http\Request;

class RecruitmentController extends Controller
{
    private const READ_ROLES = ['super_admin', 'partner', 'manager', 'hr'];
    private const WRITE_ROLES = ['super_admin', 'hr'];

    private const STAGES = [
        ['stage' => 'Applied',   'color' => 'border-border bg-background'],
        ['stage' => 'Screening', 'color' => 'border-blue-200 bg-blue-50'],
        ['stage' => 'Interview', 'color' => 'border-amber-200 bg-amber-50'],
        ['stage' => 'Offer',     'color' => 'border-purple-200 bg-purple-50'],
        ['stage' => 'Hired',     'color' => 'border-green-200 bg-green-50'],
    ];

    private function gate(Request $request, array $roles): ?\Illuminate\Http\JsonResponse
    {
        if (! in_array($request->user()->role, $roles)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    public function index(Request $request)
    {
        if ($deny = $this->gate($request, self::READ_ROLES)) return $deny;

        $candidates = JobCandidate::orderBy('id')->get();

        $pipeline = collect(self::STAGES)->map(fn ($s) => [
            'stage' => $s['stage'],
            'color' => $s['color'],
            'candidates' => $candidates->where('stage', $s['stage'])->values()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'role' => $c->role,
                'date' => $c->applied_label,
            ]),
        ]);

        return response()->json([
            'jobs' => JobPosting::orderBy('id')->get()->map(fn ($j) => [
                'id' => $j->id,
                'title' => $j->title,
                'dept' => $j->dept,
                'posted' => $j->posted_date->format('Y-m-d'),
                'applicants' => $j->applicants,
                'status' => $j->status,
            ]),
            'pipeline' => $pipeline,
        ]);
    }

    public function storeJob(Request $request)
    {
        if ($deny = $this->gate($request, self::WRITE_ROLES)) return $deny;

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'dept' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:5000',
            'employment_type' => 'nullable|in:Full-time,Part-time,Contract,Internship',
        ]);

        $job = JobPosting::create([
            'title' => $validated['title'],
            'dept' => ($validated['dept'] ?? null) ?: 'General',
            'description' => $validated['description'] ?? null,
            'employment_type' => $validated['employment_type'] ?? 'Full-time',
            'posted_date' => now()->toDateString(),
            'status' => 'Active',
        ]);

        return response()->json([
            'ok' => true,
            'job' => [
                'id' => $job->id,
                'title' => $job->title,
                'dept' => $job->dept,
                'posted' => $job->posted_date->format('Y-m-d'),
                'applicants' => 0,
                'status' => 'Active',
            ],
        ], 201);
    }

    public function updateJob(Request $request, $id)
    {
        if ($deny = $this->gate($request, self::WRITE_ROLES)) return $deny;

        $job = JobPosting::findOrFail($id);
        $validated = $request->validate(['status' => 'required|in:Active,Closed']);
        $job->update($validated);

        return response()->json(['ok' => true]);
    }

    public function storeCandidate(Request $request)
    {
        if ($deny = $this->gate($request, self::WRITE_ROLES)) return $deny;

        $validated = $request->validate([
            'job_posting_id' => 'required|exists:job_postings,id',
            'name'           => 'required|string|max:255',
            'role'           => 'nullable|string|max:255',
            'email'          => 'nullable|email|max:255',
            'phone'          => 'nullable|string|max:50',
            'resume_url'     => 'nullable|url|max:1000',
        ]);

        $candidate = JobCandidate::create([
            'job_posting_id' => $validated['job_posting_id'],
            'name'           => $validated['name'],
            'role'           => $validated['role'] ?? null,
            'email'          => $validated['email'] ?? null,
            'phone'          => $validated['phone'] ?? null,
            'resume_url'     => $validated['resume_url'] ?? null,
            'stage'          => 'Applied',
            'applied_label'  => now()->format('d M Y'),
        ]);

        JobPosting::where('id', $validated['job_posting_id'])->increment('applicants');

        return response()->json(['ok' => true, 'candidate' => [
            'id'    => $candidate->id,
            'name'  => $candidate->name,
            'role'  => $candidate->role,
            'stage' => $candidate->stage,
            'date'  => $candidate->applied_label,
        ]], 201);
    }

    public function updateCandidate(Request $request, $id)
    {
        if ($deny = $this->gate($request, self::WRITE_ROLES)) return $deny;

        $candidate = JobCandidate::findOrFail($id);
        $validated = $request->validate([
            'stage' => 'required|in:Applied,Screening,Interview,Offer,Hired,Rejected',
        ]);

        $candidate->update(['stage' => $validated['stage']]);

        return response()->json(['ok' => true, 'stage' => $candidate->stage]);
    }
}
