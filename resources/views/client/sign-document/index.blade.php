@extends('client.layout')
@section('title', 'Assinar Documento')

@section('content')
<div class="max-w-7xl mx-auto space-y-4">

    <div>
        <h1 class="text-xl font-semibold text-white">Assinar documento</h1>
        <p class="text-xs text-gray-500 mt-0.5">
            Assinatura digital PAdES com certificado PFX — posicione o carimbo no preview antes de assinar
        </p>
    </div>

    @if(session('signed_file'))
        <div class="flex items-center gap-4 bg-gray-900 border border-green-800 rounded-lg px-4 py-3">
            <svg class="w-8 h-8 text-green-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div class="flex-1">
                <p class="text-sm font-medium text-white">Documento assinado</p>
                <p class="text-xs text-gray-500">{{ session('signed_file') }}</p>
            </div>
            <a href="{{ route('sign-document.download', session('signed_file')) }}"
               class="flex items-center gap-1.5 bg-green-700 hover:bg-green-600 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Baixar PDF
            </a>
        </div>
    @endif

    @if($signedDocuments->isNotEmpty())
        <div class="bg-gray-900 rounded-lg border border-gray-800 p-6">
            <h2 class="text-sm font-semibold text-gray-300 uppercase tracking-wider border-b border-gray-800 pb-2 mb-4">
                Documentos assinados anteriormente
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead>
                        <tr class="text-xs text-gray-500 uppercase">
                            <th class="pb-2 pr-4">Data</th>
                            <th class="pb-2 pr-4">Documento</th>
                            <th class="pb-2 pr-4">Certificado</th>
                            <th class="pb-2 pr-4">Motor</th>
                            <th class="pb-2 pr-4">TSA</th>
                            <th class="pb-2 pr-4">Selo</th>
                            <th class="pb-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @foreach($signedDocuments as $log)
                            <tr class="text-gray-300">
                                <td class="py-2 pr-4 whitespace-nowrap text-gray-400">{{ $log->created_at->format('d/m/Y H:i') }}</td>
                                <td class="py-2 pr-4">{{ $log->meta['original_name'] ?? $log->meta['file'] }}</td>
                                <td class="py-2 pr-4">{{ $log->meta['certificate_description'] ?? '—' }}</td>
                                <td class="py-2 pr-4 uppercase text-xs text-gray-400">{{ $log->meta['engine'] ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ ($log->meta['tsa'] ?? false) ? 'Sim' : 'Não' }}</td>
                                <td class="py-2 pr-4">{{ ($log->meta['use_seal'] ?? false) ? 'Sim' : 'Não' }}</td>
                                <td class="py-2 text-right">
                                    <a href="{{ route('sign-document.download', $log->meta['file']) }}"
                                       class="text-blue-400 hover:underline text-xs font-medium">Baixar</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if($certificates->isEmpty())
        <div class="text-center py-16 text-gray-500 bg-gray-900 border border-gray-800 rounded-xl">
            <p class="font-medium">Nenhum certificado cadastrado</p>
            <p class="text-sm mt-1">
                <a href="{{ route('certificates.create') }}" class="text-blue-400 hover:underline">Cadastre um certificado PFX</a>
                para poder assinar documentos.
            </p>
        </div>
    @else
    <form id="sign-form" method="POST" action="{{ route('sign-document.sign') }}" enctype="multipart/form-data"
          class="lg:grid lg:grid-cols-3 lg:gap-6 lg:items-start space-y-4 lg:space-y-0">
        @csrf

        {{-- Posição da assinatura em pontos PDF, origem topo-esquerdo (preenchida pelo JS) --}}
        <input type="hidden" name="sign_x" id="sign_x" value="150">
        <input type="hidden" name="sign_y" id="sign_y" value="240">
        <input type="hidden" name="sign_w" id="sign_w" value="150">
        <input type="hidden" name="sign_h" id="sign_h" value="60">
        <input type="hidden" name="sign_page" id="sign_page" value="1">
        <input type="hidden" name="drawn_signature" id="drawn_signature" value="">

        {{-- Coluna esquerda: documento + preview --}}
        <div class="lg:col-span-2 bg-gray-900 rounded-lg border border-gray-800 p-6 space-y-4">
            <div>
                <h2 class="text-sm font-semibold text-gray-300 uppercase tracking-wider border-b border-gray-800 pb-2 mb-4">
                    Documento
                </h2>
                <label class="block text-sm font-medium text-gray-300 mb-1.5">PDF para assinar (máx. 15 MB):</label>
                <input type="file" name="pdf" id="pdf-file" accept=".pdf"
                       class="w-full text-sm text-gray-400 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0
                              file:bg-gray-700 file:text-white file:text-sm hover:file:bg-gray-600
                              bg-gray-800 border border-gray-700 rounded-lg">
            </div>

            <div>
                <p class="text-xs text-gray-500 mb-3">
                    Clique no documento para posicionar a assinatura. Arraste o marcador para reposicionar.
                </p>

                <div id="pdf-nav" class="hidden items-center gap-3 mb-3">
                    <button type="button" id="pdf-prev"
                            class="bg-gray-800 hover:bg-gray-700 text-gray-300 text-xs px-3 py-1.5 rounded-md transition-colors">
                        ‹ Anterior
                    </button>
                    <span id="pdf-page-info" class="text-xs text-gray-400">Página 1 / 1</span>
                    <button type="button" id="pdf-next"
                            class="bg-gray-800 hover:bg-gray-700 text-gray-300 text-xs px-3 py-1.5 rounded-md transition-colors">
                        Próxima ›
                    </button>
                </div>

                <div id="pdf-wrapper" class="relative hidden overflow-hidden rounded-lg border border-gray-700 bg-gray-800"
                     style="cursor:crosshair">
                    <canvas id="pdf-canvas" class="block max-w-full"></canvas>
                    <div id="sign-marker"
                         style="position:absolute;border:2px dashed #e67e22;background:rgba(230,126,34,0.15);cursor:move;display:none;overflow:hidden;box-sizing:border-box">
                        <div style="font-size:10px;color:#e67e22;padding:2px 4px;white-space:nowrap;pointer-events:none;user-select:none">
                            ✍ Assinatura
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Coluna direita: painel de ações (fixo ao rolar) --}}
        <div class="lg:sticky lg:top-4 bg-gray-900 rounded-lg border border-gray-800 p-6 space-y-5">
            <div>
                <h2 class="text-sm font-semibold text-gray-300 uppercase tracking-wider border-b border-gray-800 pb-2 mb-4">
                    Certificado
                </h2>
                <select name="certificate_id" required
                        class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white
                               focus:outline-none focus:border-blue-500">
                    <option value="">Selecione o certificado...</option>
                    @foreach($certificates as $certificate)
                        <option value="{{ $certificate->id }}" @selected(old('certificate_id') == $certificate->id)
                                @disabled($certificate->isExpired())>
                            {{ $certificate->description }}
                            @if($certificate->reference) — ref. {{ $certificate->reference }} @endif
                            @if($certificate->isExpired()) (EXPIRADO) @endif
                        </option>
                    @endforeach
                </select>

                <div class="mt-3 space-y-2">
                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="checkbox" name="initial_all_pages" value="1" checked
                               class="rounded bg-gray-800 border-gray-600 text-blue-600 focus:ring-blue-500">
                        Rubricar todas as páginas
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="checkbox" name="use_tsa" value="1" checked
                               class="rounded bg-gray-800 border-gray-600 text-blue-600 focus:ring-blue-500">
                        Carimbo de tempo TSA
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-300" title="Estampa o selo/logo do certificado acima e à direita da assinatura">
                        <input type="checkbox" name="use_seal" value="1"
                               class="rounded bg-gray-800 border-gray-600 text-blue-600 focus:ring-blue-500">
                        Usar selo de autenticação
                    </label>
                </div>
            </div>

            <div>
                <h2 class="text-sm font-semibold text-gray-300 uppercase tracking-wider border-b border-gray-800 pb-2 mb-4">
                    Assinatura visual
                </h2>
                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="radio" name="signature_mode" value="registered" checked
                               class="bg-gray-800 border-gray-600 text-blue-600 focus:ring-blue-500">
                        Imagem cadastrada no certificado
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="radio" name="signature_mode" value="draw"
                               class="bg-gray-800 border-gray-600 text-blue-600 focus:ring-blue-500">
                        Desenhar assinatura agora
                    </label>
                </div>

                <div id="sig-pad-box" class="hidden mt-3">
                    <canvas id="sig-pad" width="600" height="240"
                            class="w-full rounded-lg border border-gray-600 touch-none"
                            style="background:#fff;height:140px;cursor:crosshair"></canvas>
                    <div class="flex items-center justify-between mt-1.5">
                        <p class="text-xs text-gray-500">Assine com o mouse ou o dedo</p>
                        <button type="button" id="sig-clear" class="text-xs text-blue-400 hover:underline">Limpar</button>
                    </div>
                </div>
            </div>

            <div class="space-y-2 pt-2 border-t border-gray-800">
                <button type="submit"
                        class="w-full flex items-center justify-center gap-2 bg-yellow-600 hover:bg-yellow-500 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                    </svg>
                    Assinar PDF enviado
                </button>
                <button type="submit" formaction="{{ route('sign-document.generate') }}" formnovalidate
                        class="w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-500 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Gerar documento e assinar
                </button>
            </div>
        </div>
    </form>
    @endif

