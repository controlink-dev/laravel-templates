Copiado do cgest em 2026-09-23; os caminhos `resources/css/print_forms/print.css` e `PrintFormController` referem-se ao código de origem.

---

# Impressos — notas de fidelidade canvas ↔ PDF (fase F1)

| Campo | Valor |
|---|---|
| Fase | F1 — spike de fidelidade (`docs/specs/spec-modulo-impressos-v1.1.md`, secção 12) |
| Data | 2026-09-02 |
| Veredicto | **Fidelidade aceitável.** O texto coincide ao centésimo de milímetro; os preenchimentos têm um erro conhecido e limitado de ±0,25 mm, de causa identificada. |
| Motor | Gotenberg 8 (`docker.io/gotenberg/gotenberg:8`) em podman, `/forms/chromium/convert/html`, produtor `Skia/PDF m151` |
| Editor | GrapesJS 0.23.6 (BSD-3-Clause), `dragMode: 'absolute'`, `storageManager: false` |

## 1. Como foi medido

A geometria não foi avaliada a olho. Um conjunto de sondas com posição e dimensão
declaradas em milímetros é colocado no canvas do GrapesJS; mede-se o
`getBoundingClientRect()` de cada uma dentro do iframe do canvas, normalizado pela
largura da folha (o que absorve o zoom do canvas); exporta-se `getHtml()`/`getCss()`;
o servidor compõe o documento final e envia-o ao motor; e o PDF resultante é medido
por dois caminhos independentes:

- **`pdftotext -bbox`** — caixas dos glifos em pontos, para o texto.
- **`pdftoppm -r 508`** — rasterização a 20 px/mm, para os preenchimentos.

Ambos os valores são convertidos para milímetros e subtraídos do que o canvas
reportou. O que se compara é sempre *canvas contra PDF*, nunca CSS contra PDF: é o
canvas que o utilizador vê, e é dele que a fidelidade tem de ser medida.

A folha de estilos de impressão é **a mesma nos dois lados** — `resources/css/print_forms/print.css`
é injetada no canvas por `canvas.styles` e enviada ao motor dentro do documento
composto. A geometria da página vem do `@page` dessa folha, com o motor chamado com
`preferCssPageSize=true` e margens a zero, para não existirem duas fontes de verdade.

## 2. Resultados

### 2.1 Página

| | Canvas | PDF | Divergência |
|---|---|---|---|
| Largura | 210,000 mm | 209,900 mm | −0,100 mm |
| Altura | 297,000 mm | 297,050 mm | +0,050 mm |

O PDF declara 594,96 × 841,92 pt, contra os 595,276 × 841,890 pt do A4 nominal.
Irrelevante para impressão, mas relevante para a validação: qualquer verificação de
"a caixa está dentro da área imprimível" tem de tolerar esta diferença.

### 2.2 Texto — coincide

| Sonda | Tamanho | Esquerda (canvas → PDF) | Largura (canvas → PDF) | Pior |
|---|---|---|---|---|
| T1 | 7 pt | 19,998 → 19,997 | 53,462 → 53,460 | **0,002 mm** |
| T2 | 9 pt | 19,998 → 19,997 | 39,006 → 39,005 | **0,001 mm** |
| T3 | 12 pt | 19,998 → 19,997 | 63,126 → 63,149 | **0,023 mm** |

Os glifos não são encaixados em grelha nenhuma: são colocados pela transformação
exata. É o resultado que interessa, porque **todos os campos legais textuais são
texto** — os limites de 7.4 e as regras V-11 a V-17 e R-04 medem texto, e é aí que a
fidelidade é praticamente perfeita.

O texto extraído é igual ao introduzido, carácter por carácter, incluindo o valor
longo de 38 caracteres a 7 pt. Não há truncagem silenciosa.

### 2.3 Preenchimentos — encaixam na grelha do pixel CSS

Marcas retangulares opacas, canvas → PDF:

| Sonda | Nominal | Erro de posição | Erro de dimensão |
|---|---|---|---|
| M1 | 10 × 1 mm em (15, 15) | +0,101 mm | −0,249 mm |
| M2 | 20 × 20 mm em (100, 50) | +0,002 mm | +0,102 mm |
| M3 | 10 × 1 mm em (185, 281) | −0,049 mm | +0,051 mm |

