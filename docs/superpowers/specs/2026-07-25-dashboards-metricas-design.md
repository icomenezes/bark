# Métricas nas dashboards (admin e cliente) — Design

Data: 2026-07-25

## Problema

A dashboard do admin mostra apenas métricas de acesso (clientes, online agora, acessos
negados) — nada sobre o que o sistema efetivamente produz: documentos assinados e
envelopes enviados. Além disso, a lista "Atividade recente" mostra só a hora (`H:i`), o
que torna impossível distinguir um evento de hoje de um de três dias atrás.

A dashboard do cliente não tem métrica nenhuma: é só uma saudação e três atalhos para os
módulos. O usuário não sabe quantos envelopes já enviou, quanto do plano mensal já
consumiu, nem quantos signatários/grupos tem cadastrados.

## Escopo

1. Três cards novos na dashboard admin: envelopes enviados, envelopes concluídos,
   assinaturas avulsas — cada um com total geral e recorte do mês corrente.
2. Correção do formato de data em "Atividade recente".
3. Quatro cards novos na dashboard do cliente: envelopes, assinaturas avulsas,
   signatários e grupos — escopados ao usuário logado.

Fora de escopo: gráficos, séries temporais, filtros de período configuráveis, exportação.

## Arquitetura

### `app/Services/DashboardStatsService.php` (novo)

Toda agregação fica num serviço único, seguindo o padrão dos serviços existentes
(`UsageLimitService`, `AccessLogService`). Os controllers apenas chamam e repassam para a
view.

```php
class DashboardStatsService
{
    /** @return array{envelopes_sent:int, envelopes_sent_month:int,
     *                envelopes_completed:int, envelopes_completed_month:int,
     *                signatures:int, signatures_month:int} */
    public function admin(): array;

    /** @return array{envelopes:int, envelopes_month:int,
     *                signatures:int, signatures_month:int,
     *                signers:int, groups:int,
     *                max_envelopes_month:?int, max_signatures_month:?int} */
    public function client(User $user): array;
}
```

Motivo de ser um serviço e não queries inline: são sete agregações que, inline, inflariam
`Admin\DashboardController::index()` para mais de 60 linhas, e cada número passaria a
exigir um teste HTTP para ser verificado. Como serviço, os números são testáveis
diretamente e os dois controllers ficam com três linhas cada.

### Janela do mês

Todas as contagens mensais usam `now()->startOfMonth()` … `now()->endOfMonth()`, a mesma
janela do `UsageLimitService` — garantindo que o "X de Y este mês" do cliente bata
exatamente com o limite que bloqueia a operação.

### Fontes de dados

| Número | Query |
|---|---|
| Envelopes enviados | `envelope_events` com `event = 'sent'` e `envelope_signer_id IS NULL`, contando `envelope_id` distintos. Mês: mesma query filtrada por `created_at`. |
| Envelopes concluídos | `envelopes.status = 'completed'`. Mês: filtrado por `completed_at`. |
| Assinaturas avulsas | `access_logs` com `event = 'document_signed'`. Mês: filtrado por `created_at`. |
| Envelopes do cliente | `envelopes` por `user_id` (qualquer status, inclusive rascunho). Mês: por `created_at`, igual ao `UsageLimitService::canCreateEnvelope()`. |
| Signatários / grupos | `saved_signers` / `signer_groups` por `user_id`. |

Decisões de fonte que valem registro:

- **Envelopes enviados vem de `envelope_events`, não de `envelopes.status`.** Não existe
  coluna `sent_at`; contar por `envelopes.created_at` atribuiria ao mês errado um envelope
  criado em rascunho num mês e enviado no seguinte. O evento `sent` de nível de envelope
  (gravado em `EnvelopeService::send()` com `signer_id` nulo) é o registro exato do envio.
  O filtro `envelope_signer_id IS NULL` é obrigatório: `notifySigner()` grava um segundo
  evento `sent` por signatário, que contaria em duplicidade.
- **Assinaturas avulsas vêm de `access_logs`, não de `signed_documents`.** A tabela
  `signed_documents` só recebe registro no fluxo de API (`SignDocumentApiController`); o
  fluxo web grava apenas o log `document_signed`. `access_logs` cobre os dois — é a mesma
  fonte que `UsageLimitService::canSignPdf()` usa para aplicar o limite do plano.
- **Envelopes do cliente contam qualquer status, inclusive `draft`**, para espelhar o
  limite do plano, que também conta rascunhos.

## Dashboard admin

`app/Http/Controllers/Admin/DashboardController.php` injeta `DashboardStatsService` no
construtor e adiciona `$stats` ao `compact()` existente.

