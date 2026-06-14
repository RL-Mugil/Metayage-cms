<?php

namespace App\Http\Controllers;

use App\Http\PaginationHelper;
use App\Models\Client;
use App\Models\FeedbackEntry;
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

        DB::table('ip_notifications')->insert([
            'user_id' => $request->user()->id,
            'type' => 'feedback_request',
            'title' => 'Feedback request sent',
            'description' => "CSAT survey \"{$validated['subject']}\" sent to {$validated['client']}",
            'meta' => json_encode($validated),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }
}
