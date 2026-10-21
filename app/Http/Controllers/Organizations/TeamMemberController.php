<?php

namespace App\Http\Controllers\Organizations;

use App\Data\Organizations\TeamMemberData;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\StoreTeamMemberRequest;
use App\Http\Requests\Organizations\UpdateTeamMemberRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\ManageOrganizationTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamMemberController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewTeam', $organization);

        $actorRole = OrganizationRole::tryFrom((string) $request->attributes->get('currentOrganizationRole'));
        $organizationBranchIds = $organization->branches()->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $members = $organization->users()
            ->with(['branches' => fn ($query) => $query->whereIn('branches.id', $organizationBranchIds)
                ->select(['branches.id', 'branches.organization_id', 'branches.name', 'branches.code'])])
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(function (User $member) use ($actorRole, $request, $organization): array {
                $memberRole = OrganizationRole::tryFrom((string) $member->pivot->role);
                $canEdit = $memberRole !== null
                    && $memberRole !== OrganizationRole::OWNER
                    && (int) $member->getKey() !== (int) $request->user()->getKey()
                    && ($actorRole === OrganizationRole::OWNER || $memberRole !== OrganizationRole::ADMIN);

                return [
                    'id' => (int) $member->getKey(),
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => (string) $member->pivot->role,
                    'roleLabel' => $memberRole?->label() ?? (string) $member->pivot->role,
                    'branchIds' => $member->branches->pluck('id')->map(static fn ($id): int => (int) $id)->values(),
                    'branchNames' => $member->branches->pluck('name')->values(),
                    'isCurrentUser' => (int) $member->getKey() === (int) $request->user()->getKey(),
                    'canEdit' => $canEdit,
                    'canRemove' => $canEdit,
                ];
            })->values();

        $roles = collect(OrganizationRole::cases())
            ->reject(static fn (OrganizationRole $role): bool => $role === OrganizationRole::OWNER)
            ->reject(static fn (OrganizationRole $role): bool => $role === OrganizationRole::ADMIN && $actorRole !== OrganizationRole::OWNER)
            ->map(static fn (OrganizationRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
            ])->values();

        return Inertia::render('Team/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'members' => $members,
            'branches' => $organization->branches()->where('status', 'active')->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(static fn ($branch): array => [
                    'id' => (int) $branch->getKey(),
                    'name' => $branch->name,
                    'code' => $branch->code,
                ])->values(),
            'roles' => $roles,
            'canAssignAdmin' => $actorRole === OrganizationRole::OWNER,
            'flashMessage' => $request->session()->get('success'),
        ]);
    }

    public function store(StoreTeamMemberRequest $request, ManageOrganizationTeam $team): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageTeam', $organization);

        $team->addByEmail(
            $organization,
            $request->user(),
            (string) $request->validated('email'),
            TeamMemberData::fromValidated($request->validated()),
        );

        return redirect()->route('team-members.index')->with('success', 'تمت إضافة الموظف للمؤسسة وتحديد صلاحياته.');
    }

    public function update(
        UpdateTeamMemberRequest $request,
        User $teamMember,
        ManageOrganizationTeam $team,
    ): RedirectResponse {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageTeam', $organization);

        $team->updateMember(
            $organization,
            $request->user(),
            $teamMember,
            TeamMemberData::fromValidated($request->validated()),
        );

        return redirect()->route('team-members.index')->with('success', 'تم تحديث دور الموظف والفروع المسموح بها.');
    }

    public function destroy(Request $request, User $teamMember, ManageOrganizationTeam $team): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageTeam', $organization);

        $team->removeMember($organization, $request->user(), $teamMember);

        return redirect()->route('team-members.index')->with('success', 'تمت إزالة عضوية الموظف من المؤسسة.');
    }
}
