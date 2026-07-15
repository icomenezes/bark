@extends('client.layout')
@section('title', 'Novo envelope')

@section('content')
<div class="max-w-7xl mx-auto space-y-4" x-data="envelopeWizard()">

    <div>
        <h1 class="text-xl font-semibold text-white">Novo envelope</h1>
        <p class="text-xs text-gray-500 mt-0.5">
            Envie um PDF para assinatura eletrônica — os signatários recebem um link por e-mail
        </p>
    </div>

    @if ($errors->any())
        <div class="bg-red-900/40 border border-red-700 text-red-300 px-4 py-3 rounded text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Indicador de passos --}}
    <div class="flex items-center gap-2 text-xs">
        <template x-for="(label, i) in ['Documento', 'Signatários', 'Posicionar assinaturas']" :key="i">
            <div class="flex items-center gap-2">
                <span class="w-6 h-6 rounded-full flex items-center justify-center font-semibold"
                      :class="step === i + 1 ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400'"
                      x-text="i + 1"></span>
                <span :class="step === i + 1 ? 'text-white' : 'text-gray-500'" x-text="label"></span>
                <span class="text-gray-700" x-show="i < 2">—</span>
            </div>
        </template>
    </div>

    <form method="POST" action="{{ route('envelopes.store') }}" enctype="multipart/form-data" @submit="submit($event)">
        @csrf
        <input type="hidden" name="signers_json" x-ref="signersJson">

        {{-- Passo 1: Documento --}}
        <div x-show="step === 1" class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
            <div>
                <label class="block text-sm text-gray-300 mb-1">Título do documento *</label>
                <input type="text" name="title" x-ref="title" value="{{ old('title') }}" required maxlength="255"
                       class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Mensagem aos signatários (opcional)</label>
                <textarea name="message" rows="2" maxlength="2000"
                          class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">{{ old('message') }}</textarea>
            </div>
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-300 mb-1">Ordem de assinatura</label>
                    <div class="flex gap-4 text-sm text-gray-300">
                        <label class="flex items-center gap-2">
                            <input type="radio" name="signing_order" value="parallel" checked> Paralela (todos ao mesmo tempo)
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" name="signing_order" value="sequential"> Sequencial (um por vez)
                        </label>
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-gray-300 mb-1">Expira em (opcional)</label>
                    <input type="date" name="expires_at" value="{{ old('expires_at') }}"
                           class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Arquivo PDF *</label>
                <input type="file" name="pdf" accept="application/pdf" required @change="loadPdf($event)"
                       class="w-full text-sm text-gray-400 file:mr-3 file:px-3 file:py-1.5 file:rounded file:border-0 file:bg-gray-700 file:text-gray-200">
                <p class="text-xs text-gray-500 mt-1" x-show="pdfDoc" x-cloak>
                    PDF carregado: <span x-text="pdfDoc?.numPages"></span> página(s)
                </p>
            </div>
        </div>

        {{-- Passo 2: Signatários --}}
        <div x-show="step === 2" x-cloak class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
            <template x-for="(signer, i) in signers" :key="i">
                <div class="border border-gray-800 rounded-lg p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-medium" :style="`color:${colors[i % 8]}`" x-text="`Signatário ${i + 1}`"></p>
                        <button type="button" class="text-xs text-red-400 hover:text-red-300" @click="removeSigner(i)">remover</button>
                    </div>
                    <div class="grid md:grid-cols-2 gap-3">
                        <input type="text" placeholder="Nome completo" x-model="signer.name" maxlength="255"
                               class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                        <input type="email" placeholder="E-mail" x-model="signer.email" maxlength="255"
                               class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                        <select x-model="signer.auth_method"
                                class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                            <option value="link">Somente link</option>
                            <option value="email_otp">Código por e-mail</option>
                            <option value="whatsapp_otp">Código por WhatsApp</option>
                        </select>
                        <input type="text" placeholder="WhatsApp (com DDD)" x-model="signer.whatsapp" maxlength="20"
                               x-show="signer.auth_method === 'whatsapp_otp'"
                               class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    </div>
                </div>
            </template>
            <button type="button" @click="addSigner()" x-show="signers.length < 20"
                    class="px-3 py-2 rounded text-sm bg-gray-800 text-gray-300 hover:bg-gray-700 transition-colors">
                + Adicionar signatário
            </button>
        </div>

        {{-- Passo 3: Posicionar assinaturas --}}
        <div x-show="step === 3" x-cloak class="bg-gray-900 border border-gray-800 rounded-lg p-5 space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-gray-400">Clique numa página para posicionar a assinatura de:</span>
                <template x-for="(signer, i) in signers" :key="i">
                    <button type="button" @click="selected = i"
                            class="px-3 py-1 rounded-full text-xs font-semibold border-2 transition-colors"
                            :style="`border-color:${colors[i % 8]}; color:${selected === i ? '#fff' : colors[i % 8]}; background:${selected === i ? colors[i % 8] : 'transparent'}`"
                            x-text="signer.name || `Signatário ${i + 1}`"></button>
                </template>
            </div>
            <div x-ref="pages" class="bg-gray-950 rounded p-4 overflow-auto" style="max-height: 70vh"></div>
        </div>

        {{-- Navegação --}}
        <div class="flex items-center justify-between mt-4">
            <button type="button" x-show="step > 1" x-cloak @click="step--"
                    class="px-4 py-2 rounded text-sm bg-gray-800 text-gray-300 hover:bg-gray-700 transition-colors">
                ← Voltar
            </button>
            <span x-show="step === 1"></span>
            <button type="button" x-show="step < 3"
                    @click="if (validStep(step)) { if (step === 1 && signers.length === 0) addSigner(); step++; if (step === 3) $nextTick(() => renderPages()); }
                            else alert(step === 1 ? 'Informe o título e selecione um PDF.' : 'Preencha nome e e-mail de todos os signatários (e WhatsApp quando o método for WhatsApp).')"
                    class="px-4 py-2 rounded text-sm font-medium text-white transition-colors"
                    style="background-color: var(--color-primary);">
                Avançar →
            </button>
            <button type="submit" x-show="step === 3" x-cloak
                    class="px-4 py-2 rounded text-sm font-medium text-white transition-colors"
                    style="background-color: var(--color-primary);">
                Enviar para assinatura
            </button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
