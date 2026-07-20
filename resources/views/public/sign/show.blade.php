<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $settings->company_name ?? config('app.name') }} — Assinar documento</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-950 text-gray-100 min-h-screen">
<div class="max-w-5xl mx-auto p-4 sm:p-6 space-y-4" x-data="signPage()">

    {{-- Cabeçalho --}}
    <div class="flex items-center gap-3">
        @if (!empty($settings->logo_url))
            <img src="{{ $settings->logo_url }}" alt="" class="h-10">
        @endif
        <div>
            <h1 class="text-lg font-semibold text-white">{{ $envelope->title }}</h1>
            <p class="text-xs text-gray-500">
                Enviado por {{ $envelope->user->name }} via {{ $settings->company_name ?? config('app.name') }}
                @if ($envelope->expires_at) · expira em {{ $envelope->expires_at->format('d/m/Y') }} @endif
            </p>
        </div>
    </div>

    @if ($envelope->message)
        <div class="bg-gray-900 border border-gray-800 rounded-lg px-4 py-3 text-sm text-gray-300">
            {{ $envelope->message }}
        </div>
    @endif

    @if (session('success'))
        <div class="bg-green-900/40 border border-green-700 text-green-300 px-4 py-3 rounded text-sm">
            {{ session('success') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="bg-red-900/40 border border-red-700 text-red-300 px-4 py-3 rounded text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Formulário de assinatura --}}
    <form method="POST" action="{{ route('public.sign.store', $signer->token) }}" @submit="prepare($event)"
          class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
        @csrf
        <input type="hidden" name="signature" x-ref="signature">
        <input type="hidden" name="signature_type" :value="tab">

        <h2 class="text-white font-semibold">Assinar como {{ $signer->name }}</h2>

        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-gray-300 mb-1">Nome completo *</label>
                <input type="text" name="name" required maxlength="255" value="{{ old('name', $signer->name) }}"
                       class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">CPF *</label>
                <input type="text" name="cpf" required placeholder="000.000.000-00" value="{{ old('cpf', $signer->cpf) }}"
                       @input="maskCpf($event)" maxlength="14"
                       class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                @error('cpf') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Assinatura: desenhar ou digitar --}}
        <div>
            <div class="flex gap-2 mb-2">
                <button type="button" @click="tab = 'drawn'"
                        class="px-3 py-1.5 rounded text-sm transition-colors"
                        :class="tab === 'drawn' ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400'">
                    Desenhar
                </button>
                <button type="button" @click="tab = 'typed'"
                        class="px-3 py-1.5 rounded text-sm transition-colors"
                        :class="tab === 'typed' ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400'">
                    Digitar
                </button>
            </div>

            <div x-show="tab === 'drawn'">
                <canvas x-ref="pad" width="400" height="150" class="border border-gray-700 rounded bg-white touch-none w-full max-w-md"></canvas>
                <button type="button" class="text-xs text-gray-400 hover:text-white mt-1" @click="pad.clear()">Limpar</button>
            </div>

            <div x-show="tab === 'typed'" x-cloak>
                <input type="text" x-model="typedName" placeholder="Digite seu nome"
                       class="w-full max-w-md bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                <p class="mt-2 text-2xl text-white max-w-md truncate" style="font-family: 'Segoe Script', cursive; font-style: italic;"
                   x-text="typedName || 'Prévia da assinatura'"></p>
            </div>
            @error('signature') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        {{-- OTP --}}
        @if ($signer->requiresOtp())
            <div class="border border-gray-800 rounded-lg p-4 space-y-2">
                <p class="text-sm text-gray-300">
                    Este documento exige verificação por código
                    ({{ $signer->channel === 'whatsapp' ? 'WhatsApp' : 'e-mail' }}).
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" form="otp-form"
                            class="px-3 py-2 rounded text-sm bg-gray-800 text-gray-300 hover:bg-gray-700 transition-colors">
                        Receber código
                    </button>
                    <input type="text" name="otp_code" inputmode="numeric" maxlength="6" placeholder="Código (6 dígitos)"
                           value="{{ old('otp_code') }}"
                           class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                </div>
                @error('otp_code') <p class="text-red-400 text-xs">{{ $message }}</p> @enderror
            </div>
        @endif

        <div class="border-t border-gray-800 pt-4 space-y-3">
            <p class="text-xs text-gray-500">
                Ao clicar em Assinar, declaro que li o documento e concordo em assinar eletronicamente.
            </p>
            <button type="submit"
                    class="w-full sm:w-auto px-6 py-2.5 rounded text-sm font-medium text-white transition-colors"
                    style="background-color: var(--color-primary, #1e40af);">
                Assinar documento
            </button>
        </div>
    </form>

    {{-- Documento --}}
    <div class="flex items-center gap-2 text-xs text-gray-500 px-1">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
        </svg>
        Role para conferir o documento completo abaixo
    </div>
    <div class="bg-gray-900 border border-gray-800 rounded-lg overflow-hidden">
        <iframe src="{{ route('public.sign.document', $signer->token) }}" class="w-full bg-white" style="height:70vh"></iframe>
    </div>

    {{-- Recusa --}}
    <details class="bg-gray-900 border border-gray-800 rounded-lg p-5">
        <summary class="text-sm text-gray-400 cursor-pointer hover:text-white">Não concorda? Recusar assinatura</summary>
        <form method="POST" action="{{ route('public.sign.decline', $signer->token) }}" class="mt-3 space-y-3"
              onsubmit="return confirm('Recusar a assinatura? Isso encerra o envelope para todos os signatários.')">
            @csrf
            <textarea name="reason" rows="2" required maxlength="1000" placeholder="Motivo da recusa"
                      class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">{{ old('reason') }}</textarea>
            @error('reason') <p class="text-red-400 text-xs">{{ $message }}</p> @enderror
            <button class="px-4 py-2 rounded text-sm bg-red-900/50 text-red-300 hover:bg-red-900 transition-colors">
                Recusar assinatura
            </button>
        </form>
    </details>

    <p class="text-center text-xs text-gray-600 pb-6">
        &copy; {{ date('Y') }} {{ $settings->company_name ?? config('app.name') }}
    </p>
