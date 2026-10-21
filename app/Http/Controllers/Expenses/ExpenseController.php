<?php

namespace App\Http\Controllers\Expenses;

use App\Data\Expenses\CreateExpenseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\StoreExpenseRequest;
use App\Models\CashShift;
use App\Models\Expense;
use App\Models\Organization;
use App\Services\Expenses\CreateExpense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewReports', $organization);

        $branch = $request->attributes->get('currentBranch');
        abort_if($branch === null, 404);

        $expenses = Expense::query()
            ->forOrganization((int) $organization->getKey())
            ->where('branch_id', $branch->getKey())
            ->with('creator:id,name')
            ->latest('expense_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(static fn (Expense $expense): array => [
                'id' => $expense->getKey(),
                'category' => $expense->category,
                'description' => $expense->description,
                'amount' => $expense->amount,
                'paymentMethod' => $expense->payment_method,
                'expenseDate' => $expense->expense_date?->format('Y-m-d'),
                'notes' => $expense->notes,
                'creatorName' => $expense->creator?->name,
            ]);

        return Inertia::render('Expenses/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'expenses' => $expenses,
            'canManageExpenses' => $request->user()->can('manageExpenses', $organization),
            'hasOpenCashShift' => CashShift::query()
                ->where('cashier_id', $request->user()->getKey())
                ->where('organization_id', $organization->getKey())
                ->where('branch_id', $branch->getKey())
                ->where('status', 'open')
                ->exists(),
            'today' => now($organization->timezone ?: config('app.timezone'))->toDateString(),
            'flashMessage' => $request->session()->get('success'),
        ]);
    }

    public function store(StoreExpenseRequest $request, CreateExpense $createExpense): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');
        abort_if($branch === null, 404);

        $createExpense->handle(CreateExpenseData::fromValidated(
            $request->validated(),
            (int) $organization->getKey(),
            (int) $branch->getKey(),
            (int) $request->user()->getKey(),
        ));

        return redirect()->route('expenses.index')->with('success', 'تم تسجيل المصروف بنجاح.');
    }
}
