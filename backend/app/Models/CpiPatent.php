<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CpiPatent extends Model
{
    public const COUNTRIES = ['India', 'USA', 'Others'];

    public const MAX_FIGURES = 12;

    /** Suggested domains (the admin may also type a new one). */
    public const DOMAINS = [
        'Artificial Intelligence', 'Telecommunication', 'Electric Vehicle', 'Healthcare', 'Cryptography', '3D / AR / VR', 'Blockchain',
        'Manufacturing', 'Business Intelligence', 'Network Management', 'Food and Beverages', 'Aerospace Engineering',
        'Bio-Medical Engineering', 'Polymer Technology',
    ];

    protected $fillable = [
        'patent_number', 'slug', 'title', 'domain', 'country', 'abstract', 'image', 'image_alt', 'figures', 'link_url', 'link_disabled',
        'status', 'published_at',
    ];

    protected $casts = ['published_at' => 'datetime', 'figures' => 'array', 'link_disabled' => 'boolean'];

    /** The figures to show, oldest design first; falls back to the single legacy image. */
    public function figureList(): array
    {
        $list = collect($this->figures ?? [])->filter(fn ($f) => ! empty($f['url']))->values()->all();
        if (! $list && $this->image) {
            $list = [['url' => $this->image, 'alt' => $this->image_alt]];
        }

        return $list;
    }

    /** Publication number in the form Google Patents expects: no spaces, commas, slashes, hyphens or dots, upper case. */
    public static function normalizeNumber(string $number): string
    {
        return strtoupper(preg_replace('/[\s,\/\-.]+/', '', trim($number)));
    }

    /**
     * Automatic link to the patent on Google Patents. Google resolves a normalised number with or without a
     * kind code (WO2020136668 and WO2020136668A1 both work). Demo IDs and anything that does not look like a
     * publication number get no automatic link.
     */
    public function googlePatentsUrl(): ?string
    {
        $n = self::normalizeNumber((string) $this->patent_number);
        if ($n === '' || str_starts_with($n, 'SAMPLE') || ! preg_match('/^[A-Z]{2}[A-Z0-9]{4,}$/', $n) || ! preg_match('/\d/', $n)) {
            return null;
        }

        return 'https://patents.google.com/patent/'.$n.'/en';
    }

    /** The link the Patent ID points to: the admin's override, else the automatic Google Patents link, else none. */
    public function linkUrl(): ?string
    {
        if ($this->link_disabled) {
            return null;
        }

        return $this->link_url ?: $this->googlePatentsUrl();
    }
}
