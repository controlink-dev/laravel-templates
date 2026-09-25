<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Data\ColumnDescriptor;
use Controlink\Templates\Data\DocumentGraph;
use Controlink\Templates\Data\FixtureDataset;
use Controlink\Templates\Data\FixtureValue;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\Fixtures\Models\Party;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;
use Illuminate\Support\Facades\Schema;

class FixtureDatasetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DocumentGraph::flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('parties');
        DocumentGraph::flush();

        parent::tearDown();
    }

    public function test_declared_rows_are_repeated_fixture_repeats_times_with_a_suffix_on_the_first_column(): void
    {
        $type = Templates::type('invoice');

        $dataset = (new FixtureDataset)->for($type);

        $this->assertCount(3 * $type->fixtureRepeats, $dataset['linhas']);
        $this->assertSame('ART-0000000001-01', $dataset['linhas'][0]['codigo']);
        $this->assertSame('SRV-0000000002-01', $dataset['linhas'][1]['codigo']);
        $this->assertSame('ART-0000000003-01', $dataset['linhas'][2]['codigo']);

        $lastPass = str_pad((string) $type->fixtureRepeats, 2, '0', STR_PAD_LEFT);
        $this->assertSame('ART-0000000001-'.$lastPass, $dataset['linhas'][count($dataset['linhas']) - 3]['codigo']);
    }

    public function test_a_repeats_of_one_does_not_repeat(): void
    {
        $type = Templates::type('invoice');

        $dataset = (new FixtureDataset)->for($type, 1);

        $this->assertCount(3, $dataset['linhas']);
        $this->assertSame('ART-0000000001', $dataset['linhas'][0]['codigo']);
        $this->assertSame('SRV-0000000002', $dataset['linhas'][1]['codigo']);
    }

    public function test_a_field_the_graph_offers_and_the_type_does_not_declare_gets_a_value_generated_from_its_column(): void
    {
        Schema::create('parties', function ($table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('vat', 9)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $config = Templates::type('invoice')->toArray();
        $config['nodes']['adquirente'] = array_merge($config['nodes']['adquirente'], ['model' => Party::class]);
        $type = TemplateType::fromConfig('invoice', $config);

        $dataset = (new FixtureDataset)->for($type, 1);

        $this->assertArrayHasKey('adquirente.vat', $dataset, 'A calculated field must get a dataset key, or the preview throws under strict_variables.');
        $this->assertSame(
            FixtureValue::for(new ColumnDescriptor(name: 'adquirente.vat', bindingType: 'text', length: null)),
            $dataset['adquirente.vat'],
        );
    }

    public function test_a_declared_value_is_never_overwritten_by_a_generated_one(): void
    {
        // The model's own column is named exactly like the declared binding's suffix
        // (`adquirente.nome`), so `generated()` computes a value for that key too — this
        // proves the declared fixture value wins the union rather than the two never
        // colliding in practice.
        Schema::create('parties', function ($table): void {
            $table->id();
            $table->string('nome', 150);
        });

        $config = Templates::type('invoice')->toArray();
        $config['nodes']['adquirente'] = array_merge($config['nodes']['adquirente'], ['model' => Party::class]);
        $type = TemplateType::fromConfig('invoice', $config);

        $dataset = (new FixtureDataset)->for($type, 1);

        $this->assertSame($type->fixture['adquirente.nome'], $dataset['adquirente.nome']);
    }
}
