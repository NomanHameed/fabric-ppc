<?php

namespace App\Modules\OracleSync\Sources;

use App\Modules\OracleSync\Contracts\OracleLotSource;
use App\Modules\OracleSync\Data\OracleLotRow;
use App\Modules\OracleSync\Data\OracleRowError;
use App\Modules\OracleSync\Exceptions\InvalidOracleFile;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

final class ExcelUploadSource implements OracleLotSource
{
    public const REQUIRED = [
        'PARTY', 'PARTY_CODE', 'CONTRACT_NUM', 'REF_NUM', 'DATED', 'QUALITY', 'Q_CODE', 'LOT_NUM1', 'GSM', 'DESIGN_CODE',
        'DESIGN', 'COLOUR_NAME', 'COLOUR_CODE', 'G_WIDTH', 'F_WIDTH', 'PROCESS_TYPE', 'PC_DTL_ID', 'QNTY', 'QNTY_CONT',
    ];

    public function __construct(private readonly string $path, private readonly string $originalName = '') {}

    public function label(): string
    {
        return $this->originalName !== '' ? $this->originalName : basename($this->path);
    }

    public function rows(): iterable
    {
        $reader = new Reader;
        $opened = false;
        try {
            $reader->open($this->path);
            $opened = true;
            foreach ($reader->getSheetIterator() as $sheet) {
                $map = null;
                $rowNo = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $rowNo++;
                    $cells = $row->toArray();
                    if ($map === null) {
                        $map = $this->headerMap($cells);

                        continue;
                    }
                    if ($this->isBlank($cells)) {
                        continue;
                    }
                    yield $rowNo => $this->toRow($rowNo, fn (string $h) => $cells[$map[$h]] ?? null);
                }
                break; // first sheet only
            }
        } catch (InvalidOracleFile $e) {
            throw $e;
        } catch (Throwable $e) {
            throw InvalidOracleFile::unreadable($e);
        } finally {
            if ($opened) {
                $reader->close();
            }
        }
    }

    /** @return array<string, int> */
    private function headerMap(array $cells): array
    {
        $map = [];
        foreach ($cells as $i => $h) {
            $map[strtoupper(trim((string) $h))] = $i;
        }
        $missing = array_values(array_diff(self::REQUIRED, array_keys($map)));
        if ($missing !== []) {
            throw InvalidOracleFile::missingHeaders($missing);
        }

        return $map;
    }

    private function isBlank(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== null && trim((string) ($c instanceof DateTimeInterface ? 'x' : $c)) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * A row whose values cannot be parsed becomes an OracleRowError so the preview records it instead of aborting (M-6).
     *
     * @param  callable(string): mixed  $v
     */
    private function toRow(int $rowNo, callable $v): OracleLotRow|OracleRowError
    {
        try {
            return new OracleLotRow(
                partyName: trim((string) $v('PARTY')),
                partyCode: $this->int($v('PARTY_CODE'), 'PARTY_CODE'),
                poNo: $this->int($v('CONTRACT_NUM'), 'CONTRACT_NUM'),
                refNo: $this->str($v('REF_NUM')),
                poDate: $this->date($v('DATED')),
                qualityDescription: trim((string) $v('QUALITY')),
                qualityCode: $this->int($v('Q_CODE'), 'Q_CODE'),
                lotNo: (string) $this->int($v('LOT_NUM1'), 'LOT_NUM1'),
                oracleLineId: $this->int($v('PC_DTL_ID'), 'PC_DTL_ID'),
                gsm: $this->nullableInt($v('GSM')),
                designCode: $this->str($v('DESIGN_CODE')),
                design: $this->str($v('DESIGN')),
                colourCode: $this->str($v('COLOUR_CODE')),
                colour: $this->str($v('COLOUR_NAME')),
                gWidth: $this->nullableFloat($v('G_WIDTH')),
                fWidth: $this->nullableFloat($v('F_WIDTH')),
                poType: trim((string) $v('PROCESS_TYPE')),
                greigeQty: $this->float($v('QNTY'), 'QNTY'),
                contractQty: $this->float($v('QNTY_CONT'), 'QNTY_CONT'),
            );
        } catch (Throwable $e) {
            $line = trim((string) $v('PC_DTL_ID'));

            // oracle_line_id is an unsigned INT: anything else (negative, float, exponent, too large) is reported without a line id.
            return new OracleRowError($rowNo, ctype_digit($line) && (int) $line <= 4294967295 ? (int) $line : null, $e->getMessage());
        }
    }

    private function str(mixed $value): ?string
    {
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    private function int(mixed $value, string $field): int
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException(__('oracle.errors.not_a_number', ['field' => $field]));
        }

        return (int) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function float(mixed $value, string $field): float
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException(__('oracle.errors.not_a_number', ['field' => $field]));
        }

        return (float) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function date(mixed $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }
        $s = ucfirst(strtolower(trim((string) $value))); // "20-AUG-26" -> "20-aug-26"; month names parse case-insensitively
        try {
            $date = CarbonImmutable::createFromFormat('!d-M-y', $s);
        } catch (Throwable) {
            $date = null; // Carbon throws on e.g. an unknown month name: same reason as a non-round-tripping date
        }
        if ($date === null || strtolower($date->format('d-M-y')) !== strtolower($s)) {
            throw new \InvalidArgumentException(__('oracle.errors.bad_date', ['field' => 'DATED', 'value' => (string) $value]));
        }

        return $date;
    }
}
