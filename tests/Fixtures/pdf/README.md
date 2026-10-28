# PDFs propositadamente defeituosos

Um por regra de renderização (RNF-05: "testes do validador por render com PDFs de fixture
propositadamente defeituosos, um por regra R-xx").

São **artefactos guardados**, e é isso que os torna úteis. Vários destes defeitos são coisas
que o compositor hoje torna impossíveis — desde que a paginação passou para o servidor, um
campo `todas_paginas` não pode faltar numa página. Testar a regra contra o caminho de código
atual provaria apenas que o caminho atual está bom; testá-la contra um documento que já saiu
mal prova que a **regra** funciona, e continua a provar isso depois de o compositor mudar.

| Ficheiro | O defeito | Regra |
|---|---|---|
| `r-ok.pdf` | Nenhum — o controlo. Sem ele, todas as outras podiam estar a passar por o validador não encontrar nada | — |
| `r02-value-missing.pdf` | O ATCUD não aparece em nenhuma página | R-02 |
| `r03-missing-on-page.pdf` | ATCUD na página 1, ausente na 2 | R-03 |
| `r04-truncated.pdf` | ATCUD cortado por uma caixa de 8 mm com `overflow: hidden` | R-04 |
| `r04-outside.pdf` | ATCUD a 2 mm da borda, fora da área imprimível | R-04 |
| `r05-code-missing.pdf` | Sem código nenhum | R-05 |
| `r05-code-wrong.pdf` | Um QR que descodifica para outro conteúdo | R-05 |
| `r05-code-tiny.pdf` | Um QR de 6 mm — que **descodifica** a 300 dpi. Está aqui para fixar a fronteira: a R-05 prova que o código está correto, é a V-17 que prova que está grande o suficiente | R-05 / V-17 |
| `r06-too-many-pages.pdf` | 12 páginas | R-06 |
| `r02-hyphen-break.pdf` | Talão 80 mm (CGE-97): a morada quebrada pelo browser depois do hífen do código postal (`… Esquerdo, 4200-` / `072 Porto`) — tem de ser encontrada inteira; e `Código 9999- 888`, um hífen seguido de espaço na mesma linha, que não pode ser lido como `9999-888`; `ABCD-` / `12345678`, um token partido no hífen (tem de dar R-02); e `Bloco Fim-` seguido, 15 mm abaixo, de `seguinte texto` (não se juntam). Gerado por `Documents::render` sobre um rolo, fora do `GENERATOR.php` | R-02 |
| `r08-oversized.pdf` | Uma página em branco de ~500×500 mm (1417×1417 pts), muito maior do que qualquer folha. A porta de render recusa-a pelo tamanho, antes de medir o conteúdo | R-08 (porta de render, CGE-25) |

A R-01 (a renderização conclui) e a R-07 (tempo) não têm fixture: uma é uma exceção durante a
composição e a outra é um número medido, nenhuma delas é uma propriedade de um PDF.

Regenerar com `php tests/Fixtures/pdf/GENERATOR.php` — precisa do Gotenberg acessível
em `GOTENBERG_URL`. Os caminhos no gerador são absolutos e apontam para o scratchpad onde foi
escrito; ajustar antes de correr.

O `r08-oversized.pdf` é a exceção: é construído à mão (uma página em branco com um `MediaBox`
grande), não pelo Gotenberg, porque a porta de render só lê o tamanho da página e não precisa
de conteúdo. Não sai do `GENERATOR.php`.
