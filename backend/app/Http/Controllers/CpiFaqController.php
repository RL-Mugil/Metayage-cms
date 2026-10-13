<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CpiFaq;
use App\Services\InsightPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD for the questions on canpatentit.com. A question belongs either to the main FAQ page
 * (page = null, called "general" in the API) or to one service/technology page ("Common questions").
 * Every change republishes only the page it belongs to.
 */
class CpiFaqController extends Controller
{
    public function __construct(private InsightPublisher $publisher)
    {
    }

    /** Pages that can hold questions, for the admin selector. */
    public function pages(): JsonResponse
    {
        Gate::authorize('manage-site');
        $pages = [['key' => 'general', 'label' => 'Main FAQ page (/faq)']];
        foreach (InsightPublisher::PAGE_FAQS as $slug => [$path, $label]) {
            $pages[] = ['key' => $slug, 'label' => $label, 'path' => '/'.$path];
        }

        return response()->json($pages);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');
        $page = $this->pageFrom($request->query('page'));

        return response()->json($this->listFor($page));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');
        $data = $this->validated($request);
        $page = $this->pageFrom($request->input('page'));
        $data['page'] = $page;
        $data['sort_order'] = ((int) CpiFaq::where('page', $page)->max('sort_order')) + 10; // new questions go to the end
        $data['active'] = $data['active'] ?? true;
        $faq = CpiFaq::create($data);
        $this->publisher->publishFaqFor($page);
        $this->audit($request, 'cpi_faq_create', $faq);

        return response()->json($faq, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $faq = CpiFaq::findOrFail($id);
        $faq->update($this->validated($request)); // a question never changes page
        $this->publisher->publishFaqFor($faq->page);
        $this->audit($request, 'cpi_faq_update', $faq);

        return response()->json($faq->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $faq = CpiFaq::findOrFail($id);
        $faq->delete();
        $this->publisher->publishFaqFor($faq->page);
        $this->audit($request, 'cpi_faq_delete', $faq);

        return response()->json(['message' => 'Deleted.']);
    }

    /** Saves a new order: {"page": "general", "ids": [3, 1, 2]} lists every question id of that page in display order. */
    public function reorder(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer', 'distinct']])['ids'];
        $page = $this->pageFrom($request->input('page'));

        DB::transaction(function () use ($ids, $page) {
            foreach (array_values($ids) as $i => $id) {
                // Scoped to the page so one page's order can never move another page's questions.
                CpiFaq::where('id', $id)->where('page', $page)->update(['sort_order' => ($i + 1) * 10]);
            }
        });
        $this->publisher->publishFaqFor($page);

        return response()->json($this->listFor($page));
    }

    private function listFor(?string $page)
    {
        return CpiFaq::where('page', $page)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** "general", "" or missing means the main page (null); anything else must be a known page slug. */
    private function pageFrom(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'general') {
            return null;
        }
        validator(['page' => $value], ['page' => [Rule::in(array_keys(InsightPublisher::PAGE_FAQS))]])->validate();

        return (string) $value;
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
            'subject_id' => $f->id, 'metadata' => ['question' => $f->question, 'page' => $f->page ?? 'general'], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }
}
