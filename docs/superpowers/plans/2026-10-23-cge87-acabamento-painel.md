# CGE-87 — Acabamento visual do painel do editor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Painel lateral do editor de impressos com um só contorno por controlo, cantos arredondados visíveis e nenhum rótulo derivado do nome técnico do atributo.

**Architecture:** Só o package `laravel-templates`: CSS em `resources/css/editor.css` e um ajuste de traits em `resources/js/editor/table.js`. Nada muda no cgest além do bundle publicado (release do coord).

**Tech Stack:** GrapesJS (TraitManager), CSS, testes `node:test`/`assert` em `tests/js/*.test.mjs`.

**Spec:** OpenProject CGE-87 (sem doc de desenho; o diagnóstico está abaixo).

## Diagnóstico (medido em `http://cge87.test`, Documento Fiscal, 2026-10-23)

1. **Cantos tapados.** `.gjs-field` tem `border-radius: .5rem`, `overflow: visible` e fundo branco; o `select`/`input` de dentro tem `border-radius: 0` e fundo branco opaco (medido: `ir: 0px`, `ibg: rgb(255,255,255)`). O canto quadrado do controlo pinta por cima do canto redondo da borda — vê-se em Zona, Origem, Ajuste, etc.
2. **Bordas duplas.** A regra genérica `.gjs-field` (borda + fundo, `padding: 0`) aplica-se também aos traits compostos cujo input é um contentor: `.gjs-field-tpl-columns` (cartões de coluna), `.gjs-field-tpl-file` (input de ficheiro + "Remover imagem"), `.gjs-field-tpl-code-content` (Modelo / Origem de dados, ou tipo + campos no modo fixo). Já existe a exceção certa para `.gjs-field-tpl-value-mode` e `.gjs-field-tpl-formula` (editor.css ~1898).
3. **"Data tpl code content".** O trait `tpl-code-content` (table.js ~1083) não tem `label`; o GrapesJS deriva-o do `name` (`data-tpl-code-content`). As próprias linhas já trazem rótulos ("Modelo", "Origem de dados"), por isso o rótulo de fora é redundante.

## Decisões

- O trait do conteúdo do código fica **sem rótulo** (`label: false`) e a toda a largura, como o `tpl-formula`; as linhas de dentro alinham a coluna de rótulo com a dos outros traits (Zona, Origem), para parecer a mesma grelha. Não se cria string nova.
- Cantos: o controlo de dentro herda o raio (`border-radius: calc(.5rem - 1px)`), em vez de `overflow: hidden` no campo (cortaria focus ring e popups).
- Traits compostos: sem borda nem fundo no contentor; cada controlo de dentro mantém o seu próprio contorno; espaçamento vertical entre eles (`gap`).

## Global Constraints

- Não mexer na lógica dos traits (valores, eventos); só rótulo e apresentação.
- `dist/` do package é committado na branch; `public/vendor/templates` do cgest NÃO (skip-worktree).
- Ficheiros quentes: correr `equipa.ps1 quente cge87 resources/css/editor.css` e `... resources/js/editor/table.js` antes de editar.

## Review Focus

1. Outro trait (atual ou futuro) sem `label` volta a mostrar nome técnico → teste que percorre todos os tipos de bloco (Task 1).
2. Código em modo **Texto fixo** (tipo de conteúdo + campos, campo obrigatório vazio a vermelho) — a borda vermelha `--missing` tem de continuar visível sem o contentor.
3. Imagem **com** ficheiro carregado (botão "Remover imagem" visível) e sem ficheiro.
4. Selects de unidades no separador Estilo (`gjs-field-integer` com unidades) — não podem ganhar cantos estranhos com a regra do raio.
5. Foco (`:focus-within`) num controlo dentro de um trait composto — o anel de foco não pode aparecer à volta do contentor inteiro que já não tem borda.

---

### Task 1: Nenhum trait com rótulo derivado do nome

**Files:**
- Modify: `resources/js/editor/table.js` (~1083, trait `tpl-code-content`)
- Create: `tests/js/trait-labels.test.mjs`

- [ ] **Step 1: Teste a falhar.** Mesmo harness de `tests/js/nesting.test.mjs` (stub do editor, `registerComponents` + `registerTableAndSlot`). Para cada tipo em `['static-text','data-field','print-image','print-rule','page-number','line-table','code-slot']`, cada trait de `model.defaults.traits` (objeto) tem `label === false` ou `typeof label === 'string' && label !== ''`; mensagem `${type}.${trait.name} has no label`. Traits que sejam string (nome simples) contam como falha.
- [ ] **Step 2:** `node tests/js/trait-labels.test.mjs` → FAIL em `code-slot.data-tpl-code-content`.
- [ ] **Step 3:** `label: false` no trait `tpl-code-content`.
- [ ] **Step 4:** teste passa; correr os outros `tests/js/*.test.mjs` (todos verdes).
- [ ] **Step 5:** commit `fix(editor): code content trait shows no derived label (CGE-87)`.

### Task 2: Um contorno por controlo e cantos visíveis

**Files:**
- Modify: `resources/css/editor.css` (regra `.gjs-field` ~949; exceção ~1898; `.tpl-code-content__row` ~388; `.gjs-trt-trait--tpl-columns` ~166)

- [ ] **Step 1:** controlo de dentro herda o raio: `.tpl-editor-root .gjs-field > input, > select, > textarea { border-radius: calc(0.5rem - 1px); }`.
- [ ] **Step 2:** estender a exceção existente (`border: 0; background: transparent`) a `.gjs-field-tpl-columns`, `.gjs-field-tpl-file`, `.gjs-field-tpl-code-content`; o `:focus-within` também não desenha anel nesses contentores.
- [ ] **Step 3:** `tpl-file`: o input de ficheiro ganha o contorno de campo (borda `--tpl-edge`, raio .5rem) e há `gap: .375rem` entre ele e "Remover imagem".
- [ ] **Step 4:** `tpl-code-content`: trait a toda a largura (`flex-direction: column` como `tpl-formula`), linhas com `grid-template-columns` igual à largura do `.gjs-label-wrp` dos traits vizinhos, `gap` entre linhas; os rótulos das linhas com o mesmo tipo/peso dos rótulos de trait.
- [ ] **Step 5:** `npm run build` no package; `php artisan vendor:publish --tag=templates-assets --force` no cgest.
- [ ] **Step 6:** commit `style(editor): one outline per panel control, rounded corners visible (CGE-87)` (inclui `dist/`).

### Task 3: Revisão do painel inteiro no browser

- [ ] **Step 1:** em `http://cge87.test` (licença `TAREFA cge87`, Documento Fiscal) selecionar cada bloco — Texto, Campo de dados (Campo e Fórmula), Imagem (com e sem ficheiro), Linha, Nº de página, Tabela, QR (Dinâmico e Texto fixo com campo vazio) — e ver separadores Campo, Estilo, Camadas, Amostra. Confirmar os 5 pontos do Review Focus.
- [ ] **Step 2:** qualquer caso novo de borda dupla/canto tapado encontrado → corrigir em editor.css na mesma linha da Task 2 e commit `style(editor): ... (CGE-87)`.
- [ ] **Step 3:** suite JS e `vendor/bin/phpunit tests/Feature/EditorComponentTest.php` verdes; output para ficheiro.

## Fora de âmbito (achados para registar à parte)

- Select "Ajuste" (Fit) da imagem aparece vazio quando o atributo não está definido.
- Input de ficheiro nativo mostra o texto do browser ("Escolher ficheiro / Nenhum ficheiro selecionado") — não é traduzível por CSS.
