<?php

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchOrganizationController extends Controller
{
    public function __invoke(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('view', $organization);

        $request->session()->put('current_organization_id', $organization->getKey());
        $request->session()->forget('current_branch_id');

        return redirect()->route('dashboard')->with('success', 'تم تبديل المؤسسة الحالية.');
    }
}
