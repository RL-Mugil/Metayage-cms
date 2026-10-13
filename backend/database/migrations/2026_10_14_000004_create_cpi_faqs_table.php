<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
[
  {
    "question": "What is CanPatentIt?",
    "answer": "CanPatentIt is a US patent strategy brand of Metayage Private Limited for deep-technology founders, engineers and investors. We help you decide what to protect and how, from first idea to funding diligence."
  },
  {
    "question": "Is the information on this site legal advice?",
    "answer": "No. The site gives general information only. Using it, taking the self-check or booking a call does not create an attorney-client relationship. An engagement begins only when we agree in writing."
  },
  {
    "question": "Can you promise my invention will be patented?",
    "answer": "No one can. Patent offices decide, and outcomes depend on the facts and the prior art. We can help you make informed decisions and present your invention well."
  },
  {
    "question": "When should I contact you?",
    "answer": "Ideally before you publish, demo, pitch publicly or launch, because public disclosure can affect your rights. Early conversations are often the cheapest way to avoid problems."
  },
  {
    "question": "What should I bring to a first conversation?",
    "answer": "Just a description of the problem you solve and how your approach works. A rough diagram or draft deck helps. Keep very sensitive details high-level until we have agreed how to exchange confidential material."
  },
  {
    "question": "What is a provisional patent application?",
    "answer": "A US filing that secures an early date for what it describes. It is not examined and expires after twelve months, so a non-provisional filing is needed to continue."
  },
  {
    "question": "Is there an international patent?",
    "answer": "No. Patents are national or regional. The Paris Convention and the PCT help you plan, but fees and examination apply country by country."
  },
  {
    "question": "Do I need to live in the US to file a US patent?",
    "answer": "No. Anyone can apply regardless of nationality or residence, although your home country may require permission before you file abroad."
  },
  {
    "question": "What is the difference between a patentability search and a freedom-to-operate search?",
    "answer": "A patentability search focuses on what is unique in your invention. A freedom-to-operate search covers every feature and combination in the product you sell. Neither replaces the other."
  },
  {
    "question": "Can I infringe others' patents while selling my own patented product?",
    "answer": "Yes, potentially. A patent on one feature does not cover the rest of the product, which others may hold patents on."
  },
  {
    "question": "Which technologies do you work with?",
    "answer": "AI and machine learning, semiconductors and quantum, MedTech and biotechnology, climate and energy, and enterprise software and security."
  },
  {
    "question": "How do fees work?",
    "answer": "Scope and fees depend on the work. We discuss them openly before anything starts. This site does not list prices."
  },
  {
    "question": "How is my information handled?",
    "answer": "Details you send through our forms are used to respond to you. See the Privacy Policy for specifics."
  }
]
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
