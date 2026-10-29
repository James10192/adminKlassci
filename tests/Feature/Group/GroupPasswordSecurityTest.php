<?php

use App\Filament\Resources\GroupResource\Pages\EditGroup;
use App\Filament\Resources\GroupResource\RelationManagers\MembersRelationManager;
use App\Models\Group;
use App\Models\GroupMember;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('group_portal.invite_flow_enabled', false);
    $this->group = Group::create(['name' => 'Groupe démo', 'code' => 'groupe-demo', 'status' => 'active']);
    $this->member = GroupMember::create([
        'group_id' => $this->group->id,
        'name' => 'Direction exemple',
        'email' => 'direction@example.invalid',
        'password' => Hash::make('InitialStrong123'),
        'role' => 'fondateur',
        'is_active' => true,
    ]);
    $this->member->forceFill(['password_changed_at' => null])->save();
});

it('ouvre la vraie page de définition du mot de passe sans erreur 500', function () {
    $this->actingAs($this->member, 'group');
    $this->get('/groupe/definir-mot-de-passe')
        ->assertOk()
        ->assertSee('Nouveau mot de passe')
        ->assertSee('password_confirmation')
        ->assertSee('csrf', false);
});

it('un visiteur non authentifié ne voit pas la page de création de mot de passe', function () {
    $this->get('/groupe/definir-mot-de-passe')->assertRedirect();
});

it('valide une rotation de mot de passe et révoque le token d’invitation', function () {
    $this->member->forceFill(['invitation_token' => str_repeat('a', 64)])->save();
    $this->actingAs($this->member, 'group');

    $this->post('/groupe/definir-mot-de-passe', [
        'password' => 'NewStrongPassword123',
        'password_confirmation' => 'NewStrongPassword123',
    ])->assertRedirect();

    $member = $this->member->fresh();
    expect(Hash::check('NewStrongPassword123', $member->password))->toBeTrue()
        ->and($member->password_changed_at)->not->toBeNull()
        ->and($member->invitation_token)->toBeNull();
});

it('refuse les mots de passe trop faibles ou les confirmations différentes sans remplacer l’ancien', function () {
    $this->actingAs($this->member, 'group');
    $oldHash = $this->member->password;

    $this->post('/groupe/definir-mot-de-passe', [
        'password' => 'weak',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors('password');

    expect($this->member->fresh()->password)->toBe($oldHash)
        ->and($this->member->fresh()->password_changed_at)->toBeNull();
});

it('n’expose pas le hash d’un membre lors du montage de son formulaire d’édition', function () {
    $admin = \App\Models\User::create([
        'name' => 'Administrateur test',
        'email' => 'admin-group-member@example.invalid',
        'password' => 'test-password',
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $this->group,
        'pageClass' => EditGroup::class,
    ])->mountTableAction('edit', $this->member)
        ->assertDontSee($this->member->password);
});
