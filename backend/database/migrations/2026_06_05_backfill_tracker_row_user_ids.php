<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\TrackerRow;
use App\Models\User;

return new class extends Migration
{
    public function up(): void
    {
        $rows = TrackerRow::all();

        foreach ($rows as $row) {
            // Match PCM
            if ($row->pcm) {
                $row->pcm_user_id = $this->resolveUserId($row->pcm);
            }

            // Match SCM
            if ($row->scm) {
                $row->scm_user_id = $this->resolveUserId($row->scm);
            }

            // Match PR
            if ($row->pr) {
                $row->pr_user_id = $this->resolveUserId($row->pr);
            }

            $row->save();
        }
    }

    public function down(): void
    {
        TrackerRow::query()->update([
            'pcm_user_id' => null,
            'scm_user_id' => null,
            'pr_user_id' => null,
        ]);
    }

    private function resolveUserId(string $name): ?int
    {
        $first = strtolower(explode(' ', trim($name))[0]);
        $user = User::whereRaw('LOWER(name) LIKE ?', ["%{$first}%"])->first();
        return $user?->id;
    }
};
