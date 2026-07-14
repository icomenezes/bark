<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $titulo ?? config('app.name') }}</title>
    <style>
        body { margin: 0; padding: 0; background: #f4f4f4; font-family: 'Segoe UI', Arial, sans-serif; }
        .wrapper { max-width: 600px; margin: 30px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .header { background: {{ $primaryColor ?? '#1e40af' }}; padding: 32px 40px; text-align: center; }
        .header img { max-height: 60px; }
        .header h1 { color: #fff; margin: 12px 0 0; font-size: 22px; font-weight: 600; }
        .body { padding: 36px 40px; color: #374151; line-height: 1.7; font-size: 15px; }
        .body h2 { color: #111827; margin-top: 0; font-size: 20px; }
        .btn { display: inline-block; margin: 24px 0 0; padding: 14px 32px; background: {{ $primaryColor ?? '#1e40af' }}; color: #fff !important; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 15px; }
        .info-box { background: #f0f9ff; border-left: 4px solid {{ $primaryColor ?? '#1e40af' }}; padding: 16px 20px; border-radius: 4px; margin: 20px 0; }
        .footer { background: #f9fafb; padding: 20px 40px; text-align: center; color: #9ca3af; font-size: 13px; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        @if($logoUrl ?? false)
            <img src="{{ $logoUrl }}" alt="{{ $companyName ?? config('app.name') }}">
        @else
            <h1>{{ $companyName ?? config('app.name') }}</h1>
        @endif
    </div>
    <div class="body">
        {{ $slot }}
    </div>
    <div class="footer">
        © {{ date('Y') }} {{ $companyName ?? config('app.name') }} — Todos os direitos reservados.<br>
        Este e-mail foi enviado automaticamente, não responda.
    </div>
</div>
</body>
</html>
