<?php

use App\Models\Group;
use App\Models\GroupMember;
use App\Services\Group\UsernameGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->group = Group::create(['name' => 'Test Group', 'code' => 'test-uname', 'status' => 'active']);
});

function makeMember(int $groupId, array $overrides = []): GroupMember
{
    return GroupMember::create(array_merge([
        'group_id' => $groupId,
        'name' => 'Jean Diomandé',
        'email' => 'jean@test.fr',
        'password' => 'x',
        'role' => 'fondateur',
        'is_active' => true,
    ], $overrides));
}

it('does NOT generate a username when an email is provided', function () {
    $member = makeMember($this->group->id, ['email' => 'withemail@test.fr']);

    expect($member->refresh()->username)->toBeNull();
});

it('auto-generates a username when email is missing', function () {
    $member = makeMember($this->group->id, [
        'name' => 'Jean Diomandé',
        'email' => null,
    ]);

    expect($member->refresh()->username)->toBe('jean.diomande');
});

it('dedups colliding usernames with a numeric suffix', function () {
    makeMember($this->group->id, ['name' => 'Jean Diomandé', 'email' => null]);
    $second = makeMember($this->group->id, [
        'name' => 'Jean Diomandé',
        'email' => null,
    ]);

    expect($second->refresh()->username)->toBe('jean.diomande.2');
});

it('strips accents and diacritics', function () {
    $member = makeMember($this->group->id, [
        'name' => 'Éléonore Koffié',
        'email' => null,
    ]);

    expect($member->refresh()->username)->toBe('eleonore.koffie');
});

it('collapses three-part names to first.last', function () {
    $member = makeMember($this->group->id, [
        'name' => 'Jean Baptiste Diomandé',
        'email' => null,
    ]);

    expect($member->refresh()->username)->toBe('jean.diomande');
});

it('honours an explicit username supplied by the admin', function () {
    $member = makeMember($this->group->id, [
        'name' => 'Whatever',
        'email' => null,
        'username' => 'custom.handle',
    ]);

    expect($member->refresh()->username)->toBe('custom.handle');
});

it('falls back to a random suffix when the name is pathological', function () {
    $member = makeMember($this->group->id, [
        'name' => '!!!',
        'email' => null,
    ]);

    // Random fallback starts with "membre."
    expect($member->refresh()->username)->toStartWith('membre.');
});

it('UsernameGenerator service generates a deterministic slug from a name', function () {
    $generator = new UsernameGenerator();

    expect($generator->generate('Marie Kouadio'))->toBe('marie.kouadio');
});

it('UsernameGenerator appends .2 when the base is taken', function () {
    makeMember($this->group->id, ['name' => 'Paul Koffi', 'email' => null]);

    $generator = new UsernameGenerator();

    expect($generator->generate('Paul Koffi'))->toBe('paul.koffi.2');
});