</div>

{{-- Form separado para solicitar o OTP (fora do form principal) --}}
@if ($signer->requiresOtp())
    <form id="otp-form" method="POST" action="{{ route('public.sign.otp', $signer->token) }}">@csrf</form>
@endif

<script>
function signaturePad(el) {
    const ctx = el.getContext('2d');
    ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
    let drawing = false, drew = false;
    const pos = e => {
        const r = el.getBoundingClientRect();
        return { x: (e.clientX - r.left) * el.width / r.width, y: (e.clientY - r.top) * el.height / r.height };
    };
    el.addEventListener('pointerdown', e => { drawing = true; drew = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
    el.addEventListener('pointermove', e => { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
    ['pointerup', 'pointerleave'].forEach(ev => el.addEventListener(ev, () => drawing = false));
    return {
        clear() { ctx.clearRect(0, 0, el.width, el.height); drew = false; },
        empty: () => !drew,
        dataUrl: () => el.toDataURL('image/png'),
    };
}

function signPage() {
    return {
        tab: 'drawn', typedName: '', pad: null,

        init() {
            this.pad = signaturePad(this.$refs.pad);
        },

        maskCpf(e) {
            let v = e.target.value.replace(/\D/g, '').slice(0, 11);
            v = v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            e.target.value = v;
        },

        prepare(e) {
            if (this.tab === 'drawn') {
                if (this.pad.empty()) {
                    e.preventDefault();
                    alert('Desenhe sua assinatura antes de continuar.');
                    return;
                }
                this.$refs.signature.value = this.pad.dataUrl();
            } else {
                if (!this.typedName.trim()) {
                    e.preventDefault();
                    alert('Digite seu nome para gerar a assinatura.');
                    return;
                }
                const c = document.createElement('canvas');
                c.width = 400; c.height = 150;
                const ctx = c.getContext('2d');
                ctx.fillStyle = '#111';
                ctx.font = 'italic 40px "Segoe Script", cursive';
                ctx.textBaseline = 'middle';
                ctx.fillText(this.typedName.trim(), 20, 75, 360);
                this.$refs.signature.value = c.toDataURL('image/png');
            }
        },
    };
}
</script>
</body>
</html>