</div>
@endsection

@push('scripts')
<script>
(function () {
    var pdfDoc = null;
    var currentPage = 1;
    var totalPages = 1;
    var scale = 1;

    function loadPdfJs(cb) {
        if (window.pdfjsLib) { cb(); return; }
        var s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
        s.onload = function () {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
            cb();
        };
        document.head.appendChild(s);
    }

    function getField(name) { return document.getElementById(name).value; }
    function setField(name, val) { document.getElementById(name).value = val; }

    function renderPage(num) {
        pdfDoc.getPage(num).then(function (page) {
            var viewport = page.getViewport({ scale: 1 });
            var wrapper = document.getElementById('pdf-wrapper');
            var maxW = wrapper.offsetWidth || 700;
            scale = maxW / viewport.width;
            var scaledViewport = page.getViewport({ scale: scale });

            var canvas = document.getElementById('pdf-canvas');
            canvas.width = scaledViewport.width;
            canvas.height = scaledViewport.height;

            page.render({ canvasContext: canvas.getContext('2d'), viewport: scaledViewport }).promise.then(function () {
                updateMarkerFromFields();
            });

            document.getElementById('pdf-page-info').textContent = 'Página ' + num + ' / ' + totalPages;
        });
    }

    function updateMarkerFromFields() {
        var m = document.getElementById('sign-marker');
        var pg = parseInt(getField('sign_page')) || 1;
        if (pg !== currentPage) { m.style.display = 'none'; return; }

        var xPt = parseFloat(getField('sign_x')) || 150;
        var yPt = parseFloat(getField('sign_y')) || 240;
        var wPt = parseFloat(getField('sign_w')) || 150;
        var hPt = parseFloat(getField('sign_h')) || 60;

        m.style.left = (xPt * scale) + 'px';
        m.style.top = (yPt * scale) + 'px';
        m.style.width = (wPt * scale) + 'px';
        m.style.height = (hPt * scale) + 'px';
        m.style.display = 'block';
    }

    function markerPxToPoints(pxX, pxY) {
        return {
            x: Math.round(pxX / scale * 10) / 10,
            y: Math.round(pxY / scale * 10) / 10
        };
    }

    function initDrag(marker) {
        var dragging = false, startX, startY, origLeft, origTop;
        marker.addEventListener('mousedown', function (e) {
            dragging = true;
            startX = e.clientX; startY = e.clientY;
            origLeft = marker.offsetLeft; origTop = marker.offsetTop;
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            var canvas = document.getElementById('pdf-canvas');
            var dx = e.clientX - startX, dy = e.clientY - startY;
            var newL = Math.min(Math.max(0, origLeft + dx), Math.max(0, canvas.width - marker.offsetWidth));
            var newT = Math.min(Math.max(0, origTop + dy), Math.max(0, canvas.height - marker.offsetHeight));
            marker.style.left = newL + 'px';
            marker.style.top = newT + 'px';
            var pt = markerPxToPoints(newL, newT);
            setField('sign_x', pt.x);
            setField('sign_y', pt.y);
            setField('sign_page', currentPage);
        });
        document.addEventListener('mouseup', function () { dragging = false; });
    }

    // ─── Pad de assinatura (mouse/touch, fundo transparente no PNG exportado) ──

    var sigHasStrokes = false;

    function initSignaturePad() {
        var pad = document.getElementById('sig-pad');
        var box = document.getElementById('sig-pad-box');
        var ctx = pad.getContext('2d');
        var drawing = false;

        ctx.lineWidth = 4;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#1e3a8a';

        function pos(e) {
            var rect = pad.getBoundingClientRect();
            var p = e.touches ? e.touches[0] : e;
            return {
                x: (p.clientX - rect.left) * (pad.width / rect.width),
                y: (p.clientY - rect.top) * (pad.height / rect.height)
            };
        }

        function start(e) {
            drawing = true;
            var p = pos(e);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
            e.preventDefault();
        }

        function move(e) {
            if (!drawing) return;
            var p = pos(e);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            sigHasStrokes = true;
            e.preventDefault();
        }

        function end() { drawing = false; }

        pad.addEventListener('mousedown', start);
        pad.addEventListener('mousemove', move);
        document.addEventListener('mouseup', end);
        pad.addEventListener('touchstart', start, { passive: false });
        pad.addEventListener('touchmove', move, { passive: false });
        pad.addEventListener('touchend', end);

        document.getElementById('sig-clear').addEventListener('click', function () {
            ctx.clearRect(0, 0, pad.width, pad.height);
            sigHasStrokes = false;
        });

        document.querySelectorAll('[name="signature_mode"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                box.classList.toggle('hidden', radio.value !== 'draw' || !radio.checked);
            });
        });

        document.getElementById('sign-form').addEventListener('submit', function (e) {
            var mode = document.querySelector('[name="signature_mode"]:checked');
            if (mode && mode.value === 'draw') {
                if (!sigHasStrokes) {
                    e.preventDefault();
                    alert('Desenhe a assinatura antes de assinar (ou escolha a imagem cadastrada).');
                    return;
                }
                document.getElementById('drawn_signature').value = pad.toDataURL('image/png');
            } else {
                document.getElementById('drawn_signature').value = '';
            }
        });
    }

    function init() {
        var fileInput = document.getElementById('pdf-file');
        if (!fileInput) return;

        var marker = document.getElementById('sign-marker');
        initDrag(marker);
        initSignaturePad();

        // Preview direto do arquivo local — sem round-trip ao servidor
        fileInput.addEventListener('change', function () {
            var file = fileInput.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function () {
                loadPdfJs(function () {
                    pdfjsLib.getDocument({ data: new Uint8Array(reader.result) }).promise.then(function (pdf) {
                        pdfDoc = pdf;
                        totalPages = pdf.numPages;
                        currentPage = 1;
                        document.getElementById('pdf-wrapper').classList.remove('hidden');
                        var nav = document.getElementById('pdf-nav');
                        nav.classList.remove('hidden');
                        nav.classList.add('flex');
                        renderPage(currentPage);
                    }).catch(function () {
                        document.getElementById('pdf-wrapper').classList.add('hidden');
                    });
                });
            };
            reader.readAsArrayBuffer(file);
        });

        document.getElementById('pdf-wrapper').addEventListener('click', function (e) {
            if (e.target === marker || marker.contains(e.target)) return;
            var canvas = document.getElementById('pdf-canvas');
            var wPx = (parseFloat(getField('sign_w')) || 150) * scale;
            var hPx = (parseFloat(getField('sign_h')) || 60) * scale;
            var rect = this.getBoundingClientRect();
            var pxX = Math.min(Math.max(0, e.clientX - rect.left), Math.max(0, canvas.width - wPx));
            var pxY = Math.min(Math.max(0, e.clientY - rect.top), Math.max(0, canvas.height - hPx));
            var pt = markerPxToPoints(pxX, pxY);
            setField('sign_x', pt.x);
            setField('sign_y', pt.y);
            setField('sign_page', currentPage);
            marker.style.left = pxX + 'px';
            marker.style.top = pxY + 'px';
            marker.style.width = wPx + 'px';
            marker.style.height = hPx + 'px';
            marker.style.display = 'block';
        });

        document.getElementById('pdf-prev').addEventListener('click', function () {
            if (currentPage > 1) { currentPage--; renderPage(currentPage); }
        });
        document.getElementById('pdf-next').addEventListener('click', function () {
            if (currentPage < totalPages) { currentPage++; renderPage(currentPage); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
@endpush
