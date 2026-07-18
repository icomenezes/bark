# Incidente 2026-07-18 — pyHanko ausente na VPS, assinaturas inválidas no ITI

## Sintoma

Documentos assinados (via `sign-document` avulso e via lacre de envelopes) paravam de
validar no site do ITI ("Verificador de Conformidade" do governo), com o erro:

> Você submeteu um documento sem assinatura reconhecível ou com assinatura corrompida.

Afetava **os dois fluxos** (documento avulso e envelope), com qualquer certificado.

## Investigação

1. Comparação binária do PDF que falhava: o `/ByteRange` da assinatura cobria só até o
   byte 821226, mas o arquivo tinha 825104 bytes — **3878 bytes fora da área assinada**,
   contendo metadados XMP do TCPDF. Ou seja, a assinatura era estruturalmente inválida
   (o PDF continha dados depois do que foi hasheado).
2. Consulta ao `access_logs` (evento `document_signed`) na VPS mostrou `"engine":"tcpdf"`
   em todos os registros recentes — nunca `pyhanko`.
3. `which pyhanko`, `find / -iname "*pyhanko*"` e `pip3 list` na VPS: **nada instalado**.
   `pip3` nem existia mais no sistema (`No module named pip`).
4. Conclusão: o sistema estava caindo silenciosamente no motor fallback `SignPdfService`
   (TCPDF+FPDI), que tem uma limitação conhecida e documentada no código
   (`app/Services/Pdf/SignPdfService.php`, comentário da classe): TCPDF escreve metadados
   XMP depois de calcular a assinatura em certas condições, invalidando o `/ByteRange`.
   Esse bug é antigo mas nunca tinha sido exercitado em produção porque o pyHanko sempre
   foi escolhido primeiro quando disponível (`PyHankoSigner::available()`).

## Causa raiz de por que o pyHanko sumiu

**Não identificada com certeza — registrado aqui para não repetir a investigação.**

O usuário confirmou que o pyHanko esteve instalado e funcionando (documentos com múltiplas
assinaturas, só possíveis via pyHanko incremental). Não houve troca/reconstrução de servidor
(`uptime -s` mostrou o host de pé desde 2026-05-22, sem reboot).

Descartado, com evidência:
- **Remoção via apt**: `dpkg.log` não mostra `python3-pip` instalado em nenhum momento
  antes de 2026-07-18 (a reinstalação feita neste incidente). `apt/history.log*` (incluindo
  os rotacionados) não tem nenhuma remoção de pacote python relacionado.
- **unattended-upgrades**: histórico de upgrades automáticos não toca em python/pip.
- **CI/CD**: o workflow `.github/workflows/ci.yml` só roda `composer`/`artisan`/`npm`,
  não mexe em Python.
- **Cron/systemd timers**: nenhum job de limpeza referenciando `/opt` ou python.
- **Outros usuários/acessos**: `last` mostra só `root`, sempre dos mesmos IPs conhecidos.
- **Docker**: containers no host são só do projeto `cameras` (outro sistema, mesma VPS),
  sem relação com pyHanko.

Hipótese mais provável (não confirmada): o `pip3 install pyHanko...` original (visto no
`/root/.bash_history`, linha ~1549, sem timestamp) dependia de um `pip3` que **nunca foi
instalado via apt** — ou seja, veio de alguma instalação manual fora do gerenciador de
pacotes (ex.: bootstrap `get-pip.py`), e o ambiente correspondente (possivelmente um venv
solto em `/opt` ou similar) foi apagado manualmente em algum momento não capturado pelo
`.bash_history` (que tem limite de 2000 linhas — comandos mais antigos rotacionam e somem).

## O que foi feito (reinstalação)

Comandos executados na VPS como `root` em 2026-07-18:

```bash
apt-get update
apt-get install -y python3-pip python3-venv
python3 -m venv /opt/pyhanko-venv
/opt/pyhanko-venv/bin/pip install --upgrade pip
/opt/pyhanko-venv/bin/pip install pyHanko pyHanko-cli "pyHanko[image-support]"
chmod -R o+rX /opt/pyhanko-venv
sudo -u www-data /opt/pyhanko-venv/bin/pyhanko --version   # confirmou execução por www-data

systemctl restart php8.3-fpm
systemctl restart assinador-worker
```

Verificação:

```bash
cd /var/www/assinador
sudo -u www-data HOME=/tmp /usr/bin/php8.3 artisan tinker --execute="echo App\Services\Pdf\PyHankoSigner::available() ? 'DISPONIVEL' : 'INDISPONIVEL';"
# → DISPONIVEL
```

O caminho `/opt/pyhanko-venv/bin/pyhanko` **já era um candidato hardcoded** em
`PyHankoSigner::binary()` (`app/Services/Pdf/PyHankoSigner.php`), ao lado de
`/usr/local/bin/pyhanko` — confirma que essa era a convenção original do projeto.
Reinstalando exatamente nesse caminho, a aplicação passou a detectar o binário sem
precisar configurar `PYHANKO_BIN` no `.env`.

## Confirmação de resolução

Teste em `/sign-document` na VPS: novo documento assinado em 18/07 08:52 apareceu na
lista "Documentos assinados anteriormente" com **Motor: PYHANKO**, e validou corretamente
no site do ITI.

## Correções de código relacionadas (mesma sessão)

Durante a investigação, dois bugs de corrupção binária foram encontrados e corrigidos
(mascarados/expostos pelo mesmo sintoma, mas causa diferente — corrompiam o PDF ao
transferir para o S3, independente do motor de assinatura):

- `app/Services/Pdf/PdfSignerService.php::moveToDisk()` — usava `Storage::get()`/`put()`
  para mover o PDF assinado do disk local pro S3; trocado para `readStream()`/`writeStream()`.
- `app/Services/Envelope/EnvelopePdfComposer.php::downloadToTemp()` — usava
  `file_put_contents($temp, $disk->get($path))`; trocado para stream binário.

## Prevenção futura

- **Checklist de smoke test pós-deploy** (`docs/deploy-vps.md`) deveria incluir: abrir
  `/sign-document`, assinar um documento de teste, conferir coluna **Motor = PYHANKO** na
  lista de documentos assinados. Hoje só verifica que a assinatura existe, não qual motor
  gerou.
- Considerar um `artisan` command/health-check que alerte (log ou notificação) quando
  `PyHankoSigner::available()` retornar `false`, em vez de cair em silêncio no fallback.
