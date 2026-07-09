# tournament-game-api

API de gestão de torneios. O valor de engenharia não está nas telas — está em manter o
**estado sempre coerente**: classificação, critérios de desempate e avanço de chave que se
recalculam, em transação, a cada resultado lançado.

Princípio central: **o estado é uma projeção, não um dado.** A fonte da verdade são os
resultados das partidas; classificação, saldo, quem avançou e o campeão são *derivados* por
funções puras. Editar um resultado é recomputar a projeção — não sincronizar estado mutável.

## Arquitetura

O núcleo de regras vive em `app/Domain/Tournament`, sem nenhuma dependência de framework
(zero `Illuminate\`, zero Eloquent). Controllers, Eloquent, Requests e migrations continuam
Laravel; a tradução Eloquent → DTO acontece na borda (camada de Actions).

```
app/Domain/Tournament/
├── Input/
│   ├── MatchResult.php   # DTO: resultado de partida encerrada
│   └── TeamRef.php       # DTO: referência de time
└── Standings/
    ├── Criterion.php     # enum dos critérios de desempate
    ├── TiebreakRules.php # cadeia ordenada — ::fifa(), ::of(...)
    ├── Standing.php      # value object imutável (linha da tabela)
    └── GroupTable.php    # a engine pura de classificação
```

**Regra de dependência:** `Laravel → Domain`, nunca o contrário. Nada em `app/Domain` pode
importar `Illuminate\*` ou `App\Models\*`.

## Estado atual

- [x] `GroupTable` — engine pura de classificação, com critérios configuráveis e confronto
      direto recursivo (mini-liga entre os empatados).
- [x] Testes: 5 cenários nomeados + 1 property test (300 grupos aleatórios, seed fixa).
- [ ] `BracketResolver` — avanço do mata-mata (inclui pênaltis).
- [ ] Migrations + models Eloquent (`tournaments`, `stages`, `groups`, `matches`, `ties`).
- [ ] Action `ConfirmMatchResult` — costura Eloquent ↔ Domain dentro da transação.
- [ ] Laravel + Sanctum + endpoints da API.

## Rodando

Antes do Composer, a engine já roda — o runner de fumaça não depende de nada:

```bash
php scripts/smoke.php
```

Depois de instalar as dependências, a suíte Pest:

```bash
composer install
./vendor/bin/pest
```

## Notas

- `docs/mocks/` guarda o mock de alta fidelidade da interface (referência de design; a UI em
  si viverá no frontend, projeto à parte).
- Simplificações documentadas na engine: a ordem exata do regulamento FIFA é afinável só
  reordenando `TiebreakRules::fifa()`; o critério de sorteio (aleatório) é substituído por uma
  ordem determinística de entrada, melhor para reprodutibilidade e testes.
