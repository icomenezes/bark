# Deploy e operação na VPS — assinador.trsystem.com.br

Runbook do ambiente de produção. Registrado em 2026-07-15, durante a subida do módulo de envelopes.

## O ambiente

| Item | Valor |
|---|---|
| Pasta da aplicação | `/var/www/assinador` |
| Web | nginx + **php8.3-fpm** |
| PHP CLI padrão (`php`) | **8.4 — SEM extensão GD, NÃO usar** |
| PHP correto para artisan | `/usr/bin/php8.3` (tem GD) |
| Usuário da aplicação | `www-data` |
| Fila | `QUEUE_CONNECTION=database` + worker systemd `assinador-worker` |
| E-mail | Amazon SES via SMTP (us-east-1, porta 587) |

⚠️ **As duas regras de ouro:**

1. Todo `artisan` na VPS é `sudo -u www-data /usr/bin/php8.3 artisan ...`
   - Com o `php` pelado cai no 8.4 sem GD → `TCPDF ERROR: requires Imagick or GD` no lacre.
   - Como root, os arquivos criados (ex.: `laravel.log`) ficam com dono root → `Permission denied` para o site.
2. Existe **outra aplicação** em `/var/www/html` (ERP) com workers próprios no mesmo servidor — não confundir os processos no `ps aux`.

## Rotina de deploy (a cada push)

```bash
cd /var/www/assinador
git pull

# migrations novas (se houver)
sudo -u www-data /usr/bin/php8.3 artisan migrate --force

# limpar caches (sempre que mudar .env, rotas ou views)
sudo -u www-data /usr/bin/php8.3 artisan config:clear
sudo -u www-data /usr/bin/php8.3 artisan route:clear
sudo -u www-data /usr/bin/php8.3 artisan view:clear

# assets — só se mudou CSS/JS ou blade com classes Tailwind novas
npm ci && npm run build

# OBRIGATÓRIO se mudou qualquer código usado por jobs (lacre, mails):
# o worker roda o código que estava na memória até ser reiniciado
sudo -u www-data /usr/bin/php8.3 artisan queue:restart
```

## Worker da fila (systemd)

O lacre do envelope (`SealEnvelopeJob`) roda em fila. Sem worker, o envelope fica
preso em "Aguardando assinaturas" mesmo com todos assinados.

Unit: `/etc/systemd/system/assinador-worker.service`

```ini
[Unit]
Description=Laravel queue worker (assinador)
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/assinador
ExecStart=/usr/bin/php8.3 /var/www/assinador/artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
```

Comandos úteis:

```bash
systemctl status assinador-worker        # está rodando?
journalctl -u assinador-worker -f        # acompanhar jobs ao vivo
sudo systemctl restart assinador-worker  # reinício forçado
```

## Cron do scheduler

Necessário para o `envelopes:expire` (expira envelopes vencidos de hora em hora).
No crontab do www-data (`sudo crontab -u www-data -e`):

```cron
* * * * * cd /var/www/assinador && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
```

## E-mail (SES)

- `.env`: `MAIL_MAILER=smtp` — se estiver `log`, o Laravel "envia" escrevendo o e-mail no `laravel.log`.
- Depois de mexer no `.env`: `config:clear`.
- E-mail não chega mesmo com smtp? Verificar no console SES: conta fora do sandbox
  (sandbox só envia para endereços verificados), domínio/remetente `trsystem.com.br` verificado, e spam do destinatário.
- Erros de envio aparecem no banner vermelho da própria tela (a exceção de transporte é capturada e exibida).

## Requisitos PHP do módulo de envelopes

- **php8.3-gd** — obrigatório: TCPDF precisa de GD para os PNGs com transparência das assinaturas.
  Conferir: `/usr/bin/php8.3 -m | grep gd`
