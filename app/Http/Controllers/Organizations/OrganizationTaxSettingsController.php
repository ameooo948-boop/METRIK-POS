<?php

namespace App\Http\Controllers\Organizations;

use App\Events\OperationalReportDataChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateOrganizationTaxSettingsRequest;
use App\Models\Organization;
use App\Support\DecimalMoney;
use App\Support\OrganizationTaxSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationTaxSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageTaxSettings', $organization);

        $settings = is_array($organization->settings) ? $organization->settings : [];
        $tax = is_array($settings['tax'] ?? null) ? $settings['tax'] : [];

        return Inertia::render('Organizations/TaxSettings', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'taxSettings' => [
                'enabled' => (bool) ($tax['enabled'] ?? false),
                'ratePercent' => (string) ($tax['rate_percent'] ?? '0.00'),
                'effectiveRateBasisPoints' => OrganizationTaxSettings::rateBasisPoints($organization),
                'mode' => 'exclusive',
            ],
            'flashMessage' => $request->session()->get('success'),
        ]);
    }

    public function update(UpdateOrganizationTaxSettingsRequest $request): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $validated = $request->validated();
        $settings = is_array($organization->settings) ? $organization->settings : [];
        $settings['tax'] = [
            'enabled' => (bool) $validated['enabled'],
            'rate_percent' => DecimalMoney::fromCents(DecimalMoney::toCents((string) $validated['rate_percent'])),
            'mode' => 'exclusive',
        ];

        $organization->forceFill(['settings' => $settings])->save();
        event(new OperationalReportDataChanged((int) $organization->getKey()));

        return redirect()->route('organization.tax-settings.index')
            ->with('success', 'تم حفظ إعدادات ضريبة المبيعات. ستُحفظ النسبة المستخدمة داخل كل فاتورة جديدة.');
    }
}
