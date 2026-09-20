<?php

namespace App\Filament\Tenant\Pages;

use App\Models\Tenants\Setting;
use App\Traits\HasTranslatableResource;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components;
use Filament\Forms\Components\Actions\Action as ActionsAction;
use Filament\Forms\Components\Grid;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;

class Printer extends Page implements HasActions, HasForms
{
    use HasTranslatableResource;
    use InteractsWithFormActions;

    protected static ?string $navigationIcon = 'heroicon-o-printer';

    protected static string $view = 'filament.tenant.pages.printer';

    public static function canAccess(): bool
    {
        return can('access printer');
    }

    public ?array $data = [];

    public function mount()
    {
        $this->form->fill([
            'header' => Setting::get('receipt_header', ''),
            'footer' => Setting::get('receipt_footer', ''),
            'paper_width' => Setting::get('receipt_paper_width', '58'),
            'logo' => Setting::get('receipt_logo', null),
            'driver' => Setting::get('receipt_driver', 'serial'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Components\Hidden::make('logo'),
            Components\Textarea::make('header')
                ->rows(4)
                ->translateLabel(),
            Components\TextInput::make('name')
                ->required()
                ->translateLabel(),
            Grid::make(columns: 2)
                ->schema([
                    Components\Select::make('driver')
                        ->default('serial')
                        ->options([
                            'serial' => 'Bluetooth / USB Serial COM (Web Serial - Sangat Direkomendasikan di Windows)',
                            'bluetooth' => 'Bluetooth BLE (Web Bluetooth API)',
                            'usb' => 'USB (WebUSB API)',
                            'browser' => 'Driver Windows / System Print (window.print)',
                        ])
                        ->live()
                        ->translateLabel(),
                    Components\Select::make('paper_width')
                        ->default('58')
                        ->options([
                            '58' => '58mm (Standard)',
                            '80' => '80mm (Wide)',
                        ])
                        ->live()
                        ->label(__('Paper width'))
                        ->translateLabel(),
                ]),
            Grid::make(columns: 3)
                ->schema([
                    Components\TextInput::make('printer')
                        ->required()
                        ->helperText(__('Please click the select printer button to choose the connected printer'))
                        ->readOnly()
                        ->translateLabel()
                        ->columnSpan(2),
                    Components\TextInput::make('printerId')
                        ->required()
                        ->hintActions([
                            ActionsAction::make('select_printer')
                                ->icon('heroicon-o-printer')
                                ->translateLabel()
                                ->extraAttributes([
                                    'x-on:click' => 'fetchDeviceByDriver',
                                ]),
                        ])
                        ->readOnly(),
                ]),
            Components\Textarea::make('footer')
                ->rows(4)
                ->translateLabel(),
        ])->statePath('data');
    }

    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->translateLabel()
                ->extraAttributes([
                    'x-on:click' => 'save',
                ]),
            Action::make('test')
                ->translateLabel()
                ->color('warning')
                ->icon('heroicon-o-printer')
                ->extraAttributes([
                    'x-on:click' => 'test',
                ]),
        ];
    }

    public function validateInput()
    {
        $this->validate([
            'data.printer' => 'required',
            'data.name' => 'required',
        ]);
    }

    public function saveToServer(array $settings): void
    {
        if (isset($settings['header'])) {
            Setting::set('receipt_header', $settings['header']);
        }
        if (isset($settings['footer'])) {
            Setting::set('receipt_footer', $settings['footer']);
        }
        if (isset($settings['paper_width'])) {
            Setting::set('receipt_paper_width', $settings['paper_width']);
        }
        if (isset($settings['driver'])) {
            Setting::set('receipt_driver', $settings['driver']);
        }
        if (array_key_exists('logo', $settings)) {
            Setting::set('receipt_logo', $settings['logo']);
        }
    }
}
