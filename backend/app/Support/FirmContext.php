<?php

namespace App\Support;

use App\Models\Firm;
use LogicException;

class FirmContext
{
    private ?Firm $firm = null;

    public function set(Firm $firm): void
    {
        $this->firm = $firm;
    }

    public function clear(): void
    {
        $this->firm = null;
    }

    public function firm(): ?Firm
    {
        return $this->firm;
    }

    public function id(): ?int
    {
        return $this->firm?->getKey();
    }

    public function hasFirm(): bool
    {
        return $this->firm !== null;
    }

    public function idOrSingleActiveFirm(): int
    {
        if ($this->id() !== null) {
            return $this->id();
        }

        $firmIds = Firm::active()->limit(2)->pluck('id');
        if ($firmIds->count() !== 1) {
            throw new LogicException('A firm context is required when more than one active firm exists.');
        }

        return (int) $firmIds->first();
    }

    public function run(Firm $firm, callable $callback): mixed
    {
        $previous = $this->firm;
        $this->firm = $firm;

        try {
            return $callback();
        } finally {
            $this->firm = $previous;
        }
    }
}
