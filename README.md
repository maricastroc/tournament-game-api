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
├── Standings/
│   ├── Criterion.php     # enum dos critérios de desempate
│   ├── TiebreakRules.php # cadeia ordenada — ::fifa(), ::of(...)
│   ├── Standing.php      # value object imutável (linha da tabela)
│   └── GroupTable.php    # a engine pura de classificação
└── Bracket/
    ├── SlotSource.php      # de onde vem um lado (seed de grupo | vencedor de confronto)
    ├── Tie.php             # topologia de um confronto do mata-mata
    ├── TieResult.php       # DTO: placar + pênaltis
    ├── MatchOutcome.php    # decide o vencedor (tempo normal e pênaltis)
    ├── ResolvedTie.php     # value object: confronto resolvido (o que a UI consome)
    └── BracketResolver.php # engine pura do mata-mata (deriva vagas, vencedores, campeão)
```

**Regra de dependência:** `Laravel → Domain`, nunca o contrário. Nada em `app/Domain` pode
importar `Illuminate\*` ou `App\Models\*`.

## Estado atual

- [x] `GroupTable` — engine pura de classificação, com critérios configuráveis e confronto
      direto recursivo (mini-liga entre os empatados).
- [x] `BracketResolver` — engine pura do mata-mata: deriva participantes, decide vencedores
      (inclui pênaltis), elege o campeão e propaga "a definir" pelas rodadas.
- [x] Migrations + models Eloquent (`tournaments`, `teams`, `stages`, `groups`, `matches`, `ties`),
      com índices e a coluna `version` (lock otimista).
- [x] Action `ConfirmMatchResult` — costura Eloquent ↔ Domain dentro da transação, com lock
      otimista que rejeita edição concorrente (`StaleResultException` → HTTP 409).
- [x] API REST com Sanctum: auth por token, leituras públicas (classificação e chaveamento),
      lançamento de resultado protegido por dono (Policy), validação (422) e conflito de versão (409).
- [x] `BracketResolver` ligado ao banco: mata-mata derivado das sementes (projeção dos grupos)
      + topologia; o mesmo endpoint de resultado atende grupo (→ classificação) e mata-mata (→ chaveamento).
- [x] Seeder de demo: "Copa Atlas 2026" completa (4 grupos decididos + mata-mata em andamento)
      num comando, com organizador de credenciais conhecidas — API rica e navegável na hora.
- [x] Testes: 13 cenários de Domain + property test (300 grupos) + 16 feature tests (banco real +
      API ponta a ponta, incl. avanço no mata-mata, pênaltis e o seeder). **29 testes, ~3400 asserções.**
- [ ] Geração automática de chaveamento a partir dos grupos + CRUD de montagem do torneio.

## API

| Método | Rota | Auth | O quê |
|--------|------|------|-------|
| `POST` | `/api/register` · `/api/login` | — | emite token Sanctum |
| `GET`  | `/api/groups/{group}/standings` | — | classificação do grupo (projeção das partidas) |
| `GET`  | `/api/stages/{stage}/bracket` | — | chaveamento resolvido + campeão |
| `PUT`  | `/api/matches/{fixture}/result` | dono | lança/edita resultado → grupo devolve classificação, mata-mata devolve chaveamento; 409 em conflito de versão |
| `GET`  | `/api/user` · `POST /api/logout` | token | sessão |

## Rodando

Antes do Composer, as engines já rodam — os runners de fumaça não dependem de nada:

```bash
php scripts/smoke.php          # classificação de grupo
php scripts/smoke-bracket.php  # mata-mata
```

Depois de instalar as dependências, a suíte Pest:

```bash
composer install
./vendor/bin/pest
```

### Demo

Um comando popula a "Copa Atlas 2026" inteira (grupos decididos + mata-mata em andamento):

```bash
php artisan migrate:fresh --seed
```

Organizador de teste — para os endpoints protegidos: **`demo@bracket.test`** / **`password`**.
Depois é só navegar: `GET /api/stages/{id}/bracket`, `GET /api/groups/{id}/standings`.

## Notas

- `docs/mocks/` guarda o mock de alta fidelidade da interface (referência de design; a UI em
  si viverá no frontend, projeto à parte).
- Simplificações documentadas na engine: a ordem exata do regulamento FIFA é afinável só
  reordenando `TiebreakRules::fifa()`; o critério de sorteio (aleatório) é substituído por uma
  ordem determinística de entrada, melhor para reprodutibilidade e testes.
