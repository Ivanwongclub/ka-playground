<?php

namespace App\Services\Uploads;

interface VirusScanner
{
    /**
     * Scan raw file contents. Returns null when clean, or the detected
     * signature name on a hit. Throws on scanner unavailability — an
     * unscannable file must never become visible.
     */
    public function scan(string $contents): ?string;
}
