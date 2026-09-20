<?php

namespace App\Modules\OracleSync\Data;

use Carbon\CarbonImmutable;

final readonly class OracleLotRow
{
    /** Column limits from the contracts/lots/parties/qualities migrations; checked per row in the preview (M-6). */
    public const LIMITS = [
        'party_name' => 150, 'ref_no' => 60, 'quality_description' => 255, 'lot_no' => 10, 'design_code' => 20, 'design' => 60,
        'colour_code' => 20, 'colour' => 80, 'po_type' => 40,
    ];

    public function __construct(
        public string $partyName,
        public int $partyCode,
        public int $poNo,
        public ?string $refNo,
        public CarbonImmutable $poDate,
        public string $qualityDescription,
        public int $qualityCode,
        public string $lotNo,
        public int $oracleLineId,
        public ?int $gsm,
        public ?string $designCode,
        public ?string $design,
        public ?string $colourCode,
        public ?string $colour,
        public ?float $gWidth,
        public ?float $fWidth,
        public string $poType,
        public float $greigeQty,
        public float $contractQty,
    ) {}

    public function toArray(): array
    {
        return [
            'party_name' => $this->partyName, 'party_code' => $this->partyCode, 'po_no' => $this->poNo, 'ref_no' => $this->refNo,
            'po_date' => $this->poDate->toDateString(), 'quality_description' => $this->qualityDescription,
            'quality_code' => $this->qualityCode, 'lot_no' => $this->lotNo, 'oracle_line_id' => $this->oracleLineId,
            'gsm' => $this->gsm, 'design_code' => $this->designCode, 'design' => $this->design, 'colour_code' => $this->colourCode,
            'colour' => $this->colour, 'g_width' => $this->gWidth, 'f_width' => $this->fWidth, 'po_type' => $this->poType,
            'greige_qty' => $this->greigeQty, 'contract_qty' => $this->contractQty,
        ];
    }

    /** @return list<string> field names whose value exceeds its column limit */
    public function overlong(): array
    {
        $a = $this->toArray();

        return array_values(array_filter(array_keys(self::LIMITS), fn ($f) => mb_strlen((string) ($a[$f] ?? '')) > self::LIMITS[$f]));
    }

    public static function fromArray(array $a): self
    {
        return new self(
            $a['party_name'], (int) $a['party_code'], (int) $a['po_no'], $a['ref_no'], CarbonImmutable::parse($a['po_date']),
            $a['quality_description'], (int) $a['quality_code'], (string) $a['lot_no'], (int) $a['oracle_line_id'],
            $a['gsm'] === null ? null : (int) $a['gsm'], $a['design_code'], $a['design'], $a['colour_code'], $a['colour'],
            $a['g_width'] === null ? null : (float) $a['g_width'], $a['f_width'] === null ? null : (float) $a['f_width'],
            $a['po_type'], (float) $a['greige_qty'], (float) $a['contract_qty'],
        );
    }
}
