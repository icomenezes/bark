# Busca e paginação modernizada na listagem de envelopes

Data: 2026-09-09

## Contexto

A tela `/envelopes` (`resources/views/client/envelopes/index.blade.php`) lista os
envelopes do cliente logado, paginados 20 por página via Laravel padrão
(`Envelope::paginate(20)`). Hoje não há forma de buscar um envelope específico —
com 116+ registros, encontrar um documento pelo título exige navegar página por
página. A paginação usa a view padrão do Laravel (`$envelopes->links()`), com
visual "cru" (números alinhados à esquerda, sem estilo do dark theme do projeto).

## Objetivo

1. Adicionar um campo de busca por título do documento e um filtro por status,
   que filtram no servidor (funciona sobre todos os registros, não só a
   página atual).
2. Modernizar visualmente a paginação: pills arredondadas, centralizadas,
   consistente com o dark theme e a cor primária configurável (`--color-primary`).
   Aplicada globalmente — todas as telas paginadas do projeto passam a usar o
   mesmo visual, não só envelopes.

Escopo:
- Busca + filtro de status: apenas a tela de envelopes
  (`client/envelopes/index.blade.php` + `EnvelopeController@index`).
- Paginação: global, via view default do Laravel — afeta as 4 telas paginadas
  hoje (envelopes, usuários admin, access-logs, certificados) e qualquer
  paginação futura, sem precisar tocar em cada view individualmente.

## Design

### Busca (server-side, debounce automático)

- `EnvelopeController::index` aceita `?q=` via `Request` e aplica
  `->where('title', 'like', '%'.$q.'%')` quando presente, antes do
  `withCount` e `paginate`. Adiciona `->withQueryString()` ao paginate para o
  filtro sobreviver à navegação entre páginas.
- A view recebe um `<input>` de busca acima da tabela, com um ícone de lupa,
  estilizado como os demais inputs do projeto (`bg-gray-800 border-gray-700`).
- Comportamento client-side via Alpine.js (já carregado globalmente no
  `client.layout.blade.php`):
  - `x-data` local com `query` (inicializado de `request('q')`) e um
    `debounce` de ~400ms usando `setTimeout`/`clearTimeout`.
  - Ao disparar, faz `fetch(url com ?q=...)` para a própria rota
    `envelopes.index`, pedindo a página renderizada normal (mesma view Blade),
    e substitui o conteúdo de um wrapper (`<div id="envelopes-table-wrapper">`
    contendo tabela + paginação) pelo HTML retornado — sem reload de página
    inteira.
  - Para simplificar, o fetch busca a **página completa** (não uma partial
    separada) e extrai o wrapper do HTML retornado via `DOMParser`. Evita criar
    um segundo endpoint/partial só para isso.
  - Atualiza a URL do browser via `history.pushState` para refletir `?q=...`
    (permite refresh/compartilhar link com o filtro aplicado).
  - Clicar em links de paginação dentro do wrapper também deve passar pelo
    fetch (interceptação de clique em links `<a>` dentro do wrapper, mesmo
    padrão), para manter a experiência sem reload ao trocar de página.
- Sem resultados: reaproveita o estado vazio já existente ("Nenhum envelope
  ainda..."), ajustando a mensagem quando `q` estiver preenchido (ex.: "Nenhum
  envelope encontrado para o termo buscado").

### Filtro de status

- Dropdown `<select name="status">` ao lado do campo de busca, com as opções
  "Todos" + os 6 status já usados nos badges da tabela (`draft`, `sent`,
  `completed`, `declined`, `cancelled`, `expired`), reaproveitando os mesmos
  labels em pt-BR do `$statusLabels` atual da view.
- `EnvelopeController::index` aceita `?status=` e aplica
  `->where('status', $status)` quando presente e diferente de vazio/"todos".
  Combina com o filtro de busca por título via AND (ambos os filtros, quando
  preenchidos, se somam — não são alternativos).
- Selecionar uma opção do dropdown dispara o mesmo fluxo de fetch/debounce da
  busca (sem precisar de botão "aplicar" separado); mudar o `<select>` dispara
  a busca imediatamente (sem debounce, já que é uma seleção discreta, não
  digitação).
- Estado do filtro (`status` selecionado) também refletido na URL via
  `history.pushState`, junto com `q`.

### Paginação modernizada (global)

- View de paginação customizada **global**, registrada como default do
  Laravel: criar `resources/views/vendor/pagination/casca.blade.php` e, no
  `AppServiceProvider::boot()`, chamar
  `Illuminate\Pagination\Paginator::defaultView('vendor.pagination.casca')`
  (e `defaultSimpleView` se necessário).
- Passa a valer automaticamente nas 4 telas paginadas atuais — envelopes
  (`client/envelopes/index`), usuários admin (`admin/users/index`),
  access-logs (`admin/access-logs/index`) e certificados
  (`client/certificates/index`) — e em qualquer paginação futura, sem alterar
  cada view individualmente. Nenhuma dessas views precisa mudar a chamada
  `->links()`.
- Visual: pills arredondadas (`rounded-full`), centralizadas horizontalmente
  (`flex justify-center`), item ativo com `background-color: var(--color-primary)`
  e texto branco, itens inativos com hover sutil (`hover:bg-gray-800`), setas
  prev/next como ícones SVG (chevron), estado disabled com opacidade reduzida e
  sem cursor pointer.
- Mantém o texto "Showing X to Y of Z results" em pt-BR ("Mostrando X a Y de
  Z"), centralizado acima ou ao lado dos pills conforme espaço.
- Como a tabela de envelopes é recarregada via fetch (busca/filtro), o wrapper
  substituído pelo fetch (`#envelopes-table-wrapper`) precisa incluir a
  paginação também, para que os links de página renderizados nela sigam
  passando pelo mesmo fluxo de fetch (ver interceptação de cliques descrita
  acima).

## Testes

- Feature test no `EnvelopeController@index`: busca por `q` retorna só
  envelopes cujo título contém o termo (case-insensitive); filtro por `status`
  retorna só envelopes daquele status; busca + status combinados aplicam AND;
  ambos vazios retornam todos; paginação preserva `q` e `status` na URL
  (`withQueryString`).
- Não há teste automatizado de JS/Alpine no projeto atualmente — verificação
  manual no browser (debounce da busca, filtro de status imediato, navegação
  entre páginas sem reload, URL refletindo os filtros, paginação nova visível
  também em usuários/access-logs/certificados).

## Fora de escopo

- Filtros adicionais além de título e status (ex.: data de criação, canal de
  assinatura) — não pedidos, ficam para uma iteração futura se necessário.
- Alterar `->paginate()` (tamanho de página, ordenação) nas outras 3 telas
  afetadas pela paginação global — só o visual dos links muda, não o
  comportamento de cada listagem.
