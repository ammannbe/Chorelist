<?php

namespace Tests\Unit;

use App\Models\Completion;
use App\Support\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Same cases as tests/js/schedule.test.js: client and server must agree.
 */
class ScheduleTest extends TestCase
{
    private static function fixtures(string $key): array
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/../fixtures/schedule.json'), true);

        return collect($fixtures[$key])->mapWithKeys(fn (array $case) => [$case['name'] => [$case]])->all();
    }

    public static function stateCases(): array
    {
        return self::fixtures('state');
    }

    public static function occurrenceCases(): array
    {
        return self::fixtures('occurrences');
    }

    public static function missedCases(): array
    {
        return self::fixtures('missed');
    }

    #[DataProvider('stateCases')]
    public function test_state(array $case): void
    {
        $state = Schedule::state($case['task'], $case['completions'], $case['today']);

        foreach ($case['expect'] as $key => $value) {
            $this->assertSame($value, $state[$key], $key);
        }
    }

    #[DataProvider('occurrenceCases')]
    public function test_occurrences(array $case): void
    {
        $this->assertSame($case['expect'], Schedule::occurrences($case['task'], $case['from'], $case['to']));
    }

    #[DataProvider('missedCases')]
    public function test_missed(array $case): void
    {
        $this->assertSame($case['expect'], Schedule::missed($case['task'], $case['completions'], $case['from'], $case['today']));
    }

    public function test_rules_are_validated(): void
    {
        $this->assertTrue(Schedule::validRule('FREQ=MONTHLY;BYDAY=4MO'));
        $this->assertTrue(Schedule::validRule('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH'));
        $this->assertFalse(Schedule::validRule('FREQ=SOMETIMES'));
        $this->assertFalse(Schedule::validRule('BYDAY=MO'));
        $this->assertFalse(Schedule::validRule('FREQ=DAILY;COUNT=3'));
        $this->assertFalse(Schedule::validRule(null));
    }

    public function test_occurrence_ids_are_the_same_as_on_the_client(): void
    {
        // tests/js/schedule.test.js
        $this->assertSame('f9cc1f2b-92f9-5ce1-9383-17eee3b7f125', Completion::occurrenceId('task', '2026-11-02'));
    }
}
