<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:160']);
        $term = trim($data['q'] ?? '');
        $companies = Company::when($term, fn ($q) => $q->where('legal_name', 'like', '%'.addcslashes($term, '%_\\').'%'))->orderBy('legal_name')->paginate(20)->withQueryString();

        return view('companies.index', compact('companies', 'term'));
    }

    public function create(): View
    {
        return view('companies.form', ['company' => new Company]);
    }

    public function edit(Company $company): View
    {
        return view('companies.form', compact('company'));
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new Company);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        return $this->save($request, $company);
    }

    private function save(Request $request, Company $company): RedirectResponse
    {
        $request->validate(['cnpj' => ['required', 'string', 'max:18', 'regex:/^(?:[A-Za-z0-9]{12}[0-9]{2}|[A-Za-z0-9]{2}\.[A-Za-z0-9]{3}\.[A-Za-z0-9]{3}\/[A-Za-z0-9]{4}-[0-9]{2})$/']]);
        $cnpj = strtoupper(str_replace(['.', '/', '-'], '', $request->string('cnpj')->toString()));
        if (preg_match('/^(.)\1{13}$/', $cnpj)) {
            throw ValidationException::withMessages(['cnpj' => 'CNPJ inválido.']);
        }
        for ($length = 12; $length <= 13; $length++) {
            $sum = 0;
            $weight = 2;
            for ($i = $length - 1; $i >= 0; $i--) {
                $sum += (ord($cnpj[$i]) - 48) * $weight;
                $weight = $weight === 9 ? 2 : $weight + 1;
            }
            $remainder = $sum % 11;
            $digit = $remainder < 2 ? 0 : 11 - $remainder;
            if ($digit !== (int) $cnpj[$length]) {
                throw ValidationException::withMessages(['cnpj' => 'Dígitos verificadores do CNPJ inválidos.']);
            }
        }
        $request->merge(['cnpj' => $cnpj]);
        $data = $request->validate(['cnpj' => ['required', Rule::unique('companies')->ignore($company->id)], 'legal_name' => 'required|string|max:160', 'trade_name' => 'nullable|string|max:160', 'phone' => 'nullable|string|max:25', 'email' => 'nullable|email|max:254', 'active' => 'required|boolean', 'postal_code' => ['nullable', 'regex:/^[0-9]{5}-?[0-9]{3}$/'], 'street' => 'nullable|string|max:160', 'address_number' => 'nullable|string|max:30', 'address_complement' => 'nullable|string|max:160', 'district' => 'nullable|string|max:160', 'city' => 'nullable|string|max:160', 'state' => ['nullable', 'regex:/^[A-Z]{2}$/']]);
        if ($company->exists) {
            $request->validate(['lock_version' => 'required|integer|min:1']);
        }
        DB::transaction(function () use ($request, $company, $data) {
            DB::table('practice')->where('id', 1)->lockForUpdate()->firstOrFail();
            $record = $company->exists ? Company::lockForUpdate()->findOrFail($company->id) : $company;
            if ($record->exists && $record->lock_version !== (int) $request->input('lock_version')) {
                throw ValidationException::withMessages(['company' => 'Cadastro alterado. Reabra a página antes de salvar.']);
            }
            if (Company::where('cnpj', $data['cnpj'])->when($record->exists, fn ($q) => $q->where('id', '!=', $record->id))->exists()) {
                throw ValidationException::withMessages(['cnpj' => 'CNPJ já cadastrado.']);
            }
            $action = $record->exists ? 'updated' : 'created';
            $record->fill($data);
            if ($record->exists) {
                $record->lock_version++;
            }
            $record->save();
            Audit::record('companies.'.$action, 'company', $record->id);
        });

        return to_route('companies.index')->with('status','Empresa salva.');
    }
}
