<?php

use App\Modules\Production\Status\StatusStageResolver;

/*
 * The status -> stage rules, with no database and no Eloquent: the resolver takes its alias rows from a
 * closure, so these are the rules themselves rather than the seeded data (that is covered by
 * tests/Feature/Production/StageResolutionTest.php).
 */

/** The seed's own shape, trimmed to the cases that matter here. */
function stageAliasRows(): array
{
    return [
        ['stage_id' => 1, 'stage_process_id' => 11, 'aliases' => ['BLEACHING']],
        ['stage_id' => 2, 'stage_process_id' => 21, 'aliases' => ['DYEING']],
        ['stage_id' => 4, 'stage_process_id' => 41, 'aliases' => ['SANFO/CAL']],
        ['stage_id' => 4, 'stage_process_id' => 42, 'aliases' => ['SANFO/CAL RP']],
        ['stage_id' => 8, 'stage_process_id' => 81, 'aliases' => ['ON.AAP', 'SB.ON APP']],
        ['stage_id' => 14, 'stage_process_id' => 141, 'aliases' => ['DISPATCHED', 'RF-DISP', 'DISP *']],
        ['stage_id' => 15, 'stage_process_id' => 151, 'aliases' => ['*CANCEL*']],
        // A blank alias is master-data noise and must claim nothing at all.
        ['stage_id' => 15, 'stage_process_id' => 152, 'aliases' => ['CLOSED', '  ']],
    ];
}

function stageResolver(?array $rows = null): StatusStageResolver
{
    $rows ??= stageAliasRows();

    return new StatusStageResolver(fn (): array => $rows);
}

it('normalises spaces, case and a trailing slash, and keeps a slash inside', function (string $raw, ?string $expected) {
    expect(StatusStageResolver::normalise($raw))->toBe($expected);
})->with([
    ['DYEING', 'DYEING'],
    ['dyeing', 'DYEING'],
    ['DYEING/', 'DYEING'],
    ['DYEING /', 'DYEING'],
    ['  dyeing  /  ', 'DYEING'],
    ['SANFO/CAL', 'SANFO/CAL'],
    ['SANFO  /  CAL', 'SANFO / CAL'],     // whitespace is collapsed, never removed
    ['SANFO/CAL RP/', 'SANFO/CAL RP'],
    ['DISP 11-09-26/', 'DISP 11-09-26'],
    ["RF-INSP/\n", 'RF-INSP'],
    ['', null],
    ['   ', null],
    ['/', null],
    [' / / ', null],
]);

it('normalises a null status to null', function () {
    expect(StatusStageResolver::normalise(null))->toBeNull();
});

it('resolves the same process however the status is written', function (string $raw) {
    expect(stageResolver()->resolve($raw))->toBe(['stage_id' => 2, 'stage_process_id' => 21]);
})->with(['DYEING', 'dyeing', 'DYEING/', 'DYEING /', ' Dyeing / ']);

it('resolves the dispatch family by pattern and anything cancelled by substring', function (string $raw, int $stage, int $process) {
    expect(stageResolver()->resolve($raw))->toBe(['stage_id' => $stage, 'stage_process_id' => $process]);
})->with([
    ['DISP 11-09-26/', 14, 141],
    ['DISP 1-1-26', 14, 141],
    ['DISPATCHED/', 14, 141],          // an exact alias, not the DISP * pattern
    ['RF-DISP', 14, 141],
    ['ANYTHING CANCEL', 15, 151],
    ['CANCELLED/', 15, 151],
    ['cancel', 15, 151],
    ['SB.ON APP', 8, 81],
    ['SANFO/CAL', 4, 41],
    ['SANFO/CAL RP/', 4, 42],          // the longer exact alias, not the shorter one
]);

it('leaves an unknown status, a blank one and a null one unresolved', function (?string $raw) {
    expect(stageResolver()->resolve($raw))->toBe(['stage_id' => null, 'stage_process_id' => null]);
})->with([
    'unknown' => ['SOMETHING NOBODY MAPPED'],
    'empty' => [''],
    'slash only' => ['/'],
    'null' => [null],
    // A blank alias on a process claims nothing, so a blank status does not land on it.
    'spaces' => ['   '],
]);

it('says whether a status is mapped', function () {
    $resolver = stageResolver();

    expect($resolver->isMapped('DYEING/'))->toBeTrue()
        ->and($resolver->isMapped('NOT A STATUS'))->toBeFalse()
        // Nothing to map is not the same as a mapping gap.
        ->and($resolver->isMapped(null))->toBeFalse()
        ->and($resolver->isMapped(''))->toBeFalse();
});

it('prefers the more specific wildcard when two could match', function () {
    $resolver = stageResolver([
        ['stage_id' => 1, 'stage_process_id' => 1, 'aliases' => ['*']],
        ['stage_id' => 2, 'stage_process_id' => 2, 'aliases' => ['DISP *']],
    ]);

    expect($resolver->resolve('DISP 11-09-26/'))->toBe(['stage_id' => 2, 'stage_process_id' => 2])
        ->and($resolver->resolve('WHATEVER'))->toBe(['stage_id' => 1, 'stage_process_id' => 1]);
});

it('gives an alias claimed twice to the process that comes first', function () {
    $resolver = stageResolver([
        ['stage_id' => 1, 'stage_process_id' => 1, 'aliases' => ['DYEING']],
        ['stage_id' => 2, 'stage_process_id' => 2, 'aliases' => ['DYEING']],
    ]);

    expect($resolver->resolve('DYEING'))->toBe(['stage_id' => 1, 'stage_process_id' => 1]);
});

it('anchors a wildcard alias instead of matching anywhere', function () {
    $resolver = stageResolver([['stage_id' => 1, 'stage_process_id' => 1, 'aliases' => ['DISP *']]]);

    expect($resolver->resolve('REDISP 11-09-26'))->toBe(['stage_id' => null, 'stage_process_id' => null])
        ->and($resolver->resolve('DISP 11-09-26 EXTRA'))->toBe(['stage_id' => 1, 'stage_process_id' => 1]);
});

it('reads the aliases once and again after a flush', function () {
    $calls = 0;
    $resolver = new StatusStageResolver(function () use (&$calls): array {
        $calls++;

        return $calls === 1
            ? [['stage_id' => 1, 'stage_process_id' => 1, 'aliases' => ['DYEING']]]
            : [['stage_id' => 9, 'stage_process_id' => 9, 'aliases' => ['DYEING']]];
    });

    $resolver->resolve('DYEING');
    $resolver->resolve('DYEING');
    expect($calls)->toBe(1); // memoised: a backfill over every lot is one read, not one per lot

    $resolver->flush();
    expect($resolver->resolve('DYEING'))->toBe(['stage_id' => 9, 'stage_process_id' => 9])
        ->and($calls)->toBe(2);
});
