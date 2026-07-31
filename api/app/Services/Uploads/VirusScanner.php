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

    /**
     * Cheap liveness probe for the fail-closed edge (S04E D-4). True only when
     * the scanner is reachable and answering. Never throws — a probe that
     * cannot confirm the scanner returns false, so the caller refuses intake
     * (503) rather than accepting a file it cannot scan.
     */
    public function isAvailable(): bool;
}
