<?php

namespace App\Http\Controllers\Group;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Handles the /groupe/set-password flow triggered by EnsurePasswordChanged
 * middleware when `password_changed_at` is null on the authenticated member.
 *
 * Form is a plain Blade view (not a Filament page) so the middleware exempt
 * can match a simple named route pattern without Filament's panel lifecycle
 * getting in the way. Rate limited to 6 req/min per IP via the route
 * definition (not the middleware itself, so legitimate typos don't lock out).
 */
class SetPasswordController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $member = auth('group')->user();

        // Short-circuit if the member has already rotated — shouldn't happen
        // because the middleware skips them, but defensive against session
        // staleness after an admin-side reset.
        if (! $member->mustChangePassword()) {
            return redirect()->route('filament.group.pages.dashboard');
        }

        return view('groupe.set-password', [
            'member' => $member,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
        ]);

        auth('group')->user()->recordPasswordRotation($validated['password']);

        session()->forget('gp_invitation_member_id');

        return redirect()->route('filament.group.pages.dashboard')
            ->with('status', 'Mot de passe mis à jour. Bienvenue sur le portail.');
    }
}
