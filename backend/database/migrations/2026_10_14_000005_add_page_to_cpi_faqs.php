<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NULL page = the main FAQ page; otherwise the slug of a service or technology page.
        Schema::table('cpi_faqs', function (Blueprint $table) {
            $table->string('page', 60)->nullable()->after('id')->index();
        });

        $rows = json_decode(<<<'JSON'
[
  {
    "page": "patentability-prior-art",
    "question": "Does a search guarantee a patent will be granted?",
    "answer": "No. A search informs your decision, but examination by the patent office is a separate process and the outcome cannot be guaranteed."
  },
  {
    "page": "patentability-prior-art",
    "question": "I had a patentability search. Why do I still need an FTO search?",
    "answer": "A patentability search focuses on what is unique in your invention. An FTO search covers every feature and combination in the product you sell, so neither replaces the other."
  },
  {
    "page": "patentability-prior-art",
    "question": "Should I search before I publish or demo?",
    "answer": "Ideally yes. Public disclosure can affect your rights, so speak with us before you publish, present or launch."
  },
  {
    "page": "provisional-applications",
    "question": "What happens after twelve months?",
    "answer": "A provisional expires. If you want continued protection you need to file a non-provisional (and any international filings) within the applicable deadlines. We track the dates with you."
  },
  {
    "page": "provisional-applications",
    "question": "Can I say 'patent pending' after filing?",
    "answer": "Generally yes, once an application is on file. We can confirm how to use the term correctly for your situation."
  },
  {
    "page": "provisional-applications",
    "question": "Is there such a thing as an international patent?",
    "answer": "No. Patents are national or regional. Routes such as the Paris Convention and the PCT help you plan, but fees and examination apply country by country."
  },
  {
    "page": "us-patent-prosecution",
    "question": "How long does prosecution take?",
    "answer": "It varies widely with the technology area and the examiner's workload. We cannot promise timing, but we can explain what to expect and options for speeding things up where available."
  },
  {
    "page": "us-patent-prosecution",
    "question": "Can anyone apply for a US patent?",
    "answer": "Yes. Nationality and residence are not barriers to filing in the US, although foreign filing permissions may apply in your home country."
  },
  {
    "page": "us-patent-prosecution",
    "question": "How do you pick countries for foreign filings?",
    "answer": "By market size, the value of a patent there, the likelihood of grant, enforcement and budget."
  },
  {
    "page": "ip-strategy-diligence",
    "question": "Can I infringe other patents while selling my own patented product?",
    "answer": "Yes, potentially. A patent on one improvement does not cover the rest of the product, and others may hold patents on other features, components or combinations. Owning a patent is not a guarantee of freedom to sell."
  },
  {
    "page": "ip-strategy-diligence",
    "question": "We have no filings yet. Is strategy still useful?",
    "answer": "Yes. Early decisions about disclosure, ownership and filing order are often the cheapest to get right."
  },
  {
    "page": "ip-strategy-diligence",
    "question": "Do you only work with startups?",
    "answer": "We work with founders, engineers, research groups and the investors and advisers who back them."
  },
  {
    "page": "ai-machine-learning",
    "question": "Can an AI model or algorithm be patented?",
    "answer": "It depends on how the invention is framed and what technical improvement it provides. We assess this case by case; no outcome can be promised."
  },
  {
    "page": "semiconductors-quantum",
    "question": "When should I file relative to a conference paper?",
    "answer": "Before you publish or present. Public disclosure can limit your rights, particularly outside the US, so talk to us first."
  },
  {
    "page": "medtech-biotechnology",
    "question": "Do you give regulatory or medical advice?",
    "answer": "No. We focus on IP strategy and will coordinate with your regulatory and clinical advisers."
  },
  {
    "page": "climate-energy",
    "question": "We are sharing details with a pilot partner. Is that a problem?",
    "answer": "It can be. Disclosure terms and timing matter, so talk to us before details leave the building."
  },
  {
    "page": "enterprise-software-security",
    "question": "Is open-source involvement a problem for patenting?",
    "answer": "Not automatically, but licences, public repositories and disclosures all matter. We review them with you before filing."
  }
]
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