Duas barras com a **mesma** dimensão nominal (M1 e M3, ambas 10 × 1 mm) saem com
espessuras diferentes: 0,75 mm e 1,05 mm. Não é artefacto de medição — o perfil de
cinzentos do raster tem arestas duras, sem antialiasing. É o motor a arredondar.

Duas varreduras isolam a causa. Espessura nominal contra espessura impressa:

| Nominal (mm) | 0,10 | 0,20 | 0,30 | 0,40 | 0,50 | 0,60 | 0,70 | 0,80 | 0,90 | 1,00 |
|---|---|---|---|---|---|---|---|---|---|---|
| Impressa (mm) | 0,25 | 0,25 | 0,25 | 0,25 | 0,55 | 0,50 | 0,55 | 0,75 | 1,05 | 1,05 |

E a mesma barra de 1 mm deslocada em passos de 0,05 mm sai com o topo a variar entre
−0,10 mm e +0,10 mm e a espessura entre 0,80 mm e 1,10 mm.

**Causa:** o motor encaixa os preenchimentos em pixels CSS inteiros. 1 px CSS =
1/96 in = **0,264583 mm**, que é exatamente o quantum observado. Daí decorre:

- erro de posição de um preenchimento ≤ **±0,13 mm** (meio pixel);
- erro de espessura ≤ **±0,15 mm**, com quantum de 0,26 mm;
- espessuras nominais entre 0,10 mm e 0,40 mm imprimem **todas** iguais (0,25 mm);
- uma linha fina nunca desaparece — o mínimo é 1 px.

O texto não sofre disto. Só formas.

### 2.4 Tipo de letra

A fonte é auto-hospedada: o canvas carrega-a por URL, e o compositor substitui esse
URL por um `data:` URI antes de enviar ao motor, porque o motor corre sem rede
(secção 3.2). O PDF traz a fonte **embebida e subsetizada** (`AAAAAA+LiberationSans`,
CID TrueType) — não houve recurso a fonte de substituição em nenhum dos lados, o que
é o que torna a coincidência de 0,002 mm nas larguras de texto possível.

A substituição do URL pelo `data:` URI é obrigatória e tem de falhar ruidosamente: o
compositor conta as substituições e aborta se não fizer exatamente as esperadas. Sem
isso, o motor calaria a falta da fonte e cairia numa substituta — e todas as regras de
truncagem passariam a medir a fonte errada.

### 2.5 Determinismo

Oito renderizações do mesmo documento: **o raster é byte a byte idêntico** em todas.
O ficheiro PDF, não — mas a diferença são **dois bytes**, nas posições 234 e 269, que
são o dígito dos segundos em `/CreationDate` e `/ModDate`. Neutralizando essas duas
entradas, o SHA-256 é estável em todas as renderizações.

Consequência direta para RA-05 ("PDF regenerado tem hash igual"): **o hash não pode
ser calculado sobre os bytes crus do PDF.** Tem de ser calculado depois de normalizar
`/CreationDate` e `/ModDate`. A regra é cumprível; a implementação ingénua não.

### 2.6 Persistência

`getProjectData()` → JSON → `loadProjectData()` → `getProjectData()` devolve JSON
idêntico (1803 bytes, cadeia igual) e a geometria de todas as sondas no canvas tem
deriva **zero**. O `project_data` é serializável sem perda, como a secção 5 exige.

O que o editor exporta em `getCss()` traz duas regras próprias do GrapesJS
(`* { box-sizing: border-box; }` e `body { margin: 0 }`) e o `@font-face` com o URL da
fonte. O compositor tem de contar com isso: o CSS exportado não é só o do utilizador.

## 3. Consequências para as fases seguintes

