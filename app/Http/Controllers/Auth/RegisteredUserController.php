<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganizationForUser;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RegisteredUserController extends Controller
{
    public function store(
        RegisterUserRequest $request,
        ProvisionOrganizationForUser $provisioner,
    ): RedirectResponse {
        $data = $request->validated();

        [$user, $organization] = DB::transaction(function () use ($data, $provisioner): array {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $organization = $provisioner->handle($user, $data['organization_name']);

            return [$user, $organization];
        });

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        $mainBranch = $organization->branches()->where('is_main', true)->firstOrFail();
        $request->session()->put([
            'current_organization_id' => $organization->getKey(),
            'current_branch_id' => $mainBranch->getKey(),
        ]);

        return redirect()->route('dashboard');
    }
}
