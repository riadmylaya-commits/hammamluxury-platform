<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ActivityLogResource\Pages;
use App\Models\ActivityLog;
use App\Models\Partner;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Journal des actions importantes (lecture seule). */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 90;

    public static function getModelLabel(): string
    {
        return __('admin.activity_log');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.activity_logs');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function actionLabel(string $action): string
    {
        return __('admin.log_action')[$action] ?? $action;
    }

    public static function subjectLabel(ActivityLog $l): string
    {
        if (! $l->subject) {
            return '—';
        }
        $type = class_basename($l->subject_type);
        $name = $l->subject->company_name ?? $l->subject->name ?? $l->subject->reference ?? $l->subject->number ?? null;

        return (__('admin.log_subjects')[$type] ?? $type).' n° '.$l->subject_id.($name ? ' · '.$name : '');
    }

    public static function panelLabel(?string $panel): string
    {
        return __('admin.log_panels')[$panel] ?? (string) $panel;
    }

    public static function details(ActivityLog $l): string
    {
        $props = $l->properties ?? [];
        $parts = [];
        if (str_starts_with($l->action, 'partner.commission_period_')) {
            $parts[] = self::periodLine($props['label'] ?? null, $props['pct'] ?? null, $props['from'] ?? null, array_key_exists('to', $props) ? $props['to'] : null);
            $changes = [];
            foreach (['previous_label' => 'label', 'previous_pct' => 'pct', 'previous_from' => 'from', 'previous_to' => 'to', 'previous_end' => 'to'] as $old => $new) {
                if (array_key_exists($old, $props)) {
                    $changes[] = self::keyLabel($old === 'previous_end' ? 'to' : $new).' : '.self::value($new, $props[$old]).' → '.self::value($new, $props[$new] ?? null);
                }
            }
            if ($changes) {
                $parts[] = __('admin.log_before_after').' — '.implode(' ; ', $changes);
            }
            $props = array_diff_key($props, array_flip(['label', 'pct', 'from', 'to', 'previous_label', 'previous_pct', 'previous_from', 'previous_to', 'previous_end']));
        }
        if ($l->action === 'partner.commission_change_alerted') {
            $parts[] = __('admin.log_alert', ['stage' => $props['stage'] ?? 'J-7', 'from' => self::value('pct', $props['from'] ?? null), 'to' => self::value('pct', $props['to'] ?? null), 'on' => self::value('on', $props['on'] ?? null)]);
            $props = array_diff_key($props, array_flip(['on', 'from', 'to', 'stage']));
        }
        foreach (['old' => 'new', 'before' => 'after'] as $old => $new) {
            if (array_key_exists($old, $props) || array_key_exists($new, $props)) {
                $parts[] = __('admin.log_before_after').' — '.self::value($old, $props[$old] ?? null).' → '.self::value($new, $props[$new] ?? null);
                unset($props[$old], $props[$new]);
            }
        }
        unset($props['agent']); // chaîne technique du navigateur : conservée en base, non affichée
        $auth = str_starts_with($l->action, 'auth.');
        $period = str_starts_with($l->action, 'partner.commission_');
        foreach ($props as $k => $v) {
            $label = match (true) {
                $auth && $k === 'method' => __('admin.log_auth_method'),
                ! $period && $k === 'from' => __('admin.log_keys')['before'] ?? 'Avant',
                ! $period && $k === 'to' => __('admin.log_keys')['after'] ?? 'Après',
                default => self::keyLabel((string) $k),
            };
            $parts[] = $label.' : '.self::value((string) $k, $v);
        }

        return implode(' · ', array_filter($parts));
    }

    private static function periodLine(?string $label, mixed $pct, ?string $from, ?string $to): string
    {
        return trim(($label ? $label.' — ' : '').self::value('pct', $pct).', '.__('admin.log_period_range', ['from' => self::value('from', $from), 'to' => self::value('to', $to)]));
    }

    public static function keyLabel(string $key): string
    {
        return __('admin.log_keys')[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public static function value(string $key, mixed $v): string
    {
        if ($v === null || $v === '' || $v === 'sans fin') {
            return in_array($key, ['to', 'previous_to'], true) ? __('admin.log_no_end') : '—';
        }
        if (is_bool($v)) {
            return $v ? __('admin.yes') : __('admin.no');
        }
        if (is_array($v)) {
            $assoc = array_keys($v) !== range(0, count($v) - 1);

            return collect($v)->map(fn ($x, $k) => ($assoc ? self::keyLabel((string) $k).' : ' : '').self::value((string) $k, $x))->implode(', ');
        }
        if (in_array($key, ['pct', 'previous_pct'], true) && is_numeric($v)) {
            return rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',').' %';
        }
        if (is_string($v) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $v, $m)) {
            return "$m[2]/$m[1]";
        }
        if (is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}:\d{2}))?/', $v, $m)) {
            return "$m[3]/$m[2]/$m[1]".(isset($m[4]) ? ' '.$m[4] : '');
        }

        return is_string($v) ? (__('admin.log_values')[$v] ?? $v) : (string) $v;
    }

    public static function mobileCard(ActivityLog $l): string
    {
        return implode('', [
            '<div><strong>'.e(self::actionLabel($l->action)).'</strong></div>',
            '<div>'.e($l->created_at->format('d/m/Y H:i')).' · '.e($l->user->name ?? self::panelLabel($l->panel)).'</div>',
            '<div>'.e(self::subjectLabel($l)).'</div>',
            '<div style="overflow-wrap:anywhere">'.e(self::details($l)).'</div>',
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'subject']))
            ->columns([
                Tables\Columns\TextColumn::make('m_card')->label('')->state(fn (ActivityLog $l) => self::mobileCard($l))->html()->wrap()->hiddenFrom('xl'),
                Tables\Columns\TextColumn::make('created_at')->visibleFrom('xl')->label(__('admin.date'))->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('panel')->visibleFrom('xl')->label(__('admin.panel'))->badge()->formatStateUsing(fn (?string $state) => self::panelLabel($state)),
                Tables\Columns\TextColumn::make('action')->visibleFrom('xl')->label(__('admin.action'))->formatStateUsing(fn (string $state) => self::actionLabel($state))->wrap()->searchable(),
                Tables\Columns\TextColumn::make('user.name')->visibleFrom('xl')->label(__('admin.user'))->default('—')->searchable(),
                Tables\Columns\TextColumn::make('subject')->visibleFrom('xl')->label(__('admin.subject'))->wrap()
                    ->getStateUsing(fn (ActivityLog $l) => self::subjectLabel($l)),
                Tables\Columns\TextColumn::make('properties')->visibleFrom('xl')->label(__('admin.details'))
                    ->getStateUsing(fn (ActivityLog $l) => '<span style="overflow-wrap:anywhere">'.e(self::details($l)).'</span>')->html()->wrap(),
                Tables\Columns\TextColumn::make('ip')->visibleFrom('xl')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('panel')->label(__('admin.panel'))->options(fn () => __('admin.log_panels')),
                Tables\Filters\SelectFilter::make('partner')->label(__('admin.partner'))
                    ->options(fn () => Partner::orderBy('company_name')->pluck('company_name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->where('subject_type', (new Partner)->getMorphClass())->where('subject_id', $data['value'])
                        : $query),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListActivityLogs::route('/')];
    }
}
