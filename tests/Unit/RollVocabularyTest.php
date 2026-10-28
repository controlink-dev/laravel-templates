<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Output\EscPos\RollVocabulary;
use Controlink\Templates\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The receipt vocabulary, V-21 (CGE-97): a roll may only use the `esc-*` classes and the
 * few styles the editor writes, so the PDF is what the thermal printer prints. The cases
 * live in a fixture the editor's mirror (`tests/js/roll-vocabulary.test.mjs`) reads too.
 */
class RollVocabularyTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>}> */
    public static function cases(): array
    {
        $fixture = self::fixture();
        $cases   = [];

        foreach ($fixture['cases'] as $case) {
            $cases[$case['name']] = [$case];
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_the_shared_cases(array $case): void
    {
        $problems = RollVocabulary::problems($case['html'], $case['css'], $case['columns']);

        $this->assertSame(
            array_map(fn (array $expected) => [
                'code'         => 'V-21',
                'key'          => $expected['key'],
                'component_id' => $expected['component_id'],
                'subject'      => $expected['subject'],
            ], $case['expected']),
            array_map(fn (array $problem) => [
                'code'         => $problem['code'],
                'key'          => $problem['key'],
                'component_id' => $problem['component_id'],
                'subject'      => $problem['subject'],
            ], $problems),
        );
    }

    public function test_the_message_names_the_offending_selector_or_element(): void
    {
        [$problem] = RollVocabulary::problems('<div class="page"></div>', '.r-inline{display:flex}', 40);

        $this->assertSame(__('templates::editor.validation.roll_css', ['subject' => '.r-inline']), $problem['message']);
        $this->assertStringContainsString('.r-inline', $problem['message']);

        [$problem] = RollVocabulary::problems('<div class="page"><div data-tpl-cols="50" id="x"></div></div>', '', 40);

        $this->assertStringContainsString('50', $problem['message']);
        $this->assertStringContainsString('40', $problem['message']);
    }

    public function test_the_text_row_fragment_is_in_the_vocabulary(): void
    {
        $this->assertSame([], RollVocabulary::problems('<div class="page">'.self::fixture()['text_row'].'</div>', '', 40));
    }

    /** The allowed classes are exactly the `esc-*` classes print.css ships (Task 3). */
    public function test_the_allowed_classes_are_the_ones_print_css_ships(): void
    {
        preg_match_all('/\.(esc-[a-z0-9-]+)/', (string) file_get_contents(__DIR__.'/../../resources/css/print.css'), $matches);

        $shipped = array_values(array_unique($matches[1]));
        $allowed = RollVocabulary::CLASSES;
        sort($shipped);
        sort($allowed);

        $this->assertSame($shipped, $allowed);
    }

    /** @return array{text_row: string, cases: list<array<string, mixed>>} */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/roll-vocabulary-cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
