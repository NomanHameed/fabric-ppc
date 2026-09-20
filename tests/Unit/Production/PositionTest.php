<?php

use App\Modules\Production\Ledger\Position;

it('round-trips every position kind', function () {
    foreach (['STOCK', 'ISSUED', 'FINISHED', 'DISPATCHED', 'LOSS', 'STEP:42'] as $v) {
        expect(Position::from($v)->value())->toBe($v)
            ->and((string) Position::from($v))->toBe($v);
    }
    expect(Position::step(7)->stepId())->toBe(7)
        ->and(Position::stock()->stepId())->toBeNull()
        ->and(Position::issued()->isWip())->toBeTrue()
        ->and(Position::step(3)->isWip())->toBeTrue()
        ->and(Position::finished()->isWip())->toBeFalse()
        ->and(Position::stock()->isWip())->toBeFalse()
        ->and(Position::dispatched()->isTerminal())->toBeTrue()
        ->and(Position::loss()->isTerminal())->toBeTrue()
        ->and(Position::finished()->isTerminal())->toBeFalse()
        ->and(Position::step(3)->equals(Position::from('STEP:3')))->toBeTrue()
        ->and(Position::step(3)->equals(Position::step(4)))->toBeFalse();
});

it('maps positions to their label keys', function () {
    expect(Position::stock()->labelKey())->toBe('stock')
        ->and(Position::step(9)->labelKey())->toBe('step')
        ->and(Position::dispatched()->labelKey())->toBe('dispatched');
});

it('rejects unknown positions', function () {
    expect(fn () => Position::from('STEP:x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Position::from('STEP:'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Position::from('STEP:0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Position::from('stock'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Position::from('WIP'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Position::step(0))->toThrow(InvalidArgumentException::class);
});
