<?php

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\CreateOrganizationRequest;
use App\Services\Organizations\ProvisionOrganizationForUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationSetupController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user()->organizations()->exists()) {
            return redirect()->route('dashboard');
        }

        if (($request->user()->registration_type ?? 'owner') === 'employee') {
            return Inertia::render('Organizations/EmployeePending', [
                'userName' => $request->user()->name,
                'userEmail' => $request->user()->email,
                'status' => $request->session()->get('status'),
            ]);
        }

        return Inertia::render('Organizations/Setup', [
            'userName' => $request->user()->name,
        ]);
    }

    public function store(
        CreateOrganizationRequest $request,
        ProvisionOrganizationForUser $provisioner,
    ): RedirectResponse {
        if (($request->user()->registration_type ?? 'owner') === 'employee') {
            return redirect()
                ->route('organization.setup')
                ->with('status', 'حساب الموظف لا ينشئ مؤسسة جديدة. اطلب من مدير مؤسستك ربط حسابك من صفحة أعضاء الفريق.');
        }

        if ($request->user()->organizations()->exists()) {
            return redirect()->route('dashboard');
        }

        $organization = $provisioner->handle(
            $request->user(),
            $request->validated('organization_name'),
        );

        $mainBranch = $organization->branches()->where('is_main', true)->firstOrFail();

        $request->session()->put([
            'current_organization_id' => $organization->getKey(),
            'current_branch_id' => $mainBranch->getKey(),
        ]);

        return redirect()->route('dashboard')->with('success', 'تم إعداد المؤسسة والفرع الرئيسي بنجاح.');
    }
}
