<?php

namespace App\Modules\Production\Timeline;

use Carbon\CarbonInterface;

/**
 * One entry on a lot's timeline. Built from whichever record already owns the fact (a ledger movement, an
 * audit row, a colour override, a recorded user action) — never stored, so it cannot drift from its source.
 */
final readonly class TimelineEvent
{
    /**
     * @param  string  $id  stable within one lot, e.g. "movement:412"
     * @param  string  $type  a TimelineEventType constant
     * @param  array<string, scalar|null>  $context  replacements for the title line
     */
    public function __construct(
        public string $id,
        public string $type,
        public CarbonInterface $occurredAt,
        public ?string $from = null,
        public ?string $to = null,
        public ?float $qty = null,
        public ?int $actorId = null,
        public ?string $actorName = null,
        public ?string $note = null,
        public array $context = [],
    ) {}

    /** The sentence a user reads, in the current locale. */
    public function title(): string
    {
        $system = __('timeline.system');

        /** @var array<string, bool|float|int|string|null> $replace */
        $replace = $this->context + [
            'from' => $this->from ?? '—',
            'to' => $this->to ?? '—',
            'qty' => $this->qty === null ? '' : rtrim(rtrim(number_format($this->qty, 2, '.', ''), '0'), '.'),
            'user' => $this->actorName ?? (is_string($system) ? $system : 'system'),
        ];

        $title = __('timeline.events.'.$this->type, $replace);

        return is_string($title) ? $title : $this->type;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title(),
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'from' => $this->from,
            'to' => $this->to,
            'qty' => $this->qty,
            'actor_id' => $this->actorId,
            'actor' => $this->actorName,
            'note' => $this->note,
        ];
    }
}
