<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::withCount('users')->orderBy('name')->get();

        return view('admin.plans.index', compact('plans'));
    }

    public function create()
    {
        return view('admin.plans.create');
    }

    public function store(Request $request)
    {
        $request->validate($this->rules());

        Plan::create($request->only('name', 'max_pdfs_per_month', 'max_envelopes_per_month'));

        return redirect()->route('admin.plans.index')->with('success', 'Plano criado com sucesso.');
    }

    public function edit(Plan $plan)
    {
        return view('admin.plans.edit', compact('plan'));
    }

    public function update(Request $request, Plan $plan)
    {
        $request->validate($this->rules());

        $plan->update($request->only('name', 'max_pdfs_per_month', 'max_envelopes_per_month'));

        return redirect()->route('admin.plans.index')->with('success', 'Plano atualizado com sucesso.');
    }

    public function destroy(Plan $plan)
    {
        $plan->delete();

        return redirect()->route('admin.plans.index')->with('success', 'Plano removido. Clientes atribuídos ficaram sem plano.');
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'max_pdfs_per_month' => ['required', 'integer', 'min:0'],
            'max_envelopes_per_month' => ['required', 'integer', 'min:0'],
        ];
    }
}