@include('client.partials.pdfjs-loader')
<script>
function envelopeWizard() {
    return {
        step: 1, signers: [], selected: 0, fields: [], // fields: [{signerIdx, page, xPt, yPt}]
        pdfDoc: null, scale: 1.3,
        colors: ['#2563eb','#dc2626','#16a34a','#9333ea','#ea580c','#0891b2','#db2777','#65a30d'],

        addSigner() { if (this.signers.length < 20) this.signers.push({name:'', email:'', auth_method:'link', whatsapp:''}); },
        removeSigner(i) {
            this.signers.splice(i, 1);
            this.fields = this.fields.filter(f => f.signerIdx !== i)
                .map(f => f.signerIdx > i ? {...f, signerIdx: f.signerIdx - 1} : f);
            if (this.selected >= this.signers.length) this.selected = 0;
        },

        loadPdf(event) {
            const file = event.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = () => {
                window.loadPdfJs(() => {
                    pdfjsLib.getDocument({ data: new Uint8Array(reader.result) }).promise.then(pdf => {
                        this.pdfDoc = pdf;
                    });
                });
            };
            reader.readAsArrayBuffer(file);
        },

        async renderPages() {
            if (!this.pdfDoc) return;
            const wrap = this.$refs.pages;
            wrap.innerHTML = '';
            for (let p = 1; p <= this.pdfDoc.numPages; p++) {
                const page = await this.pdfDoc.getPage(p);
                const viewport = page.getViewport({ scale: this.scale });
                const holder = document.createElement('div');
                holder.className = 'relative mx-auto mb-4 shadow';
                holder.style.width = viewport.width + 'px';
                holder.dataset.page = p;
                const canvas = document.createElement('canvas');
                canvas.width = viewport.width; canvas.height = viewport.height;
                holder.appendChild(canvas);
                holder.addEventListener('click', e => this.addField(e, holder, p));
                wrap.appendChild(holder);
                await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
            }
            this.redrawMarkers();
        },

        addField(e, holder, page) {
            if (e.target.closest('.marker')) return;
            const rect = holder.getBoundingClientRect();
            this.fields.push({ signerIdx: this.selected, page,
                xPt: (e.clientX - rect.left) / this.scale, yPt: (e.clientY - rect.top) / this.scale });
            this.redrawMarkers();
        },

        redrawMarkers() {
            document.querySelectorAll('.marker').forEach(m => m.remove());
            this.fields.forEach((f, idx) => {
                const holder = this.$refs.pages.querySelector(`[data-page="${f.page}"]`);
                if (!holder) return;
                const m = document.createElement('div');
                m.className = 'marker absolute border-2 rounded flex items-center justify-between px-1 text-xs font-semibold cursor-move';
                m.style.cssText = `left:${f.xPt * this.scale}px; top:${f.yPt * this.scale}px;`
                    + `width:${120 * this.scale}px; height:${40 * this.scale}px;`
                    + `border-color:${this.colors[f.signerIdx % 8]}; color:${this.colors[f.signerIdx % 8]};`
                    + 'background:rgba(255,255,255,.7);';
                m.innerHTML = `<span>${(this.signers[f.signerIdx]?.name || 'Signatário ' + (f.signerIdx+1))}</span>`
                    + `<button type="button" data-idx="${idx}">×</button>`;
                m.querySelector('button').addEventListener('click', () => { this.fields.splice(idx, 1); this.redrawMarkers(); });
                this.makeDraggable(m, f, holder);
                holder.appendChild(m);
            });
        },

        makeDraggable(el, f, holder) {
            el.addEventListener('pointerdown', down => {
                if (down.target.tagName === 'BUTTON') return;
                down.preventDefault();
                const start = { x: down.clientX, y: down.clientY, xPt: f.xPt, yPt: f.yPt };
                const move = ev => {
                    f.xPt = Math.max(0, start.xPt + (ev.clientX - start.x) / this.scale);
                    f.yPt = Math.max(0, start.yPt + (ev.clientY - start.y) / this.scale);
                    el.style.left = f.xPt * this.scale + 'px';
                    el.style.top = f.yPt * this.scale + 'px';
                };
                const up = () => { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); };
                document.addEventListener('pointermove', move);
                document.addEventListener('pointerup', up);
            });
        },

        validStep(n) {
            if (n === 1) return this.$refs.title.value.trim() !== '' && this.pdfDoc !== null;
            if (n === 2) return this.signers.length > 0
                && this.signers.every(s => s.name.trim() && s.email.trim()
                    && (s.auth_method !== 'whatsapp_otp' || s.whatsapp.trim()));
            return true;
        },

        submit(e) {
            const missing = this.signers.findIndex((s, i) => !this.fields.some(f => f.signerIdx === i));
            if (missing !== -1) {
                e.preventDefault();
                alert(`Posicione a assinatura de ${this.signers[missing].name} no documento.`);
                return;
            }
            this.$refs.signersJson.value = JSON.stringify(this.signers.map((s, i) => ({
                ...s,
                fields: this.fields.filter(f => f.signerIdx === i)
                    .map(f => ({ page: f.page, x: +f.xPt.toFixed(2), y: +f.yPt.toFixed(2), w: 120, h: 40 })),
            })));
        },
    };
}
</script>
@endpush
