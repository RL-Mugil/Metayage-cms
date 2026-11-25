<?php

namespace App\Http\Controllers;

use App\Services\ClaudeFiles;
use Illuminate\Http\Request;

/** GET /files/claude/{token}: a file Claude produced (30-minute secret link, no sign-in). */
class ClaudeFileController extends Controller
{
    public function show(Request $request, string $token, ClaudeFiles $files)
    {
        return $files->serve($token, $request);
    }
}