`resources/views/admin/dashboard.blade.php`: o grid de cards
(`grid-cols-2 lg:grid-cols-3`) passa de 3 para 6 cards, formando duas linhas. Os três
novos seguem exatamente a marcação dos existentes — número em `text-3xl font-bold
text-white`, legenda em `text-xs text-gray-500`, ícone em quadrado `w-8 h-8` colorido.

```
┌ CLIENTES ──────────┬ ONLINE AGORA ──────┬ ACESSOS NEGADOS ───┐
│ 3                  │ 2                  │ 0                  │
│ 2 online agora     │ últimos 2 minutos  │ hoje               │
├ ENVELOPES ENVIADOS ┼ ENVELOPES CONCLUÍDOS ┼ ASSINATURAS AVULSAS ┐
│ 128                │ 94                 │ 412                │
│ 17 este mês        │ 12 este mês        │ 63 este mês        │
└────────────────────┴────────────────────┴────────────────────┘
```

Ícones e cores dos novos cards:

| Card | Ícone | Cor |
|---|---|---|
| Envelopes enviados | envelope (mesmo path do menu de envelopes) | azul (`bg-blue-900/50` / `text-blue-400`) |
| Envelopes concluídos | check em círculo | verde (`bg-green-900/50` / `text-green-400`) |
| Assinaturas avulsas | `<x-signature-icon>` | âmbar (`bg-amber-900/50` / `text-amber-400`) |

### Atividade recente

Em `resources/views/admin/dashboard.blade.php`, na linha do log:
`$log->created_at->format('H:i')` → `$log->created_at->format('d/m/Y H:i')`.

## Dashboard cliente

`app/Http/Controllers/Client/DashboardController.php` injeta o serviço e passa `$stats`
para a view. O redirecionamento de admin para `admin.dashboard` continua acontecendo
antes de qualquer agregação.

`resources/views/client/dashboard.blade.php`: um grid `grid-cols-2 lg:grid-cols-4` com os
quatro cards novos, inserido entre a saudação e os atalhos de módulo, que permanecem
inalterados abaixo. Cada card é um `<a>` para a seção correspondente.

```
┌ ENVELOPES ───┬ ASSIN. AVULSAS ┬ SIGNATÁRIOS ┬ GRUPOS ─────┐
│ 24           │ 57             │ 12          │ 3           │
│ 4 de 30 mês  │ 9 de 100 mês   │ cadastrados │ criados     │
└──────────────┴────────────────┴─────────────┴─────────────┘
  envelopes.index  sign-document.index  signers.index  signers.index
```

Legenda dos dois primeiros cards:

- Com plano atribuído: `{mês} de {limite} este mês`.
- Sem plano (`plan_id` nulo): `{mês} este mês` — sem denominador, já que não há limite a
  exibir. O serviço devolve `null` em `max_envelopes_month` / `max_signatures_month` nesse
  caso e a view decide o texto.

Os cards de signatários e grupos não têm recorte mensal — são cadastros acumulados, com
legenda fixa "cadastrados" / "criados".

Estilo: mesma superfície dos cards existentes (`bg-gray-900 border border-gray-800
rounded-xl`), com `hover:border-gray-700 transition-colors` por serem clicáveis.

## Testes

`tests/Feature/DashboardStatsTest.php`, cobrindo o serviço diretamente:

1. **Totais admin** — com envelopes em `draft`, `sent` e `completed` de usuários
   diferentes, mais logs `document_signed`, os três números batem com o esperado.
2. **Recorte mensal ignora o mês anterior** — registros datados no mês anterior entram no
   total geral e ficam de fora do número mensal.
3. **Envio duplicado não conta duas vezes** — um envelope com evento `sent` de envelope
   mais eventos `sent` por signatário conta como um único envio.
4. **Isolamento por usuário** — `client()` de um usuário não enxerga envelopes,
   assinaturas, signatários nem grupos de outro.
5. **Cliente sem plano** — `max_envelopes_month` e `max_signatures_month` voltam `null`.

Mais dois testes de rota, no estilo do `AdminSmokeTest` existente: `GET /admin` e
`GET /dashboard` retornam 200 e contêm os rótulos dos cards novos.

## Arquivos afetados

| Arquivo | Ação |
|---|---|
| `app/Services/DashboardStatsService.php` | criar |
| `app/Http/Controllers/Admin/DashboardController.php` | injetar serviço, passar `$stats` |
| `app/Http/Controllers/Client/DashboardController.php` | injetar serviço, passar `$stats` |
| `resources/views/admin/dashboard.blade.php` | 3 cards novos + formato de data do log |
| `resources/views/client/dashboard.blade.php` | grid de 4 cards acima dos atalhos |
| `tests/Feature/DashboardStatsTest.php` | criar |