| Onde | O que muda por causa destas medições |
|---|---|
| V-14, R-04 (caixa dentro da área imprimível) | Tolerância mínima de **0,3 mm**; recomendado 0,5 mm. Sem tolerância, um bloco colado à margem falha por arredondamento do motor, não por erro do utilizador. |
| Traço de linhas e retângulos (6.4) | Não expor espessura livre em milímetros: o editor prometeria o que o motor não imprime (0,1 e 0,4 mm saem iguais). Expor um conjunto fixo na grelha do motor — 0,26 / 0,53 / 0,79 mm (1, 2 e 3 px) — com etiquetas Fina / Média / Grossa. |
| Tabela de linhas (F4) | As bordas horizontais das linhas vão variar entre 0,26 e 0,53 mm consoante a posição vertical de cada linha, o que se lê como bordas desiguais. Mitigação: alturas de linha múltiplas de 0,26 mm, ou uma única borda por célula em vez de bordas adjacentes. |
| RA-04, RA-05 (`pdf_hash`) | Hash sobre o PDF **com `/CreationDate` e `/ModDate` normalizados**. Guardar também a versão do motor (`Skia/PDF m151`), porque uma atualização do contentor pode alterar o raster. |
| RNF-05 (regressão visual) | Viável e barata: o raster é determinístico, logo a comparação pode ser exata em vez de tolerante. A tolerância só é necessária entre versões diferentes do motor. |
| Fontes | A lista de famílias tem de ser fechada e auto-hospedada. Uma família que o utilizador escolha e o servidor não tenha em ficheiro quebra a medição de truncagem. |

## 4. O que este spike não provou

- **Paginação.** Só foi medida uma página. Cabeçalho e rodapé repetidos, quebra
  automática do corpo e `@page` margin boxes para a numeração ficam para a F4.
- **Twig.** O compositor testado não passou por Twig; as sondas são texto estático. A
  política de sandbox da secção 7.2 está por validar.
- **Deploy.** O Gotenberg correu em podman nesta máquina, em `localhost:3100`. Quem o
  corre em produção continua em aberto — o repositório não tem automação de deploy.
- **Fonte de produção.** O spike usou Liberation Sans do sistema, em TTF. A versão de
  produção deve trazer a família em WOFF2 dentro do repositório; `woff2_compress` não
  está instalado nesta máquina.

## 5. Reproduzir

```bash
podman run -d --rm --name cgest-gotenberg -p 3100:3000 docker.io/gotenberg/gotenberg:8
curl -s http://localhost:3100/health
```

O harness de medição desta fase é provisório e vive fora do repositório. A sua forma
definitiva é o teste de regressão visual da F6 (RNF-05), onde as sondas passam a
fixture e a comparação de raster passa a asserção.

---

# Paginação: repetir cabeçalho e rodapé (fase F4)

A F1 deixou isto explicitamente em aberto, com a nota de que tinha de ser **medido antes de
adotado**. Foi. Quatro abordagens, o mesmo conteúdo de 120 linhas a ocupar 3 páginas, medidas
página a página com `pdftotext -bbox` e com raster a 508 dpi.

| Abordagem | Cabeçalho repete | Rodapé repete | Geometria exata | Veredicto |
|---|---|---|---|---|
| A — `position: fixed` nas duas bandas | sim | sim | **não** | **Eliminada** |
| B — `thead`/`tfoot` como grupos de tabela | sim | só até à penúltima página | sim | Insuficiente |
| C — uma `.page` por folha, paginada pelo servidor | sim | sim | sim | Viável, com custo |
| **E — `thead` + `tfoot` vazio + rodapé `fixed`** | **sim** | **sim** | **sim** | **Adotada** |

## Porque a mais óbvia é a errada

O `position: loose` — perdão, `position: fixed` — repete as bandas em todas as páginas, e a
olho parece resolvido. **Não reserva espaço.** O `padding-top` do corpo aplica-se uma vez, na
primeira página; a partir da segunda o conteúdo corre por baixo da banda:

```
página 1: 0 linhas dentro da banda do cabeçalho
página 2: 10 linhas dentro da banda do cabeçalho, a mais alta em y=15,7 mm
página 3: 2 linhas dentro da banda do cabeçalho
```

Os 15,7 mm são onde vivem o número do documento e o ATCUD. Esta abordagem sobreimprimia
campos legais em todas as páginas menos a primeira, e o PDF continuaria a conter o texto —
portanto a R-02 e a R-03 **passariam** enquanto o documento impresso estava ilegível. É o pior
tipo de defeito: silencioso e a passar os testes.

## Porque a segunda também não chega

Com o cabeçalho num `thead` (`display: table-header-group`) o Chromium repete-o **e** reserva
espaço: zero sobreposições em todas as páginas. O rodapé num `tfoot` repete, mas na **última**
página sobe e cola-se ao fim do conteúdo em vez de ficar no fundo da folha:

```
b3: rodapé a 262,52 mm nas páginas 1 e 2, a 136,58 mm na página 3
```

`table { height: 100% }` não corrige (testado), e uma linha de enchimento de altura 100% no
fim do `tbody` também não (testado).

