<x-layouts::app :title="__('Departments')">
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('Departments') }}</flux:heading>
        <flux:subheading>{{ __('All departments and how many users are in each') }}</flux:subheading>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Department') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Users') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($departments as $department)
                <flux:table.row>
                    <flux:table.cell>{{ $department->label }}</flux:table.cell>

                    <flux:table.cell>
                        @if ($department->is_active)
                            <flux:badge size="sm" color="green" inset="top bottom">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc" inset="top bottom">{{ __('Inactive') }}</flux:badge>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell align="end">{{ $department->users_count }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="3" class="text-center">{{ __('No departments found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</x-layouts::app>
