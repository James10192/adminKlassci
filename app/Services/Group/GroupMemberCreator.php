<?php

namespace App\Services\Group;

use App\Enums\GroupMemberRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\Saas\SaasCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Group-scoped creation, shared by contextual actions; never accepts a client group_id. */
final class GroupMemberCreator
{
    public function create(User $actor, int $groupId, array $input): GroupMember
    {
        Gate::forUser($actor)->authorize(SaasCapabilities::MANAGE_GROUP_MEMBERS);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('group_members', 'email')],
            'username' => ['nullable', 'string', 'max:80', 'regex:'.\App\Support\Group\MemberUsernameRules::PATTERN, Rule::unique('group_members', 'username')],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', Rule::in(array_keys(GroupMemberRole::options()))],
            'phone' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ])->validate();

        $email = $data['email'] ?? null;
        $needsManual = ! config('group_portal.invite_flow_enabled', false) || blank($email);

        if ($needsManual && blank($data['password'] ?? null)) {
            throw ValidationException::withMessages([
                'password' => "Renseignez un mot de passe initial ou activez l'invitation avec une adresse e-mail.",
            ]);
        }

        $data['password'] = $needsManual
            ? $data['password']
            : app(TemporaryPasswordGenerator::class)->generate();

        return DB::transaction(function () use ($actor, $groupId, $data): GroupMember {
            $group = Group::query()->whereKey($groupId)->lockForUpdate()->firstOrFail();
            if ($group->status !== 'active') {
                throw ValidationException::withMessages([
                    'group' => "Ce groupe est suspendu. L'ajout de membres est momentanément indisponible.",
                ]);
            }

            // L'observer existant gère la génération du username et l'invitation
            // sans que la page connaisse le transport du message.
            $member = $group->members()->create($data);

            Log::info('group.member.created', [
                'group_id' => $group->id,
                'member_id' => $member->id,
                'operator_id' => $actor->id,
                'source' => 'group.workspace',
            ]);

            return $member;
        });
    }
}
