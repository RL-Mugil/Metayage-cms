<?php

namespace App\Http\Controllers;

use App\Http\PaginationHelper;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\FeedbackEntry;
use App\Models\FeedbackRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeedbackController extends Controller
{
    private function denyClients(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if ($request->user()->isClientRole()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    public function index(Request $request)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $query = FeedbackEntry::orderByDesc('entry_date');
        $result = PaginationHelper::paginate($query, $request);
        $result['data'] = $result['data']->map(fn ($f) => [
            'id'       => $f->id,
            'client'   => $f->client_name,
            'rating'   => $f->rating,
            'comment'  => $f->comment,
            'date'     => $f->entry_date->format('Y-m-d'),
            'category' => $f->category,
        ]);

        return response()->json($result);
    }

    /** Client portal users submit a CSAT rating. */
    public function storeEntry(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'rating'      => 'required|integer|min:1|max:5',
            'comment'     => 'nullable|string|max:2000',
            'category'    => 'nullable|string|max:100',
        ]);

        if ($user->isClientRole()) {
            // Pin the client name from the DB — prevents spoofing.
            $client = $this->clientFor($request);
            $validated['client_name'] = $client ? $client->company_name : $user->name;
        } else {
            // Staff must reference a real client company name to prevent fake entries.
            $exists = Client::where('company_name', $validated['client_name'])
                ->orWhere('legal_name', $validated['client_name'])
                ->exists();
            if (! $exists) {
                return response()->json(['message' => 'Client name does not match any known client.'], 422);
            }
        }

        $entry = FeedbackEntry::create([
            'client_name' => $validated['client_name'],
            'rating'      => $validated['rating'],
            'comment'     => $validated['comment'] ?? null,
            'category'    => $validated['category'] ?? 'General',
            'entry_date'  => now()->toDateString(),
        ]);

        return response()->json(['ok' => true, 'id' => $entry->id], 201);
    }

    /** Record a feedback request; surfaces as an in-app notification for the requester. */
    public function requestFeedback(Request $request)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $validated = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'subject'    => 'nullable|string|max:255',
        ]);

        $project = \App\Models\Project::with('client')->findOrFail($validated['project_id']);
        if (! $project->client) {
            return response()->json(['message' => 'This case has no client attached.'], 422);
        }

        // One open request per case — avoid spamming the client.
        $existing = FeedbackRequest::where('project_id', $project->id)->where('status', 'Pending')->exists();
        if ($existing) {
            return response()->json(['message' => "A feedback request for {$project->docket_number} is already pending with the client."], 422);
        }

        $fr = FeedbackRequest::create([
            'project_id'      => $project->id,
            'client_id'       => $project->client_id,
            'docket_number'   => $project->docket_number,
            'subject'         => ($validated['subject'] ?? null) ?: "Case experience — {$project->docket_number}",
            'requested_by_id' => $request->user()->id,
            'status'          => 'Pending',
        ]);

        // Notify every portal user of this client
        \App\Support\Notifier::push(
            collect($project->client->portalUserIds())->all(),
            'feedback_request',
            'Feedback requested',
            "{$request->user()->name} requested your feedback on case {$project->docket_number}",
            '/feedback',
            ['feedback_request_id' => $fr->id, 'project_id' => $project->id],
        );

        return response()->json($fr, 201);
    }

    /** Resolve the client record for a portal user. */
    private function clientFor(Request $request): ?Client
    {
        $user = $request->user();
        if (! $user->isClientRole()) return null;
        return $request->attributes->get('portal_client') ?? Client::forUser($user);
    }

    /** List feedback requests — firm sees all (managers: own), clients see their own client's. */
    public function requests(Request $request)
    {
        $user  = $request->user();
        $query = FeedbackRequest::with('requester:id,name', 'client:id,company_name')
            ->orderByDesc('created_at');

        if ($user->isClientRole()) {
            $client = $this->clientFor($request);
            if (! $client) return response()->json(['message' => 'Forbidden'], 403);
            $query->where('client_id', $client->id);
        } elseif ($user->role === 'manager') {
            $query->where('requested_by_id', $user->id);
        }

        $canRate = $user->role === 'client_admin';

        return response()->json($query->limit(200)->get()->map(fn ($r) => [
            'id'            => $r->id,
            'docket_number' => $r->docket_number,
            'subject'       => $r->subject,
            'client'        => $r->client?->company_name,
            'requester'     => $r->requester?->name,
            'status'        => $r->status,
            'rating'        => $r->rating,
            'comment'       => $r->comment,
            'requested_at'  => $r->created_at?->toDateString(),
            'completed_at'  => $r->completed_at?->toDateString(),
            'can_rate'      => $canRate && $r->status === 'Pending',
        ]));
    }

    /** client_admin rates a pending feedback request for their own client. */
    public function rate(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'client_admin') {
            return response()->json(['message' => 'Only your portal admin can submit the case rating.'], 403);
        }

        $client = $this->clientFor($request);
        $fr = FeedbackRequest::findOrFail($id);
        if (! $client || (int) $fr->client_id !== (int) $client->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($fr->status !== 'Pending') {
            return response()->json(['message' => 'This request has already been rated.'], 422);
        }

        $validated = $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        $fr->update([
            'rating'          => $validated['rating'],
            'comment'         => $validated['comment'] ?? null,
            'status'          => 'Completed',
            'completed_by_id' => $user->id,
            'completed_at'    => now(),
        ]);

        // Feed the CSAT dashboard
        FeedbackEntry::create([
            'client_name' => $client->company_name ?? $client->legal_name,
            'rating'      => $validated['rating'],
            'comment'     => trim(($fr->docket_number ? "[{$fr->docket_number}] " : '') . ($validated['comment'] ?? '')),
            'category'    => 'Overall',
            'entry_date'  => now()->toDateString(),
        ]);

        // Notify the requesting staff member
        \App\Support\Notifier::push(
            $fr->requested_by_id,
            'feedback_request',
            'Case feedback received',
            "{$client->company_name} rated case {$fr->docket_number}: {$validated['rating']}/5",
            '/feedback',
            ['feedback_request_id' => $fr->id, 'rating' => $validated['rating']],
        );

        return response()->json(['ok' => true]);
    }
}