- **pyHanko — NÃO é opcional na prática.** É o único motor cuja assinatura passa na validação
  do ITI (validador oficial do governo). O fallback TCPDF (`SignPdfService`) produz um PDF com
  `/ByteRange` que não cobre o arquivo inteiro (metadados XMP do TCPDF ficam fora do range
  assinado) — o ITI acusa **"documento sem assinatura reconhecível ou com assinatura
  corrompida"**. Mesma falha tanto em `sign-document` quanto no lacre de envelopes. Ver
  incidente completo em `docs/incidentes/2026-07-18-pyhanko-ausente-tcpdf-invalido.md`.

### Instalar/reinstalar o pyHanko (Ubuntu 24.04+, sem apt python3-pip)

```bash
apt-get update
apt-get install -y python3-pip python3-venv
python3 -m venv /opt/pyhanko-venv
/opt/pyhanko-venv/bin/pip install --upgrade pip
/opt/pyhanko-venv/bin/pip install pyHanko pyHanko-cli "pyHanko[image-support]"
chmod -R o+rX /opt/pyhanko-venv

# confirmar que o www-data (usuário do PHP-FPM) consegue rodar
sudo -u www-data /opt/pyhanko-venv/bin/pyhanko --version

# qpdf é usado para reparar xref antes de assinar com pyHanko
apt-get install -y qpdf   # se ainda não tiver

# aplicar: reiniciar php-fpm e o worker (o PdfSignerService tem cache estático por request,
# mas reiniciar garante que nenhum processo antigo fique preso num estado negativo)
systemctl restart php8.3-fpm
systemctl restart assinador-worker
```

**Importante:** `/opt/pyhanko-venv/bin/pyhanko` é um caminho *hardcoded* como candidato em
`app/Services/Pdf/PyHankoSigner.php::binary()` — reinstalando exatamente nesse caminho, a
aplicação detecta sozinha, sem precisar setar `PYHANKO_BIN` no `.env`.

### Como confirmar que está sendo usado

```bash
cd /var/www/assinador
sudo -u www-data HOME=/tmp /usr/bin/php8.3 artisan tinker --execute="echo App\Services\Pdf\PyHankoSigner::available() ? 'DISPONIVEL' : 'INDISPONIVEL';"
```

Ou visualmente: em `/sign-document`, a lista "Documentos assinados anteriormente" tem a
coluna **Motor** — deve mostrar `PYHANKO`, nunca `TCPDF`, para o documento validar no ITI.

## Troubleshooting rápido

| Sintoma | Causa provável | Solução |
|---|---|---|
| `Permission denied` em `storage/logs/laravel.log` | artisan rodado como root criou arquivos com dono root | `sudo chown -R www-data:www-data storage bootstrap/cache` |
| Tela vermelha com o e-mail inteiro dentro do erro | `MAIL_MAILER=log` (+ log sem permissão) | trocar para `smtp` + `config:clear` |
| Envelope com todos assinados mas "Aguardando assinaturas" | worker parado ou job falhou | `systemctl status assinador-worker`; `queue:failed` → `queue:retry all`; botão **Reprocessar lacre** no show + worker |
| `TCPDF ERROR: requires Imagick or GD` | worker rodando no PHP sem GD (8.4) | usar `/usr/bin/php8.3` (unit do systemd já usa) |
| Passo 3 do wizard sem páginas para clicar | assets/JS desatualizados | `git pull` + `view:clear` + Ctrl+F5; erros agora aparecem em `alert` |
| Convites não saíram na criação do envelope | falha de mail no `send()` | abrir o envelope → **Reenviar convite** (não precisa recriar) |
| Documento assinado não valida no site do ITI ("sem assinatura reconhecível ou corrompida") | pyHanko sumiu do host, caiu no fallback TCPDF (ByteRange inválido) | conferir motor: seção acima "Como confirmar que está sendo usado"; reinstalar pyHanko se `INDISPONIVEL` |

## Checklist de smoke test pós-deploy

1. `/envelopes/create` → wizard renderiza o PDF no passo 3.
2. Criar envelope com 1 signatário (e-mail seu), assinar pelo link.
3. Em segundos o envelope vira "Concluído" sozinho (worker OK).
4. Baixar o PDF final: carimbo + página de evidências + assinatura digital válida no Adobe Reader.
