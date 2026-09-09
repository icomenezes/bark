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

1. Adicionar um campo de busca por título do documento, que filtra no servidor
   (funciona sobre todos os registros, não só a página atual).
2. Modernizar visualmente a paginação: pills arredondadas, centralizadas,
   consistente com o dark theme e a cor primária configurável (`--color-primary`).

Escopo: apenas a tela de envelopes (`client/envelopes/index.blade.php` +
`EnvelopeController@index`). Não altera outras telas paginadas do projeto.

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
  envelope encontrado para '<busca>'").

### Paginação modernizada

- View de paginação customizada **local** a esta tela (não substitui a view
  padrão global do Laravel, para não afetar outras telas): criar
  `resources/views/client/envelopes/partials/pagination.blade.php` e renderizar
  com `$envelopes->links('client.envelopes.partials.pagination')`.
- Visual: pills arredondadas (`rounded-full`), centralizadas horizontalmente
  (`flex justify-center`), item ativo com `background-color: var(--color-primary)`
  e texto branco, itens inativos com hover sutil (`hover:bg-gray-800`), setas
  prev/next como ícones SVG (chevron), estado disabled com opacidade reduzida e
  sem cursor pointer.
- Mantém o texto "Showing X to Y of Z results" ou equivalente em pt-BR
  ("Mostrando X a Y de Z"), centralizado acima ou ao lado dos pills conforme
  espaço.

## Testes

- Feature test no `EnvelopeController@index`: busca por `q` retorna só
  envelopes cujo título contém o termo (case-insensitive), busca vazia retorna
  todos, paginação preserva `q` na URL (`withQueryString`).
- Não há teste automatizado de JS/Alpine no projeto atualmente — verificação
  manual no browser (debounce, navegação entre páginas sem reload, URL
  refletindo o filtro).

## Fora de escopo

- Filtro por status (dropdown) — não pedido, fica para uma iteração futura se
  necessário.
- Paginação customizada global (outras telas) — só envelopes por enquanto.
