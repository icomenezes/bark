<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  body { margin:0; padding:0; background:#f1f5f9; font-family: ui-sans-serif, system-ui, sans-serif; }
  .wrap { max-width:560px; margin:40px auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.08); }
  .header { background:#1e40af; padding:32px 40px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:20px; font-weight:700; }
  .body { padding:36px 40px; color:#334155; font-size:15px; line-height:1.7; }
  .body h2 { color:#1e293b; font-size:18px; margin-top:0; }
  .box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; margin:20px 0; }
  .box p { margin:4px 0; font-size:14px; }
  .btn { display:inline-block; background:#1e40af; color:#fff!important; text-decoration:none; padding:12px 28px; border-radius:8px; font-weight:600; font-size:15px; margin:20px 0; }
  .footer { padding:20px 40px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #f1f5f9; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>{{ config('app.name') }}</h1>
  </div>
  <div class="body">
    <h2>Código de verificação</h2>
    <p>Use o código abaixo para confirmar sua identidade e assinar
       <strong>{{ $signer->envelope->title }}</strong>:</p>
    <div class="box" style="text-align:center;">
        <p style="font-size:28px;font-weight:700;letter-spacing:6px;">{{ $code }}</p>
    </div>
    <p style="font-size:13px;color:#64748b;">O código vale por 10 minutos. Se você não solicitou, ignore este e-mail.</p>
  </div>
  <div class="footer">
    Equipe {{ config('app.name') }}
  </div>
</div>
</body>
</html>