## A adotada, e a razão de cada parte

```
thead  → banda do cabeçalho     repete e reserva espaço
tfoot  → célula VAZIA, só altura  reserva o espaço do rodapé em todas as páginas
div    → rodapé, position: fixed  desenha-o no fundo, incluindo na última página
tbody  → corpo, em fluxo          pagina
```

O `tfoot` vazio existe só para reservar altura; o rodapé visível é um elemento fixo, que não
precisa de reservar nada porque o `tfoot` já o fez. Medido, com um traço opaco de 1 mm no topo
de cada zona (os retângulos medem-se ao pixel, o texto não):

| | Nominal | Medido, páginas 1, 2 e 3 |
|---|---|---|
| Traço no topo do cabeçalho | 15,0 mm | **15,1–15,8 mm** nas três |
| Traço no fundo do rodapé | 281,0–282,0 mm | **281,0–281,75 mm** nas três |
| Linhas dentro de qualquer banda | 0 | **0** nas três |

A espessura de 0,7 mm para um traço de 1 mm é o encaixe na grelha do pixel CSS já
caracterizado na secção 2.3 — não é erro de posição.

**Nota sobre medir texto:** o mesmo cabeçalho medido por `pdftotext` dava 12,75 mm, 2,25 mm
acima da zona. Não é deslocamento: é a caixa do glifo de um negrito **sintetizado** (a família
embebida só tem o peso Regular). Para verificar geometria de zonas usa-se um retângulo, nunca
texto — e um peso que não exista em ficheiro é uma razão a mais para a lista de fontes ser
fechada.

## Consequência estrutural

O canvas continua com as zonas absolutas dentro de uma `.page` fixa; **é o compositor que
constrói a estrutura de tabela** que faz o motor repetir as bandas. Não é um desvio: a secção
7.1, passo 3, diz exatamente isso — "compor documento final: estrutura de página com
cabeçalho/rodapé repetíveis e corpo". As duas estruturas partilham a mesma folha de estilos e,
como acima, aterram nos mesmos milímetros.

## O número de página não é possível como bloco

O contador de páginas do CSS **não funciona** no Chromium fora das margin boxes, que ele não
implementa. Medido: `counter(page)` e `counter(pages)` imprimem `0` em todas as páginas.

O que funciona é o rodapé nativo do Gotenberg (`footer.html` com `.pageNumber` e
`.totalPages`), e — ao contrário do que se poderia temer — **coexiste com o `preferCssPageSize`**:

| `preferCssPageSize` | `marginBottom` | Formato do PDF | Número impresso |
|---|---|---|---|
| `true` | 0 | **A4 (o nosso `@page`)** | **nas 3 páginas** |
| `true` | 0,6 in | A4 | nas 3 páginas |
| `false` | 0,6 in | letter (do motor) | nas 3 páginas |

Ou seja: a geometria continua a ser a nossa e o número sai correto. O que **não** é nosso é a
posição — o motor pinta-o a y≈288 mm, à largura toda, dentro da margem inferior que as nossas
zonas deixam em branco. Não colide com nada, mas o utilizador não o pode mover.

Consequência para a spec: o "Número de página" da 6.4 não pode ser um bloco arrastável.
Ou passa a ser uma opção do tipo de documento, ou exige a paginação no servidor (abordagem C).
Decisão em aberto — ver `docs/decisions.md`.

## A paginação do motor perde o cabeçalho na última página

A estrutura adotada acima foi medida com 120 linhas e passou. Com mais linhas, não passa.

Mapeado, variando o número de linhas e observando cada página (`JFT7` = o ATCUD, um campo
legal de abrangência `todas_paginas`):

| Linhas | Páginas | Linhas por página | Cabeçalho na última |
|---|---|---|---|
| 119 | 3 | 59, 59, 1 | **ausente** |
| 130 | 3 | 59, 59, 12 | presente |
| 145 | 3 | 59, 59, 27 | presente |
| 178 | 4 | 59, 59, 59, 1 | **ausente** |
| 180 | 4 | 59, 59, 59, 3 | **ausente** |

Quando a última página leva poucas linhas, o Chromium **não emite o grupo de cabeçalho
repetido**, e o cabeçalho da tabela sobe para y=0,14 mm — onde devia estar o cabeçalho do
documento. Uma fatura que acabe com três linhas, que é o caso mais comum que existe, sai sem
ATCUD e sem número de documento na última página.

