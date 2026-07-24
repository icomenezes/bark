# Signatários salvos e grupos de signatários

Data: 2026-07-24

## Contexto

Clientes que fazem envios recorrentes de envelope (diários, semanais, mensais)
hoje precisam redigitar nome/e-mail/WhatsApp/método de autenticação de cada
signatário toda vez, no step 2 do wizard de novo envelope
(`resources/views/client/envelopes/create.blade.php`). Não há como reutilizar
uma lista de contatos já usados antes, nem agrupar um conjunto de pessoas que
sempre assinam junto (ex.: "Diretoria", "Fornecedores recorrentes").

Este design adiciona duas listas reutilizáveis, escopadas por usuário:
**signatários salvos** (contatos individuais) e **grupos de signatários**
(coleções nomeadas de contatos salvos), com integração no wizard existente.

## Escopo

- Nova tela de gestão em `/signatarios`, com link no header do cliente
  (`resources/views/client/layout.blade.php`), junto de Certificados,
  Assinar Documento e Envelopes.
- CRUD de signatários salvos e de grupos.
- Integração no step 2 do wizard de envelope: autocomplete para adicionar um
  contato salvo, botão para carregar um grupo inteiro, e checkbox por linha
  para salvar um signatário novo/editado como contato reutilizável ao enviar.

Fora de escopo:

- Qualquer mudança no fluxo de assinatura em si (posições de campo, ordem,
  OTP, página de lacre) — esta feature só facilita o preenchimento do
  formulário de signatários.
- Compartilhamento de contatos/grupos entre usuários — tudo é escopado por
  `user_id`, sem exceção.
- Limite por plano (como `UsageLimitService` faz para envelopes/PDFs por
  mês) — em vez disso, um limite fixo simples por usuário (ver abaixo).

## Modelo de dados

### `saved_signers`
| Campo | Tipo | Descrição |
|---|---|---|
| user_id | FK | Dono do contato |
| name | string | |
| channel | enum `email`\|`whatsapp` | Mesmo domínio de `envelope_signers.channel` |
| email | string nullable | Obrigatório quando `channel = email` |
| whatsapp | string nullable | Obrigatório quando `channel = whatsapp` |
| auth_method | enum `link`\|`email_otp`\|`whatsapp_otp` | Mesmo domínio de `envelope_signers.auth_method` |

Mesma validação de compatibilidade canal↔método já usada em
`EnvelopeController::validateSigners()` (`whatsapp` só aceita `link` ou
`whatsapp_otp`; `email` só aceita `link` ou `email_otp`).

### `signer_groups`
| Campo | Tipo | Descrição |
|---|---|---|
| user_id | FK | Dono do grupo |
| name | string | |

### `signer_group_members` (pivot)
| Campo | Tipo | Descrição |
|---|---|---|
| signer_group_id | FK | |
| saved_signer_id | FK | |

Muitos-para-muitos: um signatário salvo pode pertencer a vários grupos.
`unique(signer_group_id, saved_signer_id)`.

### `envelope_signers` (alteração)
Nova coluna `saved_signer_id` (FK nullable, `nullOnDelete`) — só
rastreabilidade ("este signatário do envelope veio deste contato salvo");
não afeta o fluxo de assinatura, e apagar o `SavedSigner` depois não afeta o
envelope já criado (os dados já foram copiados para `envelope_signers` na
criação, como já acontece hoje).

## Limites

Sem uso de `UsageLimitService` (que é por plano/mês — conceito diferente).
Limite fixo, validado direto no controller:
- Máximo 100 signatários salvos por usuário.
- Máximo 20 grupos por usuário.

Erro de validação simples (`ValidationException`) ao tentar exceder.

## Tela de gestão (`/signatarios`)

Nova rota autenticada, com link no header do cliente (mesmo padrão de
Certificados/Envelopes). Duas seções na mesma página (abas ou blocos
sequenciais, seguindo o estilo visual existente em `client.layout`):

