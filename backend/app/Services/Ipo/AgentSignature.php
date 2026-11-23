<?php

namespace App\Services\Ipo;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The signing agent's block at the end of an IPO form: "Dated this 23rd day of
 * November 2026" (IST, at generation) and the agent's stored signature image.
 * Signature images are kept on the private disk and never served publicly.
 */
class AgentSignature
{
    public const DISK = 'local';

    public const DIRECTORY = 'patent-agent-signatures';

    public const MAX_KB = 2048;

    /** "Dated this 23rd day of November 2026", in India Standard Time. */
    public static function datedLine(?CarbonInterface $at = null): string
    {
        $ist = ($at ? Carbon::instance($at) : Carbon::now())->copy()->setTimezone('Asia/Kolkata');

        return 'Dated this '.$ist->format('jS').' day of '.$ist->format('F Y');
    }

    /**
     * The image for a picked agent (Form1DataBuilder agent row carrying signature_path).
     *
     * @return array{bytes: string, ext: string, width: int, height: int}|null
     */
    public static function imageFor(?array $agent): ?array
    {
        $path = $agent['signature_path'] ?? null;
        if (blank($path) || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }
        $bytes = (string) Storage::disk(self::DISK)->get($path);
        $size = @getimagesizefromstring($bytes);
        if (! $size || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return null;
        }

        return [
            'bytes'  => $bytes,
            'ext'    => $size[2] === IMAGETYPE_PNG ? 'png' : 'jpeg',
            'width'  => (int) $size[0],
            'height' => (int) $size[1],
        ];
    }

    /** Stores a new signature for the agent's login, replacing (and deleting) any old one. */
    public static function store(User $user, UploadedFile $file): string
    {
        $ext = $file->guessExtension() === 'png' ? 'png' : 'jpg';
        $path = $file->storeAs(self::DIRECTORY, $user->id.'-'.Str::random(16).'.'.$ext, self::DISK);
        $old = $user->patent_agent_signature_path;
        $user->forceFill(['patent_agent_signature_path' => $path])->save();
        if ($old && $old !== $path) {
            Storage::disk(self::DISK)->delete($old);
        }

        return $path;
    }

    public static function remove(User $user): void
    {
        $old = $user->patent_agent_signature_path;
        if (! $old) {
            return;
        }
        $user->forceFill(['patent_agent_signature_path' => null])->save();
        Storage::disk(self::DISK)->delete($old);
    }
}
