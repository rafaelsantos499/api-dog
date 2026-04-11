# 🐾 API Dog — API de Rede Social para Pets

> Uma API REST pronta para produção voltada a uma rede social de pets, construída com **Laravel 12**. Projeto de portfólio desenvolvido para demonstrar habilidades de engenharia back-end como integração com IA, processamento assíncrono, autenticação multi-provedor e design de API com foco em performance.

---

## 📌 Visão Geral

**API Dog** é o back-end de uma plataforma social onde usuários compartilham fotos dos seus pets. Cada post e comentário passa por um **pipeline de moderação com IA** (OpenAI + Google Gemini com fallback automático) que valida se o conteúdo contém um pet e filtra comentários ofensivos. A arquitetura prioriza velocidade: curtidas e visualizações são tratadas com **operações atômicas no Redis** e persistidas de forma assíncrona via filas.

---

## ✨ Principais Funcionalidades

- **Autenticação multi-provedor** — email/senha, Google OAuth (Socialite) e verificação de token Firebase (mobile)
- **Moderação de conteúdo com IA** — validação de imagens de pets e pontuação de toxicidade de comentários usando `laravel/ai` com OpenAI como primário e Gemini como backup; resultados cacheados por 7 dias para evitar chamadas redundantes
- **Sistema de curtidas e visualizações via Redis** — contadores atualizados instantaneamente no Redis e sincronizados com o banco de forma assíncrona via jobs (`PersistLikeJob`, `PersistViewJob`)
- **Pipeline de processamento de imagens** — cada upload gera três variantes WebP (original · feed · thumbnail) usando `intervention/image`
- **Paginação do feed por cursor** — feed público stateless e escalável com cache no Redis
- **Enriquecimento de sessão** — cada login registra dispositivo, navegador, plataforma, IP e geolocalização (via `ip-api.com`)
- **Rate limiting de comentários** — limite por janela deslizante por usuário; comentários bloqueados repetidamente pela IA geram um timeout configurável
- **Documentação OpenAPI / Swagger** — documentação completa da API gerada automaticamente por anotações

---

## 🏗 Arquitetura

```
┌─────────────────────────────────────────────────┐
│                  Camada de API                   │
│  Controllers → Services → Models (Eloquent ORM)  │
├────────────────────┬────────────────────────────┤
│  Moderação com IA  │    Fila Assíncrona          │
│  PetValidationAgent│  PersistLikeJob             │
│  CommentAgent      │  PersistViewJob             │
│  OpenAI + Gemini   │  (Redis → MySQL sync)       │
├────────────────────┴────────────────────────────┤
│              Infraestrutura                      │
│   MySQL · Redis · Firebase Auth · S3-compatível  │
└─────────────────────────────────────────────────┘
```

---

## 🛠 Stack Tecnológica

| Camada | Tecnologia |
|---|---|
| Framework | Laravel 12 (PHP 8.3+) |
| Autenticação | Laravel Sanctum (access + refresh tokens), Socialite, Firebase Admin SDK |
| IA | `laravel/ai` — OpenAI GPT-4o, Google Gemini (saída estruturada + anexos de imagem) |
| Cache / Filas | Redis |
| Processamento de Imagens | `intervention/image` v3 (GD, saída WebP) |
| Banco de Dados | MySQL |
| Conteinerização | Docker + Docker Compose (Nginx + PHP-FPM) |
| Docs da API | OpenAPI 3.0 via `l5-swagger` |
| Testes | PHPUnit 11 |
| Qualidade de Código | Laravel Pint (PSR-12) |

---

## 📡 Endpoints da API

### Autenticação

| Método | Endpoint | Auth | Descrição |
|---|---|---|---|
| `POST` | `/auth/register` | — | Cadastro com email e senha |
| `POST` | `/auth/login` | — | Login; retorna access + refresh tokens |
| `GET` | `/auth/google` | — | Retorna a URL de redirecionamento do Google OAuth |
| `GET` | `/auth/google/callback` | — | Processa o código de retorno do Google OAuth |
| `POST` | `/auth/firebase` | — | Verifica token Firebase (mobile) |
| `POST` | `/auth/refresh` | Sanctum | Renova o access token usando o refresh token |
| `POST` | `/auth/logout` | Sanctum | Revoga o token atual |
| `GET` | `/auth/me` | Sanctum | Retorna o perfil do usuário autenticado |

### Posts

| Método | Endpoint | Auth | Descrição |
|---|---|---|---|
| `POST` | `/posts/upload` | Sanctum | Faz upload de foto do pet — dispara validação por IA + processamento de imagem |
| `GET` | `/posts/{uuid}` | Sanctum | Busca um post pelo UUID |
| `PUT` | `/posts/{uuid}` | Sanctum | Atualiza metadados do post |
| `DELETE` | `/posts/{uuid}` | Sanctum | Soft-delete de um post |

### Curtidas

| Método | Endpoint | Auth | Descrição |
|---|---|---|---|
| `POST` | `/posts/{uuid}/like` | Sanctum | Curtir post (Redis + persistência assíncrona) |
| `POST` | `/posts/{uuid}/unlike` | Sanctum | Descurtir post (Redis + persistência assíncrona) |

### Comentários

