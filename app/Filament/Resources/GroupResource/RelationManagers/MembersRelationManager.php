<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use App\Enums\GroupMemberRole;
use Livewire\Attributes\On;
use App\Models\GroupMember;
use App\Services\Group\GroupMemberAccessReset;
use App\Services\Group\TemporaryPasswordGenerator;
use App\Services\Saas\SaasCapabilities;
use Illuminate\Support\Facades\Gate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Membres du groupe';

    protected static ?string $recordTitleAttribute = 'name';

    public function isReadOnly(): bool
    {
        // Autorisation au niveau de chaque action, pas via read-only global
        // qui ferait disparaître des raccourcis dans certains contextes.
        return false;
    }

    #[On('group-workspace-updated')]
    public function refreshWorkspace(): void
    {
        // Filament recharge la table après une action rapide de la page.
    }

    public function form(Form $form): Form
    {
        return $form->schema(\App\Filament\Forms\Group\GroupMemberFields::make());
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn ($record) => $record->username ? '@' . $record->username : null),

                Tables\Columns\TextColumn::make('role')
                    ->label('Rôle')
                    ->badge()
                    ->color(fn (string $state): string => GroupMemberRole::tryFrom($state)?->badgeColor() ?? 'gray')
                    ->formatStateUsing(fn (string $state): string => GroupMemberRole::tryFrom($state)?->label() ?? $state),

                Tables\Columns\TextColumn::make('access_state')
                    ->label('Accès')
                    ->state(fn (GroupMember $record): string => $record->password_changed_at !== null
                        ? 'Mot de passe défini'
                        : ($record->invitation_sent_at !== null ? 'Activation en attente' : 'Accès à initialiser'))
                    ->badge()
                    ->color(fn (GroupMember $record): string => $record->password_changed_at !== null
                        ? 'success'
                        : 'warning')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Dernière connexion')
                    ->since()
                    ->placeholder('Jamais'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Ajouter un membre')
                    ->visible(fn (): bool => Gate::allows(SaasCapabilities::MANAGE_GROUP_MEMBERS))
                    ->before(fn () => Gate::authorize(SaasCapabilities::MANAGE_GROUP_MEMBERS))
                    ->mutateFormDataUsing(function (array $data): array {
                        // NOT NULL en base. L'observer remplace ensuite le
                        // secret provisoire par une invitation si activée.
                        if (blank($data['password'] ?? null)) {
                            $data['password'] = app(TemporaryPasswordGenerator::class)->generate();
                        }

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('resetAccess')
                    ->label('Réinitialiser l’accès')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Réinitialiser l’accès du membre ?')
                    ->modalDescription(
                        "Un lien sécurisé à usage unique sera envoyé à l’adresse e-mail du membre. Son mot de passe actuel reste utilisable jusqu’à sa confirmation."
                    )
                    ->modalSubmitActionLabel('Confirmer la réinitialisation')
                    ->visible(fn (GroupMember $record): bool =>
                        Gate::allows(SaasCapabilities::MANAGE_GROUP_MEMBERS)
                        && config('group_portal.invite_flow_enabled', false)
                        && $record->is_active
                        && filled($record->email))
                    ->action(function (GroupMember $record): void {
                        Gate::authorize(SaasCapabilities::MANAGE_GROUP_MEMBERS);

                        app(GroupMemberAccessReset::class)->reset(
                            auth()->user(),
                            (int) $this->getOwnerRecord()->getKey(),
                            (int) $record->getKey(),
                        );

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Réinitialisation prise en compte')
                            ->body("Le lien sécurisé a été mis en file d’envoi. Sa livraison n’est pas encore confirmée.")
                            ->send();
                    }),
                Tables\Actions\EditAction::make()
                    ->visible(fn (): bool => Gate::allows(SaasCapabilities::MANAGE_GROUP_MEMBERS))
                    ->before(fn () => Gate::authorize(SaasCapabilities::MANAGE_GROUP_MEMBERS)),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (): bool => Gate::allows(SaasCapabilities::MANAGE_GROUP_MEMBERS))
                    ->before(fn () => Gate::authorize(SaasCapabilities::MANAGE_GROUP_MEMBERS))
                    ->requiresConfirmation(),
            ]);
    }
}
