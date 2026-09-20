<?php

namespace App\Modules\Production\Ledger;

use InvalidArgumentException;
use Stringable;

/**
 * Where metres of a lot currently sit (spec §5.3 "Positions"):
 * STOCK, ISSUED, STEP:<lot_route_step_id>, FINISHED, DISPATCHED, LOSS.
 */
final readonly class Position implements Stringable
{
    public const STOCK = 'STOCK';

    public const ISSUED = 'ISSUED';

    public const FINISHED = 'FINISHED';

    public const DISPATCHED = 'DISPATCHED';

    public const LOSS = 'LOSS';

    private const STEP_PREFIX = 'STEP:';

    /** @var list<string> */
    private const FIXED = [self::STOCK, self::ISSUED, self::FINISHED, self::DISPATCHED, self::LOSS];

    private function __construct(private string $value) {}

    public static function stock(): self
    {
        return new self(self::STOCK);
    }

    public static function issued(): self
    {
        return new self(self::ISSUED);
    }

    public static function finished(): self
    {
        return new self(self::FINISHED);
    }

    public static function dispatched(): self
    {
        return new self(self::DISPATCHED);
    }

    public static function loss(): self
    {
        return new self(self::LOSS);
    }

    public static function step(int $routeStepId): self
    {
        if ($routeStepId <= 0) {
            throw new InvalidArgumentException('Route step id must be positive.');
        }

        return new self(self::STEP_PREFIX.$routeStepId);
    }

    public static function from(string $value): self
    {
        if (in_array($value, self::FIXED, true)) {
            return new self($value);
        }
        if (str_starts_with($value, self::STEP_PREFIX) && ctype_digit(substr($value, strlen(self::STEP_PREFIX)))) {
            return self::step((int) substr($value, strlen(self::STEP_PREFIX)));
        }

        throw new InvalidArgumentException("Unknown position '{$value}'.");
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isStep(): bool
    {
        return str_starts_with($this->value, self::STEP_PREFIX);
    }

    public function stepId(): ?int
    {
        return $this->isStep() ? (int) substr($this->value, strlen(self::STEP_PREFIX)) : null;
    }

    /** DISPATCHED and LOSS are counters that never feed another position. */
    public function isTerminal(): bool
    {
        return in_array($this->value, [self::DISPATCHED, self::LOSS], true);
    }

    /** ISSUED and STEP:* make up WIP (spec §5.3 "Derived lot values"). */
    public function isWip(): bool
    {
        return $this->value === self::ISSUED || $this->isStep();
    }

    /** Translation key suffix under production.positions (steps share one label). */
    public function labelKey(): string
    {
        return $this->isStep() ? 'step' : strtolower($this->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
