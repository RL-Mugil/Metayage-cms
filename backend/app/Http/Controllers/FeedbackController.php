<?php

namespace App\Http\Controllers;

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

        $entries = FeedbackEntry::orderByDesc('entry_date')->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'client' => $f->client_name,
                'rating' => $f->rating,
                'comment' => $f->comment,
                'date' => $f->entry_date->format('Y-m-d'),
                'category' => $f->category,
            ]);

        return response()->json($entries);
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
