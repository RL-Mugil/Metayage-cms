<?php

namespace App\Http\Controllers;

use App\Http\PaginationHelper;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\FeedbackEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeedbackController extends Controller
{
    private function denyClients(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if ($request->user()->role === 'client') {
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

        // Pin the client name from the DB for client-role users — prevents spoofing.
        if ($user->role === 'client') {
            $client = Client::whereHas('contacts', fn ($q) => $q->where('email', $user->email))->first();
            $validated['client_name'] = $client ? $client->company_name : $user->name;
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
            'client' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
        ]);

        // Resolve the client's portal user so the notification lands on their dashboard.
        $clientUser = Client::where('company_name', $validated['client'])
            ->first()
            ?->contacts()
            ->join('users', 'users.email', '=', 'client_contacts.email')
            ->select('users.id')
            ->first();

        $notifyUserId = $clientUser?->id ?? $request->user()->id;

        DB::table('ip_notifications')->insert([
            'user_id' => $notifyUserId,
            'type' => 'feedback_request',
            'title' => 'Feedback request received',
            'description' => "CSAT survey \"{$validated['subject']}\" has been sent to you by {$request->user()->name}",
            'meta' => json_encode($validated),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }
}
