<?php

namespace App\Services\Enrolment;

use App\Models\EnrolmentBatch;
use App\Models\EnrolmentBatchRow;
use App\Services\Audit\AuditService;
use App\Services\Authz\ScopeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S04E STEP 1 — parse a scan-clean roll CSV into per-row dispositions. DRY RUN:
 * writes enrolment_batch_rows only; creates NO account and NO enrolment (that is
 * STEP 2 commit). Native reader, no new dependency (D-6).
 *
 * Defined failure mode, extended one layer above create():
 *   - STRUCTURAL defect  → StructuralParseException → whole-file reject, batch
 *     Failed, ZERO rows written. Never a partial import.
 *   - ROW defect         → that row is skipped/failed WITH a reason; the rest
 *     proceed. Every non-validated row carries its reason (P4).
 *
 * Formula/CSV injection is neutralised on EVERY cell: a value beginning
 * = + - @ TAB or CR fails that row (and is escaped again on any later export).
 *
 * Disposition (dry run — no writes to users):
 *   - an account with the email already on THIS school's active roll → match_existing
 *   - an account with the email that is NOT on this roll             → skipped (reason)
 *   - no account with the email                                      → new (→ create() at commit)
 */
class EnrolmentBatchCsvParser
{
    private const REQUIRED_COLUMNS = ['name', 'email'];

    public function __construct(
        private readonly ScopeContext $scope,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{total:int,new:int,existing:int,skipped:int,failed:int}
     */
    public function parse(EnrolmentBatch $batch, string $csv): array
    {
        return $this->scope->asSystem(
            'S04E STEP 1 CSV parse (Spec Part H / OD-25): writes enrolment_batch_rows dispositions for a scan-clean roll. The batch and its rows are outside any single actor\'s derived scope (they are a school-wide operation) and the tables are system-write by construction; disposition reads users/school_links across the school roll. DRY RUN — creates no account and no enrolment.',
            fn (): array => $this->parseInSystem($batch, $csv),
        );
    }

    private function parseInSystem(EnrolmentBatch $batch, string $csv): array
    {
        $rows = $this->readStructure($csv); // throws StructuralParseException on a whole-file defect

        $counts = ['total' => 0, 'new' => 0, 'existing' => 0, 'skipped' => 0, 'failed' => 0];
        $seenEmails = [];

        foreach ($rows as $i => $row) {
            $counts['total']++;
            $rowNo = $i + 1;
            [$status, $disposition, $reason, $matchedId] = $this->dispositionFor($row, $batch, $seenEmails);

            match ($status) {
                EnrolmentBatchRow::STATUS_VALIDATED => $disposition === EnrolmentBatchRow::DISPOSITION_NEW
                    ? $counts['new']++ : $counts['existing']++,
                EnrolmentBatchRow::STATUS_SKIPPED => $counts['skipped']++,
                default => $counts['failed']++,
            };

            DB::table('enrolment_batch_rows')->insert([
                'id' => (string) Str::uuid7(),
                'batch_id' => $batch->id,
                'school_id' => $batch->school_id,
                'row_number' => $rowNo,
                'name' => $row['name'] ?? null,
                'email' => $row['email'] ?? null,
                'status' => $status,
                'disposition' => $disposition,
                'reason' => $reason,
                'matched_user_id' => $matchedId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $counts;
    }

    /**
     * Structural validation → an ordered list of associative rows, or throw.
     *
     * @return list<array<string,string>>
     */
    private function readStructure(string $csv): array
    {
        // Encoding: strip a UTF-8 BOM, require valid UTF-8, reject NUL.
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        if (! mb_check_encoding($csv, 'UTF-8')) {
            throw new StructuralParseException('file is not valid UTF-8');
        }
        if (str_contains($csv, "\0")) {
            throw new StructuralParseException('file contains NUL bytes');
        }

        $lines = preg_split('/\r\n|\r|\n/', rtrim($csv, "\r\n"));
        if ($lines === false || $lines === [] || ($lines[0] ?? '') === '') {
            throw new StructuralParseException('file is empty');
        }

        $header = array_map(
            fn ($h) => strtolower(trim($h)),
            str_getcsv($lines[0], ',', '"', '\\'),
        );
        foreach (self::REQUIRED_COLUMNS as $col) {
            if (! in_array($col, $header, true)) {
                throw new StructuralParseException(
                    'missing required column "'.$col.'" (expected: '.implode(', ', self::REQUIRED_COLUMNS).')'
                );
            }
        }

        $dataLines = array_slice($lines, 1);
        $dataLines = array_values(array_filter($dataLines, fn ($l) => trim($l) !== ''));
        if ($dataLines === []) {
            throw new StructuralParseException('file has a header but no data rows');
        }
        $cap = (int) config('uploads.batch_csv_max_rows');
        if (count($dataLines) > $cap) {
            throw new StructuralParseException("file exceeds the {$cap}-row limit for a batch");
        }

        $out = [];
        foreach ($dataLines as $line) {
            $values = str_getcsv($line, ',', '"', '\\');
            $assoc = [];
            foreach ($header as $idx => $col) {
                $assoc[$col] = $values[$idx] ?? '';
            }
            $out[] = $assoc;
        }

        return $out;
    }

    /**
     * @param  array<string,string>  $row
     * @param  array<string,true>  $seenEmails  (by reference — intra-file dedup)
     * @return array{0:string,1:?string,2:?string,3:?int}  [status, disposition, reason, matchedUserId]
     */
    private function dispositionFor(array $row, EnrolmentBatch $batch, array &$seenEmails): array
    {
        // Formula/CSV injection — checked on EVERY cell.
        foreach ($row as $value) {
            if ($this->isFormulaLike($value)) {
                return [EnrolmentBatchRow::STATUS_FAILED, null, 'formula-injection cell rejected (=+-@/tab/CR)', null];
            }
        }

        $name = trim((string) ($row['name'] ?? ''));
        $email = strtolower(trim((string) ($row['email'] ?? '')));

        if ($name === '') {
            return [EnrolmentBatchRow::STATUS_FAILED, null, 'missing name', null];
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [EnrolmentBatchRow::STATUS_FAILED, null, 'invalid or missing email', null];
        }
        if (isset($seenEmails[$email])) {
            return [EnrolmentBatchRow::STATUS_SKIPPED, null, 'duplicate email within this file', null];
        }
        $seenEmails[$email] = true;

        $existing = DB::table('users')->where('email', $email)->first(['id']);
        if ($existing === null) {
            return [EnrolmentBatchRow::STATUS_VALIDATED, EnrolmentBatchRow::DISPOSITION_NEW, null, null];
        }
        $onRoll = DB::table('school_links')
            ->where('student_id', $existing->id)
            ->where('school_id', $batch->school_id)
            ->where('status', 'active')
            ->exists();
        if ($onRoll) {
            return [EnrolmentBatchRow::STATUS_VALIDATED, EnrolmentBatchRow::DISPOSITION_EXISTING, null, (int) $existing->id];
        }

        return [EnrolmentBatchRow::STATUS_SKIPPED, null, 'an account with this email exists but is not on this school roll', null];
    }

    private function isFormulaLike(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $first = $value[0];

        return in_array($first, ['=', '+', '-', '@', "\t", "\r"], true);
    }
}