- **Signatários**: lista com nome, canal, contato, método; criar/editar num
  formulário simples (mesmos campos da tabela); excluir com confirmação.
- **Grupos**: lista de grupos com contagem de membros; criar/editar nome;
  gerenciar membros via multi-select dos signatários salvos do usuário;
  excluir grupo (não excluí os signatários, só a associação).

## Integração no wizard de envelope (step 2)

No step 2 atual (`resources/views/client/envelopes/create.blade.php`,
Alpine.js `envelopeWizard()`):

1. **Adicionar contato salvo**: um campo de busca/autocomplete acima da lista
   de signatários, alimentado por uma rota JSON leve (`GET
   /signatarios/buscar?q=...`) que retorna os signatários salvos do usuário
   autenticado cujo nome contém a busca (limit ~10). Clicar num resultado
   chama a mesma função `addSigner()` já existente, mas pré-preenchida com os
   dados do contato (incluindo `saved_signer_id` para rastreabilidade) — o
   resultado continua editável como qualquer linha do wizard.
2. **Carregar grupo**: um dropdown com os grupos do usuário (carregado junto
   com a página, via `@php`/Blade, sem chamada JSON separada — a lista de
   grupos de um usuário é pequena, até 20). Selecionar um grupo adiciona
   todos os seus membros de uma vez, cada um virando uma linha de signatário
   (mesma função `addSigner()` em lote).
3. **Salvar para reutilizar**: cada linha de signatário no wizard ganha um
   checkbox "Salvar para reutilizar depois". Se marcado **e** a linha não tem
   `saved_signer_id` (ou os dados foram editados em relação ao contato
   original), ao enviar o envelope o backend cria um `SavedSigner` novo
   vinculado ao usuário, antes ou junto da criação do envelope. Editar uma
   linha que veio de um contato salvo não atualiza o `SavedSigner` original
   — o checkbox marcado nesse caso cria um contato novo (evita alterar
   silenciosamente um contato que pode estar em uso por outros fluxos/grupos).

## Rotas

- `GET /signatarios` — página de gestão (lista signatários + grupos)
- `POST /signatarios` — criar signatário salvo
- `PATCH /signatarios/{savedSigner}` — editar
- `DELETE /signatarios/{savedSigner}` — excluir
- `GET /signatarios/buscar` — autocomplete JSON (usado pelo wizard)
- `POST /signatarios/grupos` — criar grupo
- `PATCH /signatarios/grupos/{signerGroup}` — editar nome + membros
- `DELETE /signatarios/grupos/{signerGroup}` — excluir grupo

Todas sob `middleware('auth')`, mesmo grupo de rotas do cliente em
`routes/web.php`. Autorização: todo acesso a um `SavedSigner`/`SignerGroup`
verifica `user_id === auth()->id()` (mesmo padrão de
`EnvelopeController::authorizeOwner()`).

## Serviço

Novo `app/Services/SignerDirectoryService.php`, agregando as operações:
`createSigner()`, `updateSigner()`, `deleteSigner()`, `createGroup()`,
`updateGroupMembers()`, `deleteGroup()`, `search()` (autocomplete),
`saveFromEnvelopePayload()` (chamado por `EnvelopeController::store()`
quando um signatário do wizard vem com a flag de salvar marcada).

## Testes

- Model tests: relação muitos-para-muitos `SignerGroup`↔`SavedSigner`;
  `envelope_signers.saved_signer_id` sobrevive à exclusão do `SavedSigner`
  (fica null).
- `SignerDirectoryServiceTest`: CRUD de signatário e grupo, limite de 100/20
  por usuário, validação de canal↔método incompatível.
- `SignerDirectoryControllerTest` (feature): CRUD via HTTP, autorização
  (usuário não acessa contato/grupo de outro), autocomplete retorna só
  contatos do usuário autenticado.
- `EnvelopeControllerTest` (ajuste): criar envelope com um signatário
  marcado para salvar gera um `SavedSigner`; criar com `saved_signer_id`
  vindo do wizard preenche a coluna em `envelope_signers`.
