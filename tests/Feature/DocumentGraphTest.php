<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Contracts\EncryptedFieldMap;
use Controlink\Templates\Contracts\TenantResolver;
use Controlink\Templates\Data\DocumentGraph;
use Controlink\Templates\Data\TableColumns;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\Fixtures\Models\Party;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The fields a template type can bind to are calculated, not written down. Three
 * sources, none of them a hand-kept list: the table's columns, the model's own
 * encrypted-field map, and its casts. What this file proves is that the calculation
 * answers the request as it was made — a column added to a model appears with no code
 * and no configuration touched — and that it never offers what must not be offered.
 */
class DocumentGraphTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The memo is per-request in production; a suite that adds a column to a
        // tenant table between two reads of the same type has to be able to clear it,
        // and no test should inherit another's graph.
        DocumentGraph::flush();

        Schema::create('parties', function ($table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('vat', 9)->nullable();
            $table->date('born_on')->nullable();
            $table->decimal('balance', 12, 4)->nullable();
            $table->boolean('active')->default(false);
            $table->string('secret', 64)->nullable();
            $table->string('secret_idx', 64)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('parties');
        DocumentGraph::flush();

        parent::tearDown();
    }

    /** The `invoice` fixture type with its `adquirente` node pointed at the test model. */
    private function partyType(): TemplateType
    {
        $config = Templates::type('invoice')->toArray();

        $config['nodes']['adquirente'] = array_merge($config['nodes']['adquirente'], [
            'model' => Party::class,
            'lang'  => 'parties',
        ]);

        return TemplateType::fromConfig('invoice', $config);
    }

    /** @return array<int, array<string, mixed>> */
    private function fieldsOf(TemplateType $type, string $nodeId): array
    {
        foreach (DocumentGraph::for($type) as $node) {
            if ($node['id'] === $nodeId) {
                return $node['fields'];
            }
        }

        $this->fail("Node [{$nodeId}] is not in the graph.");
    }

    public function test_a_node_with_no_model_offers_only_the_declared_bindings(): void
    {
        $keys = array_column($this->fieldsOf(Templates::type('invoice'), 'documento'), 'key');

        $this->assertContains('documento.numero', $keys);
        $this->assertContains('documento.atcud', $keys);
        $this->assertCount(8, $keys, 'A node with no model must offer exactly its declared bindings, nothing calculated.');
    }

    public function test_a_node_with_a_model_offers_declared_fields_first_then_the_undeclared_schema_columns(): void
    {
        $keys = array_column($this->fieldsOf($this->partyType(), 'adquirente'), 'key');

        // Declared, hand-written bindings.
        foreach (['adquirente.nome', 'adquirente.nif', 'adquirente.morada'] as $declared) {
            $this->assertContains($declared, $keys, "Declared binding {$declared} missing.");
        }

        // Calculated, from the schema.
        foreach (['adquirente.name', 'adquirente.vat', 'adquirente.born_on', 'adquirente.balance', 'adquirente.active'] as $calculated) {
            $this->assertContains($calculated, $keys, "Calculated field {$calculated} missing.");
        }

        // Plumbing (id, timestamp, foreign key) and a hidden column: never offered.
        foreach (['adquirente.id', 'adquirente.created_at', 'adquirente.owner_id', 'adquirente.secret'] as $excluded) {
            $this->assertNotContains($excluded, $keys, "{$excluded} must not be offered.");
        }
    }

    public function test_a_column_listed_in_the_graph_excluded_columns_config_is_left_out(): void
    {
        config(['templates.graph.excluded_columns' => ['balance']]);

        $keys = array_column($this->fieldsOf($this->partyType(), 'adquirente'), 'key');

        $this->assertNotContains('adquirente.balance', $keys, 'A column configured as excluded must not be offered.');
        $this->assertContains('adquirente.vat', $keys, 'Only the configured column should be excluded.');
    }

    public function test_a_bound_encrypted_field_map_hides_its_blind_indexes_and_marks_its_encrypted_fields(): void
    {
        $this->app->bind(EncryptedFieldMap::class, fn () => new class implements EncryptedFieldMap {
            public function for(string $modelClass): array
            {
                return $modelClass === Party::class
                    ? ['encrypted' => ['vat'], 'blind_indexes' => ['secret_idx']]
                    : ['encrypted' => [], 'blind_indexes' => []];
            }
        });

        $fields = collect($this->fieldsOf($this->partyType(), 'adquirente'))->keyBy('key');

        $this->assertFalse($fields->has('adquirente.secret_idx'), 'A blind index must not be offered.');
        $this->assertTrue($fields['adquirente.vat']['encrypted']);
        $this->assertFalse($fields['adquirente.name']['encrypted']);
    }

    public function test_number_date_text_and_boolean_types_are_inferred_from_casts_and_columns(): void
    {
        $fields = collect($this->fieldsOf($this->partyType(), 'adquirente'))->keyBy('key');

        $this->assertSame('date', $fields['adquirente.born_on']['type']);
        $this->assertSame('number', $fields['adquirente.balance']['type']);
        $this->assertSame('text', $fields['adquirente.name']['type']);

        $this->assertSame('text', $fields['adquirente.active']['type'], 'A boolean is always a text binding.');
        $this->assertTrue($fields['adquirente.active']['is_boolean']);
        $this->assertFalse($fields['adquirente.name']['is_boolean']);

        // A cast wins over the column's own type — exercised directly against
        // TableColumns, since SQLite has nothing to offer that already carries a cast.
        $descriptor = TableColumns::describe(
            ['name' => 'issued_on', 'type' => 'varchar(20)', 'type_name' => 'varchar', 'nullable' => true],
            ['issued_on' => 'date'],
        );
        $this->assertSame('date', $descriptor->bindingType);
    }

    public function test_a_nodes_label_comes_from_its_lang_key_and_flags_a_missing_translation(): void
    {
        app('translator')->addLines(['parties.fields.name' => 'Nome'], 'pt_PT');

        $fields = collect($this->fieldsOf($this->partyType(), 'adquirente'))->keyBy('key');

        $this->assertSame('Nome', $fields['adquirente.name']['label']);
        $this->assertFalse($fields['adquirente.name']['label_missing']);

        $this->assertSame('vat', $fields['adquirente.vat']['label']);
        $this->assertTrue($fields['adquirente.vat']['label_missing']);
    }

    public function test_without_a_resolved_tenant_the_graph_offers_only_declared_fields_and_runs_no_query(): void
    {
        config([
            'templates.tenancy.mode'       => 'connection',
            'templates.tenancy.connection' => 'testing',
        ]);

        $this->app->bind(TenantResolver::class, fn () => new class implements TenantResolver {
            public function currentId(): ?string
            {
                return null;
            }

            public function all(): iterable
            {
                return [];
            }

            public function runAs(string $tenantId, callable $callback): mixed
            {
                return $callback();
            }
        });

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $keys = array_column($this->fieldsOf($this->partyType(), 'adquirente'), 'key');

        $this->assertSame(['adquirente.nome', 'adquirente.nif', 'adquirente.morada'], $keys);
        $this->assertSame(0, $queries, 'DocumentGraph must not touch the database with no resolved tenant.');
    }

    public function test_flush_clears_the_memo(): void
    {
        $type = $this->partyType();

        $before = array_column($this->fieldsOf($type, 'adquirente'), 'key');
        $this->assertNotContains('adquirente.nickname', $before);

        Schema::table('parties', function ($table): void {
            $table->string('nickname', 60)->nullable();
        });

        $stillMemoised = array_column($this->fieldsOf($type, 'adquirente'), 'key');
        $this->assertNotContains('adquirente.nickname', $stillMemoised, 'The graph must stay memoised until flush() is called.');

        DocumentGraph::flush();

        $after = array_column($this->fieldsOf($type, 'adquirente'), 'key');
        $this->assertContains('adquirente.nickname', $after, 'flush() must clear the memo.');
    }
}