### A mitigação óbvia troca um defeito por outro

Um espaçador de altura fixa no fim do corpo devolve o cabeçalho:

| Linhas | Sem espaçador | Com 45 mm |
|---|---|---|
| 119 | 3 páginas, última sem cabeçalho | 3 páginas, correto |
| 180 | 4 páginas, última sem cabeçalho | 4 páginas, correto |
| **175** | 3 páginas, correto | **4 páginas, a última em branco** |

Com a última página quase cheia (57 de 59 linhas), o espaçador não cabe e empurra uma página
em branco. Não há altura de espaçador que sirva os dois extremos: pequena demais e o
cabeçalho continua a desaparecer, grande demais e aparecem páginas vazias.

### Conclusão

**A paginação pelo motor não é utilizável para documentos certificados.** Tem dois modos de
falha silenciosos, ambos medidos, e mitigar um provoca o outro. A alternativa é a abordagem C
da tabela acima — o servidor emite uma `.page` por folha, cada uma com a sua cópia do
cabeçalho e do rodapé — que na medição saiu exata em todas as páginas e que, de passagem,
torna o número de página possível e posicionável pelo utilizador.

O custo da C é conhecido: para saber quantas linhas cabem numa página é preciso uma altura de
linha fixa. Decisão em aberto.

---

# Ler o PDF de volta (fase F5)

A camada 2 não lê o layout: lê o documento que o motor produziu. Duas medições decidiram como.

## O descodificador de QR não encontra o código numa página inteira

O `khanamiryan/qrcode-detector-decoder` lê o código sem dificuldade — desde que lhe deem uma
imagem que seja quase toda o código. Entregue a página A4 completa, não lê nada:

| O que se entrega ao descodificador | Resultado |
|---|---|
| Página inteira, 300 dpi (2479 × 2508 px) | **nada lido** |
| Página inteira, 600 dpi | **nada lido** |
| Página inteira, 900 dpi | **nada lido** |
| Recorte de 402 × 402 px em volta do slot, 300 dpi | **conteúdo correto** |

Não é resolução: é a procura. Por isso o `RenderedPdf::rasterOf()` aceita uma caixa e recorta
(com 2 mm de margem, para o código levar a sua zona de silêncio), e as caixas dos slots vêm do
layout através do `LayoutValidator::codeSlotBoxes()`.

**A consequência de não ter percebido isto seria pior do que parece.** Escrita sem o recorte, a
R-05 falhava em todos os documentos e nada podia ser ativado; escrita ao contrário — "se não
descodificar, passa" — passava em todos, e um QR ilegível chegava ao cliente.

## A R-05 não é uma verificação de tamanho

Um QR de **6 mm** descodifica perfeitamente de um raster a 300 dpi. Um descodificador de
software não é a câmara de um telemóvel a um palmo de distância.

Logo as duas regras não se substituem: a **R-05** prova que o código está *correto*, a **V-17**
prova que está *grande o suficiente*. O fixture `r05-code-tiny.pdf` existe exactamente para
fixar esta fronteira, e o teste que o usa afirma que **passa** — lê-lo como um bug é lê-lo ao
contrário.

## Medir geometria de texto: usar a corrida de palavras, não a palavra

O `pdftotext` parte um valor em várias palavras, e um valor monetário formatado pelo ICU traz
espaços inquebráveis. Procurar "a palavra" de um campo não funciona. O que funciona é localizar
a **corrida consecutiva** de palavras cujo texto, normalizado, soma o valor esperado — e a união
das suas caixas é a caixa do valor como saiu impresso.

Isso dá as duas coisas ao mesmo tempo: a prova de que o valor está **inteiro** (a truncagem que
a camada 1 se recusa a adivinhar) e a caixa para a comparação com a área imprimível.

O valor esperado é calculado pelos **mesmos filtros do template**, nunca lido cru do fixture:
comparar a página contra `1229999.98` falharia num documento que corretamente mostra
`1 229 999,98 €`.

## Requisito de deploy que isto acrescenta

`poppler-utils` no servidor (`pdftotext`, `pdftoppm`, `pdfinfo`) e a extensão `gd` do PHP.
Sem eles a ativação falha, com mensagem que o diz. É deliberado: a alternativa é ativar um
layout que ninguém verificou.
