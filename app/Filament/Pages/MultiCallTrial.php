<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\OperatorCall;
use App\Models\Team;
use App\Services\Operator\ClientSearch;
use App\Services\Operator\MultiCallDesk;
use App\Services\Operator\TestCallPlacer;
use App\Services\Telephony\PlatformExtensionAllocator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use RuntimeException;
use UnitEnum;

/**
 * Try the multi-call operator workspace on demand, before switching it
 * on for operators (Platform Settings → Operator Window).
 *
 * "Ring my softphone" places a real call (TestCallPlacer) that shows up
 * in /operator/workspace. The other actions fill a simulated desk shown
 * on this page.
 *
 * The workspace below is the same component operators get. Calls here
 * are trial rows on the admin's own desk: nothing rings, and a saved
 * message is recorded on the trial call only, never in a client's
 * inbox. Client data shown (greeting, notes, passcode, recent messages)
 * is real, so the page is super-admin only.
 */
class MultiCallTrial extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Multi-call trial';

    protected static ?string $title = 'Multi-call workspace trial';

    protected static ?string $slug = 'multi-call-trial';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.pages.multi-call-trial';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function getSubheading(): ?string
    {
        $state = config('orbital.operator.multi_call_workspace') ? 'on' : 'off';

        return "Simulated calls on your own desk. For operators this layout is currently {$state} (Platform Settings → Operator Window).";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ring')
                ->label('Ring my softphone')
                ->icon('heroicon-o-phone-arrow-down-left')
                ->color('danger')
                ->modalHeading('Place a real test call')
                ->modalDescription(fn () => $this->ringDescription())
                ->modalSubmitActionLabel('Ring')
                ->modalWidth('lg')
                ->schema([
                    $this->clientField(),
                    Forms\Components\TextInput::make('caller_name')
                        ->label('Caller name')
                        ->placeholder('Shown on the softphone; "Test caller" if blank')
                        ->maxLength(80),
                    Forms\Components\TextInput::make('caller_number')
                        ->label('Caller number')
                        ->placeholder('Random 555-01xx number if blank')
                        ->maxLength(20),
                ])
                ->action(function (array $data) {
                    $team = $this->team($data['team_id'] ?? null);
                    if (! $team) {
                        return;
                    }

                    try {
                        app(TestCallPlacer::class)->place(auth()->user(), $team, $data['caller_name'] ?? null, $data['caller_number'] ?? null);
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Test call not placed')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Ringing your softphone')
                        ->body("A real call for {$team->name}. It opens in /operator/workspace; a message saved on it goes to the client.")
                        ->success()
                        ->send();
                }),

            Action::make('allocate_softphone')
                ->label('Give me a softphone')
                ->icon('heroicon-o-device-phone-mobile')
                ->color('gray')
                ->visible(fn () => app(PlatformExtensionAllocator::class)->extensionFor(auth()->user()) === null)
                ->requiresConfirmation()
                ->modalDescription('Allocates you a WebRTC softphone extension, as for any staff member. The softphone logs in the next time you open the operator panel.')
                ->action(function () {
                    $ext = app(PlatformExtensionAllocator::class)->ensureWebrtcExtensionFor(auth()->user());
                    Notification::make()->title("Allocated extension {$ext->number}")->body('Open /operator/workspace so it logs in.')->success()->send();
                }),

            Action::make('scenario')
                ->label('Load 3-call scenario')
                ->icon('heroicon-o-queue-list')
                ->color('primary')
                ->modalHeading('Three calls for one client')
                ->modalDescription('One ringing, one queued behind it, one on hold with a half-written message. Replaces any trial calls on your desk.')
                ->modalSubmitActionLabel('Load')
                ->modalWidth('lg')
                ->schema([$this->clientField()])
                ->action(function (array $data) {
                    $team = $this->team($data['team_id'] ?? null);
                    if (! $team) {
                        return;
                    }

                    $desk = app(MultiCallDesk::class);
                    $desk->clearTrial(auth()->user());
                    $calls = $desk->startTrialScenario(auth()->user(), $team);

                    $this->dispatch('multi-call-desk-updated', select: $calls->first()?->id);
                }),

            Action::make('simulate')
                ->label('Simulate a call')
                ->icon('heroicon-o-beaker')
                ->color('success')
                ->modalHeading('Simulate an incoming call')
                ->modalSubmitActionLabel('Ring')
                ->modalWidth('lg')
                ->schema([
                    $this->clientField(),
                    Forms\Components\TextInput::make('caller_name')
                        ->label('Caller name')
                        ->placeholder('Leave blank for an unknown caller')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('caller_number')
                        ->label('Caller number')
                        ->placeholder('Random 555-01xx number if blank')
                        ->maxLength(64),
                    Forms\Components\Radio::make('status')
                        ->label('Arrives as')
                        ->options([
                            OperatorCall::STATUS_RINGING => 'Ringing',
                            OperatorCall::STATUS_QUEUED => 'Queued behind other calls',
                        ])
                        ->default(OperatorCall::STATUS_RINGING)
                        ->inline()
                        ->required(),
                ])
                ->action(function (array $data) {
                    $team = $this->team($data['team_id'] ?? null);
                    if (! $team) {
                        return;
                    }

                    app(MultiCallDesk::class)->simulateTrialCall(
                        auth()->user(),
                        $team,
                        $data['caller_name'] ?? null,
                        $data['caller_number'] ?? null,
                        (string) ($data['status'] ?? OperatorCall::STATUS_RINGING),
                    );

                    $this->dispatch('multi-call-desk-updated');
                }),

            Action::make('clear')
                ->label('Clear trial')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Remove every trial call from your desk. Live calls and real messages are not touched.')
                ->action(function () {
                    $removed = app(MultiCallDesk::class)->clearTrial(auth()->user());
                    $this->dispatch('multi-call-desk-updated');

                    Notification::make()->title("Removed {$removed} trial ".str('call')->plural($removed))->success()->send();
                }),
        ];
    }

    private function ringDescription(): string
    {
        $status = app(TestCallPlacer::class)->softphoneStatus(auth()->user());
        $layout = config('orbital.operator.multi_call_workspace')
            ? ''
            : ' The operator Workspace shows calls as tabs only with Platform Settings → Operator Window → Multi-call workspace switched on.';

        $phone = match (true) {
            $status['extension'] === null => 'You have no softphone extension yet: close this and use "Give me a softphone".',
            ! $status['registered'] => "Your softphone (ext {$status['extension']}) isn't logged in. Open /operator/workspace in another tab first.",
            default => "Your softphone (ext {$status['extension']}) is logged in.",
        };

        return 'Asterisk rings your own softphone with a real call for the client you pick. The caller plays hold music. '
            .'A message you save on it is filed for that client like any other.'
            ." {$phone}{$layout}";
    }

    private function clientField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('team_id')
            ->label('Client')
            ->placeholder('Name, account number, or old account number')
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => app(ClientSearch::class)->search($search))
            ->getOptionLabelUsing(fn ($value): ?string => ($t = Team::find($value)) ? ClientSearch::label($t) : null)
            ->required();
    }

    private function team(mixed $teamId): ?Team
    {
        $team = Team::query()->where('personal_team', false)->find($teamId);
        if (! $team) {
            Notification::make()->title('Client not found')->danger()->send();
        }

        return $team;
    }
}
