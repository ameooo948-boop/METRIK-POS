<?php

namespace App\Services\Organizations;

use App\Data\Organizations\TeamMemberData;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Coordinates organization membership and branch access changes transactionally.
 * The current organization and actor are supplied by trusted middleware/auth state.
 */
final class ManageOrganizationTeam
{
    public function addByEmail(Organization $organization, User $actor, string $email, TeamMemberData $data): User
    {
        $target = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if (! $target) {
            throw ValidationException::withMessages([
                'email' => 'هذا البريد غير مرتبط بحساب. يجب أن ينشئ المستخدم حسابًا أولًا.',
            ]);
        }

        if ($organization->users()->whereKey($target->getKey())->exists()) {
            throw ValidationException::withMessages([
                'email' => 'هذا المستخدم عضو بالفعل في المؤسسة.',
            ]);
        }

        $actorRole = $this->actorRole($organization, $actor);
        $this->assertCanAssignRole($actorRole, $data->role);
        $this->assertValidBranches($organization, $data);

        return DB::transaction(function () use ($organization, $target, $data): User {
            $organization->users()->attach($target->getKey(), [
                'role' => $data->role,
                'is_default' => false,
            ]);

            $this->syncBranchAssignments($organization, $target, $data->branchIds);

            return $target->refresh();
        });
    }

    public function updateMember(Organization $organization, User $actor, User $member, TeamMemberData $data): void
    {
        $actorRole = $this->actorRole($organization, $actor);
        $currentRole = $this->memberRole($organization, $member);
        $this->assertCanManageMember($actor, $actorRole, $member, $currentRole);
        $this->assertCanAssignRole($actorRole, $data->role);
        $this->assertValidBranches($organization, $data);

        DB::transaction(function () use ($organization, $member, $data): void {
            $organization->users()->updateExistingPivot($member->getKey(), [
                'role' => $data->role,
            ]);

            $this->syncBranchAssignments($organization, $member, $data->branchIds);
        });
    }

    public function removeMember(Organization $organization, User $actor, User $member): void
    {
        $actorRole = $this->actorRole($organization, $actor);
        $memberRole = $this->memberRole($organization, $member);
        $this->assertCanManageMember($actor, $actorRole, $member, $memberRole);

        DB::transaction(function () use ($organization, $member): void {
            $organizationBranchIds = $organization->branches()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $assignedBranchIds = $member->branches()
                ->whereIn('branches.id', $organizationBranchIds)
                ->pluck('branches.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($assignedBranchIds !== []) {
                $member->branches()->detach($assignedBranchIds);
            }

            $organization->users()->detach($member->getKey());
        });
    }

    private function actorRole(Organization $organization, User $actor): OrganizationRole
    {
        $membership = $organization->users()->whereKey($actor->getKey())->first();
        $role = OrganizationRole::tryFrom((string) ($membership?->pivot?->role ?? ''));

        if (! $role?->canManageTeam()) {
            throw new HttpException(403, 'ليس لديك صلاحية إدارة أعضاء المؤسسة.');
        }

        return $role;
    }

    private function memberRole(Organization $organization, User $member): OrganizationRole
    {
        $membership = $organization->users()->whereKey($member->getKey())->first();

        if (! $membership) {
            throw new HttpException(404, 'العضو غير موجود في المؤسسة الحالية.');
        }

        return OrganizationRole::tryFrom((string) $membership->pivot->role)
            ?? throw new HttpException(403, 'دور العضوية غير معروف.');
    }

    private function assertCanManageMember(
        User $actor,
        OrganizationRole $actorRole,
        User $member,
        OrganizationRole $memberRole,
    ): void {
        if ((int) $actor->getKey() === (int) $member->getKey()) {
            throw new HttpException(403, 'لا يمكنك تعديل عضويتك بنفسك.');
        }

        if ($memberRole === OrganizationRole::OWNER) {
            throw new HttpException(403, 'لا يمكن تعديل أو إزالة مالك المؤسسة من إدارة الفريق.');
        }

        if ($actorRole !== OrganizationRole::OWNER && $memberRole === OrganizationRole::ADMIN) {
            throw new HttpException(403, 'إدارة مديري المؤسسة متاحة للمالك فقط.');
        }
    }

    private function assertCanAssignRole(OrganizationRole $actorRole, string $targetRole): void
    {
        if ($targetRole === OrganizationRole::OWNER->value) {
            throw ValidationException::withMessages(['role' => 'لا يمكن تعيين دور المالك من شاشة إدارة الفريق.']);
        }

        if ($targetRole === OrganizationRole::ADMIN->value && $actorRole !== OrganizationRole::OWNER) {
            throw new HttpException(403, 'تعيين مدير مؤسسة متاح للمالك فقط.');
        }
    }

    private function assertValidBranches(Organization $organization, TeamMemberData $data): void
    {
        if ($data->role === OrganizationRole::ADMIN->value) {
            return;
        }

        $uniqueIds = array_values(array_unique($data->branchIds));
        $validCount = $organization->branches()->whereIn('id', $uniqueIds)->count();

        if ($uniqueIds === [] || $validCount !== count($uniqueIds)) {
            throw ValidationException::withMessages([
                'branch_ids' => 'حدد فرعًا واحدًا على الأقل من فروع المؤسسة الحالية فقط.',
            ]);
        }
    }

    /** @param list<int> $branchIds */
    private function syncBranchAssignments(Organization $organization, User $member, array $branchIds): void
    {
        $organizationBranchIds = $organization->branches()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $existingIds = $member->branches()
            ->whereIn('branches.id', $organizationBranchIds)
            ->pluck('branches.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($existingIds !== []) {
            $member->branches()->detach($existingIds);
        }

        if ($branchIds !== []) {
            // Non-admin roles must only receive branch IDs validated against this organization.
            $member->branches()->attach($branchIds);
        }
    }
}
