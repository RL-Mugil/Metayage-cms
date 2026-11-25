<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Claude connector (MCP): OAuth clients, one-time authorization codes and refresh tokens
 * (access tokens are Sanctum tokens with the mcp:read ability). Also adds the Integrations
 * cards for the AI Assistant keys (Claude API, Groq) and the connector on/off switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 80)->unique();
            $table->string('name', 200);
            $table->json('redirect_uris');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->string('client_id', 80)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('redirect_uri');
            $table->string('code_challenge', 128);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('client_id', 80)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('access_token_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('integrations')) {
            $now = now();
            DB::table('integrations')->insertOrIgnore([
                ['slug' => 'anthropic', 'name' => 'Claude API (AI Assistant)', 'description' => 'Powers the AI Assistant page with Claude, using read-only portal tools',
                    'category' => 'AI', 'initials' => 'CL', 'color' => 'bg-orange-600', 'connected' => false, 'created_at' => $now, 'updated_at' => $now],
                ['slug' => 'groq', 'name' => 'Groq (AI Assistant)', 'description' => 'Alternative AI Assistant model (Llama on Groq), same read-only portal tools',
                    'category' => 'AI', 'initials' => 'GQ', 'color' => 'bg-rose-600', 'connected' => false, 'created_at' => $now, 'updated_at' => $now],
                ['slug' => 'claude_mcp', 'name' => 'Claude connector (MCP)', 'description' => 'Lets staff connect claude.ai / Claude Desktop to the portal, read-only and limited to their own access',
                    'category' => 'AI', 'initials' => 'MC', 'color' => 'bg-amber-700', 'connected' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_auth_codes');
        Schema::dropIfExists('oauth_clients');
        if (Schema::hasTable('integrations')) {
            DB::table('integrations')->whereIn('slug', ['anthropic', 'groq', 'claude_mcp'])->delete();
        }
    }
};
