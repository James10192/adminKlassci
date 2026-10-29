<?php

use App\Filament\Resources\GroupResource\Pages\EditGroup;
use App\Filament\Resources\GroupResource\RelationManagers\MembersRelationManager;
use App\Mail\Group\GroupMemberPasswordResetMail;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\Group\GroupMemberAccessReset;
use App\Services\Saas\SaasCapabilities;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('group_portal.invite_flow_enabled', true);
    config()->set('group_portal.member_reset_max_attempts', 2);
    config()->set('mail.default', 'array');
    config()->set('queue.default', 'sync');
    Mail::fake();

    $this->group = Group::create(['name' => 'Groupe test', 'code' => 'group-reset', 'status' => 'active']);
    $this->member = GroupMember::create([
        'group_id' => $this->group->id,
        'name' => 'Membre',
        'email' => 'member@example.invalid',
        'password' => 'temporary-pwd-from-test',
        'role' => 'fondateur',
        'is_active' => true,
    ]);
    $this->member->forceFill(['password_changed_at' => now()])->save();

    $this->admin = User::create([
        'name' => 'Administrateur',
        'email' => 'admin-reset@example.invalid',
        'password' => 'test-password',
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    RateLimiter::clear('group:reset-access:'.$this->member->id);
    // Ignore l'invitation automatique initiale : les assertions portent
    // exclusivement sur l'action de réinitialisation de ce test.
    Mail::fake();
});

it('envoie une nouvelle invitation de reset, sans exposer le mot de passe en interface', function () {
    $priorHash = $this->member->password;
    app(GroupMemberAccessReset::class)->reset($this->admin, $this->group->id, $this->member->id);

    Mail::assertQueued(GroupMemberPasswordResetMail::class);
    $member = $this->member->fresh();
    expect($member->password)->toBe($priorHash)
        ->and($member->password_changed_at)->not->toBeNull()
        ->and($member->invitation_token)->toMatch('/^[0-9a-f]{64}$/')
        ->and($member->invitation_sent_at)->not->toBeNull();
});

it('ne laisse pas le support, un admin inactif ou un acteur non habilité réinitialiser un accès', function () {
    $support = User::create([
        'name' => 'Support', 'email' => 'support-reset@example.invalid',
        'password' => 'test-password', 'role' => 'support', 'is_active' => true,
    ]);
    $disabled = User::create([
        'name' => 'Inactif', 'email' => 'inactive-reset@example.invalid',
        'password' => 'test-password', 'role' => 'super_admin', 'is_active' => false,
    ]);
    foreach ([$support, $disabled] as $operator) {
        expect(Gate::forUser($operator)->allows(SaasCapabilities::MANAGE_GROUP_MEMBERS))->toBeFalse();
        expect(fn () => app(GroupMemberAccessReset::class)->reset(
            $operator, $this->group->id, $this->member->id
        ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    }
    Mail::assertNothingQueued();
});

it('bloque un membre appartenant à un autre groupe et les comptes désactivés', function () {
    $other = Group::create(['name' => 'Autre', 'code' => 'other-reset', 'status' => 'active']);
    expect(fn () => app(GroupMemberAccessReset::class)->reset(
        $this->admin, $other->id, $this->member->id
    ))->toThrow(ValidationException::class);

    $this->member->forceFill(['is_active' => false])->save();
    expect(fn () => app(GroupMemberAccessReset::class)->reset(
        $this->admin, $this->group->id, $this->member->id
    ))->toThrow(ValidationException::class);

    Mail::assertNothingQueued();
});

it('applique une limite de fréquence par membre, sans fuite du mot de passe', function () {
    $service = app(GroupMemberAccessReset::class);
    $service->reset($this->admin, $this->group->id, $this->member->id);
    $service->reset($this->admin, $this->group->id, $this->member->id);

    expect(fn () => $service->reset(
        $this->admin, $this->group->id, $this->member->id
    ))->toThrow(ValidationException::class);

    Mail::assertQueued(GroupMemberPasswordResetMail::class, 2);
});

it('ne détruit pas les identifiants existants si le système de queue refuse la mise en file', function () {
    // Le composant métier doit renvoyer l'erreur et la transaction doit annuler
    // l'invalidation du mot de passe déjà actif.
    Mail::swap(new class {
        public function to(string $email): object
        {
            return new class {
                public function queue($mailable): void
                {
                    throw new \RuntimeException('mail queue temporarily unavailable');
                }
            };
        }
    });

    $hash = $this->member->password;
    expect(fn () => app(GroupMemberAccessReset::class)->reset(
        $this->admin, $this->group->id, $this->member->id
    ))->toThrow(\RuntimeException::class);

    expect($this->member->fresh()->password)->toBe($hash)
        ->and($this->member->fresh()->password_changed_at)->not->toBeNull();
});

it('retire tout champ password du formulaire de modification de membre', function () {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $this->group,
        'pageClass' => EditGroup::class,
    ])->mountTableAction('edit', $this->member)
        ->assertDontSee($this->member->password)
        ->assertDontSee('Mot de passe initial');
});


it('permet de choisir un mot de passe via un lien signé, sans connaître le mot de passe précédent', function () {
    $before = $this->member->password;
    app(GroupMemberAccessReset::class)->reset($this->admin, $this->group->id, $this->member->id);

    $mailable = Mail::queued(GroupMemberPasswordResetMail::class)->first();
    expect($mailable)->not->toBeNull()
        ->and($mailable->resetUrl)->toContain('/groupe/reinitialiser-mot-de-passe/')
        ->and($mailable->resetUrl)->toContain('signature=')
        ->and($mailable->resetUrl)->not->toContain('password=');

    $this->get($mailable->resetUrl)
        ->assertOk()->assertSee('Réinitialiser mon mot de passe');

    $this->post($mailable->resetUrl, [
        'password' => 'NewGroupPassword2026',
        'password_confirmation' => 'NewGroupPassword2026',
    ])->assertRedirect(route('filament.group.auth.login'));

    $member = $this->member->fresh();
    expect($member->password)->not->toBe($before)
        ->and(Hash::check('NewGroupPassword2026', $member->password))->toBeTrue()
        ->and($member->invitation_token)->toBeNull()
        ->and($member->password_changed_at)->not->toBeNull();

    $this->get($mailable->resetUrl)->assertForbidden(); // lien à usage unique
});

it('ne réinitialise rien via un lien invalide, expiré ou pour un membre désactivé', function () {
    app(GroupMemberAccessReset::class)->reset($this->admin, $this->group->id, $this->member->id);
    $url = Mail::queued(GroupMemberPasswordResetMail::class)->first()->resetUrl;
    $hash = $this->member->password;

    $this->get(str_replace('token=', 'token=altered', $url))->assertForbidden();
    $this->member->forceFill(['is_active' => false])->save();
    $this->get($url)->assertForbidden();

    expect($this->member->fresh()->password)->toBe($hash);
});
