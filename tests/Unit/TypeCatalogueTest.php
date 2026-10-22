<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Templates;
use Controlink\Templates\Types\InvalidTemplateType;
use Controlink\Templates\Types\TemplateType;
use Controlink\Templates\Types\TypeCatalogue;
use Controlink\Templates\Tests\TestCase;

class TypeCatalogueTest extends TestCase
{
    private function invoice(): array
    {
        return (require __DIR__.'/../Fixtures/types/invoice.php')['invoice'];
    }

    public function test_the_example_type_is_valid_and_reachable_through_the_facade(): void
    {
        $type = Templates::type('invoice');

        $this->assertSame('pdf', $type->output);
        $this->assertTrue($type->seeded);
        $this->assertSame(15.0, $type->pdf['margins']['top']);
        $this->assertSame(60, $type->fixtureRepeats);
        $this->assertNotNull($type->binding('documento.atcud'));
        $this->assertSame([], (new TypeCatalogue(['invoice' => $this->invoice()]))->creatable());
    }

    public function test_an_unknown_code_throws(): void
    {
        $this->expectException(InvalidTemplateType::class);
        Templates::type('nope');
    }

    /** @dataProvider brokenTypes */
    public function test_a_broken_type_is_refused_at_validation_with_the_type_and_reason_named(callable $break, string $reason): void
    {
        $type = $this->invoice();
        $break($type);

        $this->expectException(InvalidTemplateType::class);
        $this->expectExceptionMessageMatches('/invoice.*'.preg_quote($reason, '/').'/s');

        (new TypeCatalogue(['invoice' => $type]))->validate();
    }

    public static function brokenTypes(): array
    {
        return [
            'pdf output without pdf block' => [fn (array &$t) => $t['pdf'] = null, 'pdf'],
            'unknown output'               => [fn (array &$t) => $t['output'] = 'fax', 'output'],
            'legal but not mandatory'      => [function (array &$t) { $t['bindings'][0]['mandatory'] = false; }, 'legal'],
            'scope on a non-legal field'   => [function (array &$t) { $t['bindings'][0]['legal'] = false; $t['bindings'][0]['scope'] = 'any_page'; }, 'scope'],
            'unknown scope'                => [function (array &$t) { $t['bindings'][0]['scope'] = 'sometimes'; }, 'scope'],
            'unknown binding type'         => [function (array &$t) { $t['bindings'][0]['type'] = 'texto'; }, 'type'],
            'unknown default format'       => [function (array &$t) { $t['bindings'][0]['default_format'] = 'moeda'; }, 'format'],
            'binding of undeclared node'   => [function (array &$t) { $t['bindings'][] = ['key' => 'ghost.x', 'label' => 'x', 'type' => 'text']; }, 'node'],
            'duplicate binding key'        => [function (array &$t) { $t['bindings'][] = $t['bindings'][0]; }, 'duplicate'],
            'columns on a non-collection'  => [function (array &$t) { $t['bindings'][0]['columns'] = [['key' => 'a', 'label' => 'a']]; }, 'columns'],
            'binding in the formula namespace' => [function (array &$t) { $t['nodes']['formula'] = []; $t['bindings'][] = ['key' => 'formula.abc123', 'label' => 'x', 'type' => 'text']; }, 'formula'],
            'column in the formula namespace'  => [function (array &$t) {
                foreach ($t['bindings'] as &$binding) {
                    if ($binding['type'] === 'collection') {
                        $binding['columns'][] = ['key' => 'formula_abc123', 'label' => 'x'];
                    }
                }
            }, 'formula'],
            'singleton not a string'     => [function (array &$t) { $t['nodes']['emitente']['singleton'] = true; }, 'singleton'],
            'empty binding key'            => [function (array &$t) { $t['bindings'][] = ['key' => '', 'label' => 'x', 'type' => 'text']; }, 'no key'],
            'singleton method missing'     => [function (array &$t) { $t['nodes']['emitente']['model'] = \Controlink\Templates\Tests\TestCase::class; $t['nodes']['emitente']['singleton'] = 'nope'; }, 'singleton'],
            'every_page field not in header or footer of the initial layout' => [function (array &$t) { $t['initial_layout']['html'] = str_replace('id="tpl-atcud"', 'id="tpl-atcud" data-moved="1"', preg_replace('/<div class="block block--field"[^>]*data-tpl-binding="documento.atcud"[^>]*>.*?<\/div>/s', '', $t['initial_layout']['html'])); }, 'every_page'],
        ];
    }

    public function test_validation_never_touches_the_database(): void
    {
        $this->expectNotToPerformAssertions();

        config(['database.connections.testing.database' => '/nonexistent/path.sqlite']);
        \Illuminate\Support\Facades\DB::purge('testing');

        (new TypeCatalogue(['invoice' => $this->invoice()]))->validate();
    }
}
