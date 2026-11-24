<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Models\ClientAccountAlias;
use App\Models\ClientLegacyRecord;
use App\Models\DirectoryEntry;
use App\Models\DirectoryFieldDefinition;
use App\Models\SharedDirectory;
use App\Models\Team;
use App\Models\User;
use App\Services\Clients\ClientProvisioner;
use App\Services\Import\Amtelco\DirectoryImporter;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class AmtelcoDirectoryImportTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private string $report;

    private Team $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);

        // A client as orbital:import-amtelco leaves it.
        $owner = User::factory()->create();
        $this->client = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Ent & Allergy Assoc', 'account_number' => 620, 'personal_team' => false]);
        app(ClientProvisioner::class)->provision($this->client, $owner);
        ClientLegacyRecord::create(['team_id' => $this->client->id, 'source' => 'amtelco', 'section' => 'account', 'source_account_number' => 620, 'payload' => []]);
        ClientAccountAlias::create(['team_id' => $this->client->id, 'account_number' => 6200001, 'source' => 'amtelco:did']);

        // Same number, but not created by the Amtelco import: never touched.
        Team::forceCreate(['user_id' => $owner->id, 'name' => 'Someone else', 'account_number' => 777, 'personal_team' => false]);

        $this->report = tempnam(sys_get_temp_dir(), 'report');
        $this->file = tempnam(sys_get_temp_dir(), 'dirs');
        file_put_contents($this->file, json_encode([
            'schema' => DirectoryImporter::SCHEMA,
            'providers' => [
                ['n' => 1, 'name' => ['raw' => 'ABBATISTA, MARIA', 'last' => 'ABBATISTA', 'first' => 'MARIA'], 'account_numbers' => ['raw' => '0620', 'numbers' => ['0620']], 'specialty' => 'OBGYN', 'town' => 'WHITE PLAINS', 'state' => 'NY', 'contact_methods' => [['type' => 'office', 'label' => 'MAIN#', 'number' => '+19146826000', 'raw' => '9146826000']]],
            ],
            'directories' => [
                ['directory_id' => '0620 ENT', 'linked_numbers' => ['0620'], 'listings_count' => 2, 'listings' => [
                    ['id' => '0620 ENT#1', 'record_type' => 'contact', 'name' => ['raw' => 'HUANG, JOHN', 'last' => 'HUANG', 'first' => 'JOHN'], 'contact_methods' => [['type' => 'cell', 'label' => 'CELL', 'number' => '+12016849227', 'raw' => '12016849227']]],
                    ['id' => '0620 ENT#2', 'record_type' => 'instruction', 'name' => ['raw' => 'KAZA'], 'other_fields' => [['label' => 'HOME #', 'value' => '>FETCH ACCT 2045!']]],
                ]],
                // Reaches the same client through its alias.
                ['directory_id' => '6200001 SUB', 'linked_numbers' => ['6200001'], 'listings_count' => 1, 'listings' => [
                    ['id' => '6200001 SUB#1', 'record_type' => 'routing', 'name' => ['raw' => 'GORDON, RACHEL', 'last' => 'GORDON', 'first' => 'RACHEL'], 'attributes' => ['account_numbers' => [['raw' => '620', 'numbers' => ['620']]]]],
                ]],
                ['directory_id' => '0002 HSP DIR', 'linked_numbers' => ['0002'], 'listings_count' => 1, 'listings' => [
                    ['id' => '0002 HSP DIR#1', 'record_type' => 'contact', 'other_fields' => [['label' => 'HOSPITAL', 'value' => 'ALCOHOLICS ANONYMOUS (AA)']], 'contact_methods' => [['type' => 'phone', 'label' => 'PHONE #', 'number_local' => '949-1200', 'number_status' => 'no_area_code', 'raw' => '9491200']]],
                ]],
                ['directory_id' => '0777 X', 'linked_numbers' => ['0777'], 'listings_count' => 1, 'listings' => [['id' => '0777 X#1', 'record_type' => 'note', 'unlabeled_text' => ['HELLO']]]],
                ['directory_id' => '0070', 'linked_numbers' => ['0070'], 'listings_count' => 0, 'listings' => [], 'empty' => true],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @unlink($this->report);
        parent::tearDown();
    }

    private function import(array $options = []): PendingCommand
    {
        return $this->artisan('orbital:import-amtelco-directories', ['path' => $this->file, '--report' => $this->report] + $options);
    }

    private function entries(array $where): Collection
    {
        return DirectoryEntry::query()->withoutGlobalScope('team')->where($where)->get()->keyBy(fn ($e) => $e->values['amtelco_ref']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->import(['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, DirectoryEntry::query()->withoutGlobalScope('team')->count());
        $this->assertSame(0, SharedDirectory::count());
        $this->assertStringContainsString('"0620 ENT","client #'.$this->client->id.'",2,0,0', file_get_contents($this->report));
    }

    public function test_client_directories_are_filled_by_account_number_and_alias(): void
    {
        $this->import()->assertSuccessful();

        $entries = $this->entries(['team_id' => $this->client->id]);
        $this->assertEqualsCanonicalizing(['0620 ENT#1', '0620 ENT#2', '6200001 SUB#1'], $entries->keys()->all());

        $huang = $entries['0620 ENT#1'];
        $this->assertSame('John Huang', $huang->values['name']);
        $this->assertSame('+12016849227', $huang->values['cell_phone']);
        // Two directories on one client: tagged by kind and by directory.
        $this->assertEqualsCanonicalizing(['Contact', 'Amtelco 0620 ENT'], $huang->tags->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Instruction', 'Amtelco 0620 ENT'], $entries['0620 ENT#2']->tags->pluck('name')->all());
        $this->assertSame('#620 Ent & Allergy Assoc', $entries['6200001 SUB#1']->values['account_ref']);

        // The fields exist, and a phone isn't required of routing lines.
        $fields = DirectoryFieldDefinition::query()->withoutGlobalScopes()->where('team_id', $this->client->id)->pluck('required', 'key');
        foreach (['cell_phone', 'home_phone', 'pager', 'text_to', 'contact_procedure', 'account_ref', 'amtelco_ref'] as $key) {
            $this->assertTrue($fields->has($key), "missing field {$key}");
        }
        $this->assertFalse((bool) $fields['primary_phone']);

        $archive = ClientLegacyRecord::where(['team_id' => $this->client->id, 'section' => 'directories'])->firstOrFail();
        $this->assertCount(2, $archive->payload['directories']);
    }

    public function test_the_rest_become_unattached_shared_directories(): void
    {
        $this->import()->assertSuccessful();

        $providers = SharedDirectory::where('name', DirectoryImporter::PROVIDERS_DIRECTORY)->firstOrFail();
        $maria = $this->entries(['shared_directory_id' => $providers->id])['0001 DIR#1'];
        $this->assertSame('Maria Abbatista', $maria->values['name']);
        $this->assertSame('OBGYN', $maria->values['department']);
        $this->assertSame('White Plains, NY', $maria->values['location']);
        $this->assertSame('#620 Ent & Allergy Assoc', $maria->values['account_ref']);

        $hospitals = SharedDirectory::where('name', 'Amtelco 0002 HSP DIR')->firstOrFail();
        $this->assertSame('Alcoholics Anonymous (AA)', $this->entries(['shared_directory_id' => $hospitals->id])->first()->values['name']);
        $this->assertSame(0, $hospitals->teams()->count(), 'attached to no client');

        // A number used by a client this import didn't create is not a match.
        $this->assertTrue(SharedDirectory::where('name', 'Amtelco 0777 X')->exists());
        $this->assertSame(0, DirectoryEntry::query()->withoutGlobalScope('team')->where('team_id', Team::where('account_number', 777)->value('id'))->count());

        // Empty directories are not created.
        $this->assertFalse(SharedDirectory::where('name', 'Amtelco 0070')->exists());
    }

    public function test_rerun_creates_nothing_new_and_keeps_edits_unless_refreshed(): void
    {
        $this->import()->assertSuccessful();
        $count = DirectoryEntry::query()->withoutGlobalScope('team')->count();

        $huang = $this->entries(['team_id' => $this->client->id])['0620 ENT#1'];
        $huang->values = ['name' => 'Dr John Huang', 'pager' => 'stale', 'favourite_hospital' => 'White Plains'] + $huang->values;
        $huang->save();

        $this->import()->assertSuccessful();
        $this->assertSame($count, DirectoryEntry::query()->withoutGlobalScope('team')->count());
        $this->assertSame(1, SharedDirectory::where('name', 'Amtelco 0002 HSP DIR')->count());
        $this->assertSame('Dr John Huang', $huang->fresh()->values['name']);

        $this->import(['--refresh' => true])->assertSuccessful();
        $this->assertSame($count, DirectoryEntry::query()->withoutGlobalScope('team')->count());
        $this->assertSame('John Huang', $huang->fresh()->values['name']);
        $this->assertArrayNotHasKey('pager', $huang->fresh()->values, 'an imported field the listing no longer fills is emptied');
        $this->assertSame('White Plains', $huang->fresh()->values['favourite_hospital'], 'fields the import never writes survive a refresh');
    }

    public function test_rejects_other_json(): void
    {
        file_put_contents($this->file, json_encode(['schema' => 'something/else']));

        $this->import()->expectsOutputToContain('not an Amtelco directory export')->assertFailed();
    }
}
