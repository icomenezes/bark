# Tela de signatários/grupos — completar edição

Data: 2026-07-24

## Contexto

O design original ([2026-07-24-signatarios-salvos-grupos-design.md](2026-07-24-signatarios-salvos-grupos-design.md))
previa "criar/editar" tanto para signatários salvos quanto para grupos,
incluindo "gerenciar membros via multi-select". A implementação entregou o
backend completo (`SignerDirectoryController::update()`/`updateGroup()`,
rotas `signers.update`/`signers.groups.update`, `SignerDirectoryService`
com `sync()` de membros), mas a view
`resources/views/client/signers/index.blade.php` só expõe **criar** e
**remover** — não há link "editar" em nenhuma linha, nem formulário
pré-preenchido, nem o `<select multiple>` de membros marcado com os membros
atuais de um grupo.

Resultado, na prática: usuário não consegue editar nome de um grupo, nem
adicionar/remover um signatário de um grupo já existente, nem editar os
dados de um signatário salvo — apesar de o backend suportar tudo isso.

## Escopo

- Duas páginas novas de edição (signatário e grupo), com formulário
  pré-preenchido, seguindo o padrão de página separada já usado no resto do
  app (Certificados, por exemplo, usa `GET .../{id}/edit`).
- Ajuste na `index.blade.php`: cada linha de signatário e de grupo ganha um
  link "editar" ao lado do "remover" existente.

Fora de escopo:

- Qualquer mudança em model, migration ou `SignerDirectoryService` — a
  lógica de update (incluindo `sync()` de membros) já existe e está correta.
- Mudança de UX para modal/inline/Alpine — mantém o padrão de página
  separada com reload tradicional, consistente com o resto do app.
- Integração com o wizard de envelope (já implementada em commit anterior).

## Rotas novas

Adicionadas ao grupo `middleware('auth')` existente em `routes/web.php`,
junto das rotas de signers:

```
GET signatarios/{savedSigner}/editar        -> signers.edit
GET signatarios/grupos/{signerGroup}/editar  -> signers.groups.edit
```

(`PATCH signers.update` e `PATCH signers.groups.update` já existem e não
mudam.)

## Controller

`SignerDirectoryController`, dois métodos novos:

- `edit(SavedSigner $savedSigner)`: `authorizeOwner()` (já existe como
  método privado) e retorna `view('client.signers.edit', compact('savedSigner'))`.
- `editGroup(SignerGroup $signerGroup)`: `authorizeOwnerGroup()` (já existe)
  e retorna `view('client.signers.edit-group', compact('signerGroup', 'signers'))`,
  onde `$signers` é `auth()->user()->savedSigners()->orderBy('name')->get()`
  (mesma query do `index()`, para popular o select completo).

## Views novas

### `resources/views/client/signers/edit.blade.php`

Mesmo formulário de "Novo signatário" (campos `name`, `channel`, `email`,
`whatsapp`, `auth_method`), mas:
- `action="{{ route('signers.update', $savedSigner) }}"` + `@method('PATCH')`
- cada campo pré-preenchido com `old('name', $savedSigner->name)` etc.
  (padrão `old()` com fallback no valor atual, para sobreviver a erro de
  validação sem perder o que o usuário digitou)
- link "Cancelar" voltando para `signers.index`

### `resources/views/client/signers/edit-group.blade.php`

Mesmo formulário de "Novo grupo" (campo `name` + `<select name="members[]" multiple>`),
mas:
- `action="{{ route('signers.groups.update', $signerGroup) }}"` + `@method('PATCH')`
- `name` pré-preenchido com `old('name', $signerGroup->name)`
- cada `<option>` do select marcada com `@selected($signerGroup->members->contains($signer->id))`
  (adicionar/remover membro = simplesmente mudar a seleção antes de salvar;
  o `sync()` no service já cuida do resto)
- link "Cancelar" voltando para `signers.index`

## Ajuste na `index.blade.php`

Nas duas listas (`Seus signatários` e `Seus grupos`), ao lado do form de
remoção existente, adicionar um link:

```blade
<a href="{{ route('signers.edit', $signer) }}" class="text-xs text-gray-400 hover:text-white mr-3">editar</a>
```

(e equivalente com `signers.groups.edit` para grupos), mantendo o botão
"remover" como está.

## Testes

- `SignerDirectoryControllerTest` (estender o existente ou criar se não
  houver): `GET signers.edit` retorna 200 e contém os dados atuais do
  signatário; `GET signers.groups.edit` retorna 200 e o select contém os
  membros atuais marcados; ambos retornam 403 para usuário que não é dono
  (mesmo padrão dos testes de `update`/`destroy` já existentes, se houver).
