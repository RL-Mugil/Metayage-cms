"""One-off: writes the migration that gives each FAQ a page and seeds the per-page questions
currently defined in content.py (the 'Common questions' block under each service/technology page)."""
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, HERE)
from content import SERVICES, AREAS  # noqa: E402

rows = []
for item in SERVICES + AREAS:
    for q, a in item["faq"]:
        rows.append({"page": item["slug"], "question": q, "answer": a})
payload = json.dumps(rows, ensure_ascii=False, indent=2)

php = """<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NULL page = the main FAQ page; otherwise the slug of a service or technology page.
        Schema::table('cpi_faqs', function (Blueprint $table) {
            $table->string('page', 60)->nullable()->after('id')->index();
        });

        $rows = json_decode(<<<'JSON'
%s
JSON, true);
        $now = now();
        $order = [];
        foreach ($rows as $row) {
            $order[$row['page']] = ($order[$row['page']] ?? 0) + 10;
            DB::table('cpi_faqs')->insert([
                'page' => $row['page'], 'question' => $row['question'], 'answer' => $row['answer'],
                'sort_order' => $order[$row['page']], 'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('cpi_faqs')->whereNotNull('page')->delete();
        Schema::table('cpi_faqs', function (Blueprint $table) {
            $table->dropColumn('page');
        });
    }
};
""" % payload

dest = os.path.join(ROOT, "backend", "database", "migrations", "2026_10_14_000005_add_page_to_cpi_faqs.php")
with open(dest, "w", encoding="utf-8", newline="\n") as f:
    f.write(php)
print("wrote", dest, len(rows), "questions across", len({r['page'] for r in rows}), "pages")
