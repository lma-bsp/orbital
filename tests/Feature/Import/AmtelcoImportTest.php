<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Filament\Operator\Pages\Workspace;
use App\Models\ClientAccountAlias;
use App\Models\ClientLegacyRecord;
use App\Models\Team;
use App\Models\User;
use App\Services\Clients\ClientProvisioner;
use App\Services\Import\Amtelco\AmtelcoImporter;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsXlsxFiles;
use Tests\TestCase;

/**
 * orbital:import-amtelco against a small workbook shaped like NCI's
 * Amtelco export.
 */
class AmtelcoImportTest extends TestCase
{
    use BuildsXlsxFiles;
    use RefreshDatabase;

    private const ACCOUNT_HEADER = ['Account #', 'Client Name', 'Answer Phrase', 'Client Status', 'Passcode', 'Alpha Date', 'Text Msgs', 'Delivered', 'Voice Msgs', 'Played', 'Specials'];

    private string $workbook;

    private string $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->report = tempnam(sys_get_temp_dir(), 'report');
        $this->workbook = $this->buildXlsx($this->sheets());
    }

    protected function tearDown(): void
    {
        @unlink($this->workbook);
        @unlink($this->report);
        parent::tearDown();
    }

    /**
     * @param  array<int, array<int, string|int|null>>|null  $accounts
     * @return array<string, array<int, array<int, string|int|null>>>
     */
    private function sheets(?array $accounts = null): array
    {
        return [
            'README' => [['NCI Client Data'], ['Source', 'test']],
            'Accounts' => [self::ACCOUNT_HEADER, ...($accounts ?? [
                [2, 'SET UP COPY ACCT', 'MY NAME IS XXXX', 'At Lunch', '0', '07/14/2026', '0', '0', '0', '0', '0'],
                [620, 'ENT & ALLERGY  ASSOC', 'ENT AND ALLERGY, THIS IS ___', 'TRICKY', '4321', '11/04/2026', '0', '3', '12', '4', '0'],
                [4332, 'PRIME MEDICAL', '', 'DROP ACCT', '0', '05/01/2024', '1', '1', '0', '0', '0'],
                [7555, 'MR ROCCO UNGARO', 'GOOD MORNING', '', '0', '02/02/2026', '0', '0', '5', '5', '0'],
                [7505, 'MR ROCCO UNGARO', '', '', '0', '02/02/2026', '0', '0', '1', '0', '0'],
            ])],
            'Setup' => [
                ['Account #', 'Client Name', 'Section', 'Field', 'Value'],
                [2, 'SET UP COPY ACCT', 'MAIN', 'Language', 'English'],
                [620, 'ENT & ALLERGY  ASSOC', 'HEADER', 'Billing#', '0'],
                [620, 'ENT & ALLERGY  ASSOC', 'MAIN', 'Language', 'English'],
                [7555, 'MR ROCCO UNGARO', 'MAIN', 'Language', 'English'],
            ],
            'Details' => [['Account #', 'Client Name', 'Manual Behavior?'], [620, 'ENT & ALLERGY  ASSOC', 'No']],
            'Info Pages' => [
                ['Account #', 'Client Name', 'Line', 'Text'],
                [620, 'ENT & ALLERGY  ASSOC', 1, 'INFO PAGE 1                             INFO PAGE 2'],
                [620, 'ENT & ALLERGY  ASSOC', 2, 'ENT AND ALLERGY ASSOCIATES              2ND OFFICE: SUFFERN'],
            ],
            'Forms' => [['Account #', 'Client Name', 'Line', 'Text'], [620, 'ENT & ALLERGY  ASSOC', 1, 'FULL NAME:|            |']],
            'Dial Lists' => [['Account #', 'Client Name', 'List', 'Slot', 'Name', 'Dial String'], [620, 'ENT & ALLERGY  ASSOC', 'One', 11, 'All-FX', '.59SX']],
            'Transfer Lists' => [['Account #', 'Client Name', 'Fkey #', 'Name', 'String', 'Acct #']],
            'Dist Lists' => [['Account #', 'Client Name', 'List #', 'List Name', 'Voice', 'Member Count', 'Member Accounts']],
            'DIDs' => [
                ['Account #', 'Client Name', 'DID'],
                [620, 'ENT & ALLERGY  ASSOC', 620],
                [620, 'ENT & ALLERGY  ASSOC', 6200001],
                [7555, 'MR ROCCO UNGARO', 7555],
                [7555, 'MR ROCCO UNGARO', 7505],
            ],
            'Other Config' => [['Account #', 'Client Name', 'Default Sub'], [620, 'ENT & ALLERGY  ASSOC', '5232 ENT']],
        ];
    }

    private function import(array $options = []): PendingCommand
    {
        return $this->artisan('orbital:import-amtelco', ['path' => $this->workbook, '--report' => $this->report] + $options);
    }

    public function test_dry_run_reports_everything_and_writes_nothing(): void
    {
        $teams = Team::count();

        $this->import(['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame($teams, Team::count());
        $this->assertFalse(User::where('email', AmtelcoImporter::IMPORT_USER_EMAIL)->exists());
        $this->assertSame(0, ClientLegacyRecord::count());

        $csv = array_map('str_getcsv', file($this->report));
        $byAccount = collect(array_slice($csv, 1))->keyBy(0);
        $this->assertSame('create', $byAccount['620'][3]);
        $this->assertSame('skip', $byAccount['2'][3]);
        $this->assertStringStartsWith('utility-name', $byAccount['2'][4]);
        $this->assertSame('skip', $byAccount['4332'][3]);
        $this->assertStringStartsWith('dropped-status', $byAccount['4332'][4]);
        $this->assertStringContainsString('alias 7505 is also the number of account 7505', $byAccount['7555'][6]);
    }

    public function test_import_creates_provisioned_clients_with_their_call_details(): void
    {
        $this->import()->assertSuccessful();

        $team = Team::where('account_number', 620)->firstOrFail();
        $this->assertSame('ENT & Allergy Assoc', $team->name);
        $this->assertFalse($team->personal_team);
        $this->assertSame('America/New_York', $team->timezone);
        $this->assertSame('free', $team->tier);
        $this->assertSame('ENT AND ALLERGY, THIS IS ___', $team->greeting);
        $this->assertSame('TRICKY', $team->operator_note);
        $this->assertSame('4321', $team->passcode);
        $this->assertNotSame('4321', DB::table('teams')->where('id', $team->id)->value('passcode'), 'passcode is encrypted at rest');

        // Owned by the import user, who is the client's admin.
        $owner = User::where('email', AmtelcoImporter::IMPORT_USER_EMAIL)->firstOrFail();
        $this->assertSame($owner->id, $team->user_id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
        $this->assertTrue($owner->fresh()->hasRole(ClientProvisioner::ROLE_CLIENT_ADMIN));

        // Skipped accounts are not created.
        $this->assertFalse(Team::where('account_number', 2)->exists());
        $this->assertFalse(Team::where('account_number', 4332)->exists());
        $this->assertNull(Team::where('account_number', 7555)->value('passcode'), 'Amtelco 0 means no passcode');

        $this->assertSame([6200001], $team->accountAliases()->pluck('account_number')->all());
    }

    public function test_every_tab_is_archived_verbatim(): void
    {
        $this->import()->assertSuccessful();
        $team = Team::where('account_number', 620)->firstOrFail();

        $sections = ClientLegacyRecord::where('team_id', $team->id)->pluck('payload', 'section');
        $this->assertEqualsCanonicalizing(['account', 'setup', 'details', 'info_pages', 'forms', 'dial_lists', 'other_config'], $sections->keys()->all());

        $this->assertSame('ENT & ALLERGY  ASSOC', $sections['account']['Client Name'], 'the original name is kept');
        $this->assertCount(2, $sections['setup']['rows']);
        $this->assertSame(['Section' => 'MAIN', 'Field' => 'Language', 'Value' => 'English'], $sections['setup']['rows'][1]);
        // Fixed-width layout survives, spaces and all.
        $this->assertSame("INFO PAGE 1                             INFO PAGE 2\nENT AND ALLERGY ASSOCIATES              2ND OFFICE: SUFFERN", $sections['info_pages']['text']);
        $this->assertSame('.59SX', $sections['dial_lists']['rows'][0]['Dial String']);
    }

    public function test_rerunning_updates_in_place_and_keeps_operator_edits_unless_refreshed(): void
    {
        $this->import()->assertSuccessful();
        $teams = Team::count();
        $records = ClientLegacyRecord::count();

        $team = Team::where('account_number', 620)->firstOrFail();
        $team->forceFill(['name' => 'ENT and Allergy Associates', 'operator_note' => 'Dr on vacation'])->save();

        $this->import()->assertSuccessful();
        $this->assertSame($teams, Team::count());
        $this->assertSame($records, ClientLegacyRecord::count());
        $this->assertSame(1, ClientAccountAlias::where('team_id', $team->id)->count());
        $this->assertSame('ENT and Allergy Associates', $team->fresh()->name);
        $this->assertSame('Dr on vacation', $team->fresh()->operator_note);

        $this->import(['--refresh' => true])->assertSuccessful();
        $this->assertSame('ENT & Allergy Assoc', $team->fresh()->name);
        $this->assertSame('TRICKY', $team->fresh()->operator_note);
    }

    public function test_a_client_this_import_did_not_create_is_never_touched(): void
    {
        $owner = User::factory()->create();
        $mine = Team::forceCreate(['user_id' => $owner->id, 'name' => 'ACME', 'account_number' => 620, 'personal_team' => false]);

        $this->import()
            ->expectsOutputToContain('Conflict: #620')
            ->assertSuccessful();

        $this->assertSame('ACME', $mine->fresh()->name);
        $this->assertSame(0, ClientLegacyRecord::where('team_id', $mine->id)->count());
        $this->assertSame(1, Team::where('account_number', 620)->count());
    }

    public function test_include_and_exclude_override_the_rules(): void
    {
        $this->import(['--include' => ['4332'], '--exclude' => ['7505']])->assertSuccessful();

        $this->assertTrue(Team::where('account_number', 4332)->exists());
        $this->assertFalse(Team::where('account_number', 7505)->exists());
    }

    public function test_rejects_a_workbook_that_is_not_the_amtelco_export(): void
    {
        $other = $this->buildXlsx(['Sheet1' => [['a', 'b'], ['1', '2']]]);

        $this->artisan('orbital:import-amtelco', ['path' => $other, '--report' => $this->report])
            ->expectsOutputToContain("no 'Accounts' tab")
            ->assertFailed();

        @unlink($other);
    }

    public function test_operators_find_clients_by_alias_and_see_the_call_brief(): void
    {
        $this->import()->assertSuccessful();
        $team = Team::where('account_number', 620)->firstOrFail();

        $workspace = new Workspace;

        $this->assertArrayHasKey($team->id, $workspace->searchClients('6200001'));
        $this->assertArrayHasKey($team->id, $workspace->searchClients('620'));
        $this->assertArrayHasKey($team->id, $workspace->searchClients('allergy'));
        $this->assertSame([], $workspace->searchClients('nobody by this name'));

        $workspace->activeTeamId = $team->id;
        $brief = $workspace->callBrief();
        $this->assertSame('TRICKY', $brief['operator_note']);
        $this->assertSame('4321', $brief['passcode']);
        $this->assertSame([6200001], $brief['aliases']);
        $this->assertStringStartsWith('INFO PAGE 1', $brief['info_pages']);
    }
}