| Método | Endpoint | Auth | Descrição |
|---|---|---|---|
| `GET` | `/posts/{uuid}/comments` | Sanctum | Lista comentários (paginação por cursor) |
| `POST` | `/posts/{uuid}/comments` | Sanctum | Adiciona comentário — moderação por IA aplicada |
| `PUT` | `/posts/{uuid}/comments/{comment}` | Sanctum | Edita comentário próprio |
| `DELETE` | `/posts/{uuid}/comments/{comment}` | Sanctum | Remove comentário (autor ou dono do post) |

### Feed

| Método | Endpoint | Auth | Descrição |
|---|---|---|---|
| `GET` | `/feed` | — | Feed público com paginação por cursor (cache Redis) |

---

## 🤖 Moderação com IA

Ambos os pipelines de moderação seguem o mesmo design:

1. **Gera hash da entrada** (MD5 para imagens, SHA1 para texto) e consulta o cache — pula a inferência se já foi avaliado
2. **Chama o provedor primário** (OpenAI) com um schema de saída estruturada
3. **Fallback para o Gemini** se o primário atingir timeout ou retornar erro
4. **Fail-open** — se ambos os provedores falharem, o conteúdo é aprovado (configurável)

### Validação de Imagem de Pet (`PetValidationAgent`)

Retorna `{ safe: bool, valid: bool, reason: string }`:
- `safe`: nenhum conteúdo de nudez, gore ou símbolo de ódio detectado
- `valid`: a imagem contém um pet reconhecível (cachorro, gato, coelho, pássaro, etc.)

### Pontuação de Toxicidade em Comentários (`CommentValidationAgent`)

Retorna `{ score: int, blocked: bool, reason: string }`:
- Pontuação de 0 a 100; threshold ≥ 70 bloqueia o comentário
- Detecta discurso de ódio, ameaças, xingamentos, assédio e linguagem codificada
- Após 5 comentários bloqueados em uma janela de 60 minutos, o usuário recebe um timeout de 30 minutos

---

## 🗄 Schema do Banco de Dados (Simplificado)

```
users               posts               post_comments
─────────────       ──────────────      ─────────────
id                  id                  id
uuid                uuid                uuid
name                user_id (FK)        post_id (FK)
email               original_path       user_id (FK)
password            feed_path           body
google_id           thumb_path          deleted_at
comment_timeout_    title
  until             description
                    views / likes / comments_count
user_sessions       is_published / published_at
─────────────       deleted_at
user_id (FK)
token_id (FK)       post_likes          post_views
ip / device /       ──────────          ──────────
  platform /        post_id (FK)        post_id (FK)
  browser /         user_id (FK)        user_id (FK)
  location          UNIQUE(post,user)   UNIQUE(post,user)
```

---

## 🚀 Rodando Localmente

### Com Docker (recomendado)

```bash
cp .env.example .env
docker compose up -d
docker compose exec app php artisan migrate --seed
```

A API estará disponível em `http://localhost:8080`.

### Sem Docker

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

> Requer PHP 8.3+, MySQL e Redis.

---

## ⚙️ Principais Variáveis de Ambiente

```env
# Banco de dados
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=api_dog

# Redis
REDIS_HOST=127.0.0.1

# Provedores de IA
AI_DEFAULT_PROVIDER=openai
OPENAI_API_KEY=sk-...
GEMINI_API_KEY=...

# Firebase (autenticação mobile)
FIREBASE_CREDENTIALS=path/to/serviceAccount.json

# Storage
FILESYSTEM_DISK=public   # ou s3
```

---

## 📖 Documentação da API

Após iniciar o servidor, o Swagger UI está disponível em:

```
http://localhost:8080/api/documentation
```

---

## 🌱 Seeders

O projeto conta com seeders completos para popular o banco com dados realistas, facilitando testes manuais e demonstrações.

```bash
php artisan db:seed
```

| Seeder | O que gera |
|---|---|
| `DatabaseSeeder` | 10 usuários aleatórios via factory + 1 usuário fixo de teste; orquestra os demais seeders em ordem |
| `PostsSeeder` | 3 posts por usuário com imagens reais baixadas do [picsum.photos](https://picsum.photos), gerando as 3 variantes (original · feed · thumb) no storage |
| `PostLikesSeeder` | Distribui curtidas aleatórias (1–5 por post) entre os usuários e sincroniza o contador `likes` em cada post |
| `PostCommentsSeeder` | Adiciona 2–5 comentários por post com frases em português e sincroniza o contador `comments_count` |

> Ao rodar `php artisan migrate --seed`, todo o fluxo é executado automaticamente em sequência.

---

## 🧪 Testes

```bash
php artisan test
# ou
./vendor/bin/phpunit
```

---

## 📂 Estrutura do Projeto

```
app/
├── Ai/Agents/          # Definição dos agentes de IA (PetValidation, CommentValidation)
├── Http/Controllers/   # AuthController, PostController, LikeController, CommentController, FeedController…
├── Jobs/               # PersistLikeJob, PersistViewJob (sync assíncrono Redis→DB)
├── Models/             # User, Posts, PostComment, PostLike, PostView, UserSession
├── Prompts/            # System prompts dos agentes de IA (saída em pt-BR)
└── Services/           # PetValidationService, CommentValidationService, ImageService, StorageService, LocationService
```

---

## 👤 Autor

Projeto de portfólio desenvolvido para demonstrar engenharia back-end com PHP/Laravel, padrões de integração com IA, otimizações de performance com Redis e design limpo de API.

