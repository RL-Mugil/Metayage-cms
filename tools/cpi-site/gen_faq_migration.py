"""One-off: writes the cpi_faqs migration, seeded with the FAQ questions currently in content.py."""
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, HERE)
from content import FAQS  # noqa: E402

rows = [{"question": q, "answer": a} for q, a in FAQS]
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
        Schema::create('cpi_faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question', 300);
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Starting questions; edit, reorder, hide or delete them from the admin panel (FAQ tab).
        $rows = json_decode(<<<'JSON'
%s
JSON, true);
        $now = now();
        foreach ($rows as $i => $row) {
            DB::table('cpi_faqs')->insert([
                'question' => $row['question'], 'answer' => $row['answer'], 'sort_order' => ($i + 1) * 10,
                'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cpi_faqs');
    }
};
""" % payload

dest = os.path.join(ROOT, "backend", "database", "migrations", "2026_10_14_000004_create_cpi_faqs_table.php")
with open(dest, "w", encoding="utf-8", newline="\n") as f:
    f.write(php)
print("wrote", dest, len(rows), "questions")
