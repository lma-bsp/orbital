<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-lock-closed" heading="Your account has no portal pages yet">
        <p style="font-size: 0.875rem;">
            You are signed in{{ $tenantName !== '' ? ' to '.$tenantName : '' }}, but none of your roles
            include access to the overview, call history, or messages.
        </p>
        <p style="font-size: 0.875rem; margin-top: 0.75rem;">
            Ask an administrator on your account to give you a role with the access you need.
            Your own security settings are still available from the user menu.
        </p>
    </x-filament::section>
</x-filament-panels::page>
