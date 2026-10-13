<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CpiFaq;
use App\Services\InsightPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Admin CRUD for the questions on canpatentit.com/faq. Every change republishes the static page. */
class CpiFaqController extends Controller
{
    public function __construct(private InsightPublisher $publisher)
    {
    }

    public function index(): JsonResponse
    {
        Gate::authorize('manage-site');

        return response()->json(CpiFaq::orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');
        $data = $this->validated($request);
        $data['sort_order'] = ((int) CpiFaq::max('sort_order')) + 10; // new questions go to the end
        $data['active'] = $data['active'] ?? true;
        $faq = CpiFaq::create($data);
        $this->publisher->publishFaq();
        $this->audit($request, 'cpi_faq_create', $faq);

        return response()->json($faq, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $faq = CpiFaq::findOrFail($id);
        $faq->update($this->validated($request));
        $this->publisher->publishFaq();
        $this->audit($request, 'cpi_faq_update', $faq);

        return response()->json($faq->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $faq = CpiFaq::findOrFail($id);
        $faq->delete();
        $this->publisher->publishFaq();
        $this->audit($request, 'cpi_faq_delete', $faq);

        return response()->json(['message' => 'Deleted.']);
    }

    /** Saves a new order: body is {"ids": [3, 1, 2, ...]} listing every question id in display order. */
    public function reorder(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer', 'distinct']])['ids'];

        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $i => $id) {
                CpiFaq::where('id', $id)->update(['sort_order' => ($i + 1) * 10]);
            }
        });
        $this->publisher->publishFaq();

        return $this->index();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:300'],
            'answer' => ['required', 'string', 'min:3', 'max:4000'],
            'active' => ['sometimes', 'boolean'],
        ]);
        // Plain text only: the public page escapes it, but trimming keeps stored values tidy.
        $data['question'] = trim(preg_replace('/\s+/', ' ', $data['question']));
        $data['answer'] = trim(str_replace("\r\n", "\n", $data['answer']));

        return $data;
    }

    private function audit(Request $request, string $action, CpiFaq $f): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => $action, 'subject_type' => CpiFaq::class,
            'subject_id' => $f->id, 'metadata' => ['question' => $f->question], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }
}
