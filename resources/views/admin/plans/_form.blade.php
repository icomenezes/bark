<div>
    <label class="block text-sm text-gray-300 mb-1">Nome do plano</label>
    <input type="text" name="name" value="{{ old('name', $plan->name ?? '') }}"
           placeholder="Básico"
           class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
    @error('name') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
</div>

<div>
    <label class="block text-sm text-gray-300 mb-1">Máximo de PDFs assinados por mês</label>
    <input type="number" name="max_pdfs_per_month" min="0" value="{{ old('max_pdfs_per_month', $plan->max_pdfs_per_month ?? '') }}"
           class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
    @error('max_pdfs_per_month') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
</div>

<div>
    <label class="block text-sm text-gray-300 mb-1">Máximo de envelopes por mês</label>
    <input type="number" name="max_envelopes_per_month" min="0" value="{{ old('max_envelopes_per_month', $plan->max_envelopes_per_month ?? '') }}"
           class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
    @error('max_envelopes_per_month') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
</div>
