<?php

namespace App\Filament\Tenant\Resources\SellingResource;

use App\Services\Tenants\SellingTrashService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * What the buttons of Selling History do: the bulk actions on the list and the buttons on the sale page.
 * Both end up here, so the permission check, the service call and the notifications exist once.
 */
final class SellingTrashActions
{
    private function __construct()
    {
    }

    /**
     * Anyone with the `delete selling` permission, and the owner (is_owner), may trash and restore.
     */
    public static function allowed(): bool
    {
        return can('delete selling');
    }

    /**
     * @return array<int, Textarea>
     */
    public static function reasonForm(): array
    {
        return [
            Textarea::make('reason')
                ->label(__('Reason (optional)'))
                ->maxLength(255)
                ->rows(2),
        ];
    }

    /**
     * @param  array<int, int|string>  $ids
     * @param  array<string, mixed>  $data
     * @return array{done:int, skipped:int, failed:array<string, string>}
     */
    public static function trash(array $ids, array $data = []): array
    {
        abort_unless(self::allowed(), 403);

        $result = app(SellingTrashService::class)->trash($ids, Filament::auth()->user(), $data['reason'] ?? null);

        if ($result['done'] > 0) {
            Notification::make()
                ->title(__(':count sales moved to the trash', ['count' => $result['done']]))
                ->body(__('The stock of the items is back.'))
                ->success()
                ->send();
        }

        self::reportFailures($result);

        return $result;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array{done:int, skipped:int, failed:array<string, string>}
     */
    public static function restore(array $ids): array
    {
        abort_unless(self::allowed(), 403);

        $result = app(SellingTrashService::class)->restore($ids);

        if ($result['done'] > 0) {
            Notification::make()
                ->title(__(':count sales restored', ['count' => $result['done']]))
                ->success()
                ->send();
        }

        self::reportFailures($result);

        return $result;
    }

    /**
     * @param  array{done:int, skipped:int, failed:array<string, string>}  $result
     */
    private static function reportFailures(array $result): void
    {
        foreach ($result['failed'] as $code => $reason) {
            Notification::make()
                ->title(__(':code was not restored', ['code' => $code]))
                ->body($reason)
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
