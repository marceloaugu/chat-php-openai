# 💬 chat-php-pantheon

Interface web de chat em **PHP**, **JavaScript (ES6+)** e **Vanilla CSS** projetada para experimentação e avaliação de modelos de linguagem (LLMs) por meio de APIs compatíveis com o padrão **OpenAI** (`/v1/chat/completions` e `/v1/models`), utilizando o **Pantheon** como mecanismo de inferência local de referência.

Este projeto integra uma linha de experimentação em PHP voltada à execução de LLMs em servidores locais equipados com GPUs **NVIDIA Tesla K80** (arquitetura **Kepler**, Compute Capability **SM 3.7**), mantendo compatibilidade configurável com qualquer servidor ou provedor que implemente a especificação de API compatível com OpenAI (inclusive o **Ollama** nessa configuração `/v1`).

---

## 1. Apresentação

O **`chat-php-pantheon`** disponibiliza uma interface leve, responsiva e sem dependências de frameworks pesados para interagir com modelos de linguagem locais ou remotos. O foco do projeto é oferecer:

* Streaming de tokens em tempo real via **Server-Sent Events (SSE)**.
* Suporte nativo à visualização de cadeias de raciocínio (*Chain-of-Thought* / *Reasoning*), recolhíveis na interface.
* Telemetria de desempenho em tempo real e consolidada por mensagem (velocidade em `tok/s`, tempo total em segundos e contagem de tokens de prompt e resposta).
* Persistência de histórico de conversas, modelos utilizados e métricas de execução em banco de dados **MySQL / MariaDB**, com criação e migração automática de esquema.
* Recuperação de contexto local simplificada (**RAG por palavras-chave**) sobre o histórico da própria conversa.

### Resultados Observados no Ambiente de Testes (Referência)

No laboratório local de experimentação, este projeto foi validado em conjunto com o mecanismo de inferência **Pantheon** sob a seguinte configuração:

| Componente / Parâmetro | Especificação do Ambiente de Testes |
| :--- | :--- |
| **Mecanismo de Inferência** | Pantheon (API local compatível com OpenAI na porta `8081`) |
| **Hardware GPU** | 2x NVIDIA Tesla K80 (Arquitetura Kepler, Compute Capability **SM 3.7**) |
| **Modelo Testado** | `Qwen3.6-35B-A3B` (arquitetura *Mixture-of-Experts* / MoE) |
| **Modo de Inferência** | Raciocínio (*Reasoning / Chain-of-Thought*) ativado |
| **Desempenho Observado** | Aproximadamente **20 tokens por segundo (`~20 tok/s`)** |

> **Nota técnica:** Os números acima registram o resultado empírico obtido no ambiente de referência com o modelo e hardware citados, não devendo ser interpretados como garantia de desempenho em configurações de hardware, quantizações ou tamanhos de contexto distintos.

---

## 2. Objetivos

1. **Experimentação em Hardware Legado (Kepler / SM 3.7):** Viabilizar uma camada de apresentação rápida em PHP para testar inferência acelerada por GPU em placas NVIDIA Tesla K80 servidas pelo Pantheon.
2. **Padronização sobre o Protocolo OpenAI (`/v1`):** Consumir os endpoints `/v1/chat/completions` e `/v1/models` de modo que o mesmo código PHP possa conversar com o Pantheon local, com a API remota da OpenAI ou com outros motores compatíveis apenas alterando variáveis de ambiente.
3. **Observabilidade de Inferência:** Medir na própria interface de chat a taxa de geração (`tok/s`), a latência e o volume de tokens gerados a cada turno, armazenando essas métricas no banco de dados para comparação entre modelos.
4. **Inspeção de Raciocínio (*Thinking*):** Separar visualmente o fluxo de pensamento interno do modelo (`reasoning_content`, `reasoning`, `thought` ou blocos `<think>...</think>`) da resposta final apresentada ao usuário.

---

## 3. Arquitetura

A aplicação segue uma arquitetura direta em três camadas (Frontend SPA leve + Backend PHP estruturado + Banco de Dados MySQL/MariaDB), sem camada intermediária de ORM ou abstração orientada a objetos complexa:

```
[Navegador: index.php + script.js + style.css]
       │
       ├── GET/POST api.php?action=...  ──► [MySQL / MariaDB (conversations, messages)]
       │                                ──► GET {OPENAI_BASE_URL}/models (ou /health)
       │
       └── POST chat.php (SSE Stream)   ──► [MySQL / MariaDB (RAG + persistência)]
                                        ──► POST {OPENAI_BASE_URL}/chat/completions
```

### Separação entre Interface PHP e Mecanismo de Inferência

Não há uma camada formal de adaptadores para múltiplos protocolos distintos; em vez disso, o desacoplamento ocorre pela adoção estrita do **contrato HTTP/JSON compatível com OpenAI**:

* **O que é independente do provedor:**
  * Persistência de conversas e mensagens (`db.php`, `schema.sql`).
  * Mecanismo de RAG simplificado por palavras-chave no histórico da conversa (`chat.php`).
  * Interface gráfica, renderização de Markdown, realce de sintaxe e exibição de blocos de pensamento e métricas (`index.php`, `script.js`, `style.css`).
* **O que é específico da interface de API implementada:**
  * `api.php` consulta `GET {OPENAI_BASE_URL}/models` (com fallback para `{ROOT_URL}/health` e `{ROOT_URL}/models` sem o sufixo `/v1`).
  * `chat.php` envia `POST {OPENAI_BASE_URL}/chat/completions` com `"stream": true` e `"stream_options": {"include_usage": true}`, repassando os chunks SSE ao navegador e interpretando objetos `choices[0].delta` e `usage`.

### Estrutura de Diretórios e Arquivos

```text
chat-php-pantheon/
├── .env.example        # Modelo de variáveis de ambiente (sem credenciais reais)
├── .gitignore          # Regras de proteção de credenciais, dumps e arquivos locais
├── Dockerfile          # Imagem Docker (PHP 8.4 + Apache + pdo_mysql + curl)
├── README.md           # Documentação técnica do projeto
├── api.php             # Endpoints JSON para histórico, status do servidor e listagem de modelos
├── chat.php            # Endpoint de streaming SSE, RAG simplificado e chamada a /chat/completions
├── db.php              # Leitor nativo de .env, conexão PDO e auto-migração do banco de dados
├── docker-compose.yml  # Orquestração de containers (chat-app + MariaDB 10.11)
├── index.php           # Interface principal do chat
├── schema.sql          # Esquema SQL de referência (tabelas conversations e messages)
├── script.js           # Lógica de frontend, parser de streaming SSE, raciocínio e métricas
└── style.css           # Estilização dark mode / Glassmorphism
```

---

## 4. Tecnologias

* **Backend:** PHP 8.4 (compatível com PHP 8.x, utilizando extensões nativas `pdo_mysql`, `curl`, `json`, `mbstring`; sem dependências do Composer).
* **Frontend:** HTML5, Vanilla CSS (Dark Mode / Glassmorphism) e JavaScript ES6+ (`fetch`, `ReadableStream`, `AbortController`).
* **Bibliotecas Client-Side (via CDN):**
  * [Marked.js](https://marked.js.org/) — Renderização de Markdown em tempo real.
  * [Highlight.js](https://highlightjs.org/) (`11.9.0`, tema `github-dark`) — Realce de sintaxe em blocos de código com botão de cópia.
  * [FontAwesome](https://fontawesome.com/) (`6.4.0`) e fonte **Inter** (Google Fonts).
* **Banco de Dados:** MySQL 8.0+ ou MariaDB 10.11+.
* **Mecanismo de Inferência de Referência:** **Pantheon** (ou qualquer servidor compatível com a API `/v1` da OpenAI, incluindo o Ollama).

---

## 5. Requisitos

* **Ambiente Local (XAMPP / LAMP / WAMP):**
  * PHP `>= 8.0` (recomendado PHP `8.4`, utilizado na configuração de container) com extensões `pdo_mysql`, `curl` e `mbstring` habilitadas.
  * Servidor web Apache (com `mod_rewrite` opcional) ou Nginx.
  * Servidor MySQL ou MariaDB acessível.
* **Mecanismo de Inferência:**
  * Instância local do **Pantheon** (por padrão em `http://127.0.0.1:8081/v1`) **ou** outro serviço compatível com OpenAI acessível via rede.
* **Ambiente Containerizado (Opcional):**
  * Docker e Docker Compose.

---

## 6. Instalação

1. Clone o repositório no diretório do seu servidor web (ex.: `htdocs`):
   ```bash
   git clone https://github.com/marceloaugu/chat-php-pantheon.git
   cd chat-php-pantheon
   ```

2. Crie o arquivo `.env` a partir do arquivo de exemplo `.env.example`:
   ```bash
   cp .env.example .env
   ```

3. Ajuste as variáveis no arquivo `.env` conforme o seu ambiente de banco de dados e endpoint de inferência.

4. **Banco de Dados (Automático ou Manual):**
   * **Automático:** Caso o usuário configurado em `DB_USER` possua permissão de `CREATE DATABASE`, o arquivo `db.php` criará automaticamente o banco `chat_app`, as tabelas `conversations` e `messages` e as colunas adicionais (`model` e `metrics`) na primeira requisição.
   * **Manual:** Caso prefira criar previamente o banco, importe o arquivo `schema.sql`:
     ```bash
     mysql -u root -p < schema.sql
     ```

---

## 7. Configuração

A configuração é realizada por variáveis de ambiente lidas diretamente pelo parser nativo em `db.php` (a partir do arquivo `.env` na raiz do projeto ou das variáveis de ambiente do sistema/container):

| Variável | Descrição | Valor Padrão (`db.php`) | Valor Recomendado (Pantheon Local) |
| :--- | :--- | :--- | :--- |
| `OPENAI_BASE_URL` | URL-base da API compatível com OpenAI (deve incluir `/v1` quando aplicável) | `https://api.openai.com/v1` | `http://127.0.0.1:8081/v1` |
| `OPENAI_API_KEY` | Chave de autenticação (`Bearer Token`). Pode ficar vazia em servidores locais sem autenticação | *(vazio)* | *(vazio)* ou token local |
| `DB_HOST` | Host do banco de dados MySQL/MariaDB | `localhost` | `localhost` |
| `DB_NAME` | Nome do banco de dados da aplicação | `chat_app` | `chat_app` |
| `DB_USER` | Usuário do banco de dados | `ubuntu` | Seu usuário MySQL/MariaDB |
| `DB_PASS` | Senha do banco de dados | *(vazio)* | Definida exclusivamente no `.env` |

### Exemplos de Configuração por Provedor (`.env`)

* **Pantheon Local (Ambiente de Referência na porta 8081):**
  ```env
  OPENAI_BASE_URL=http://127.0.0.1:8081/v1
  OPENAI_API_KEY=
  ```

* **API Remota da OpenAI:**
  ```env
  OPENAI_BASE_URL=https://api.openai.com/v1
  OPENAI_API_KEY=sk-proj-sua_chave_aqui
  ```

* **Ollama via Endpoint Compatível com OpenAI (`/v1`):**
  ```env
  OPENAI_BASE_URL=http://127.0.0.1:11434/v1
  OPENAI_API_KEY=ollama
  ```

### Parâmetros de Inferência e Contexto no Código (`chat.php`)

* **Janela de Histórico Recente:** Envia as últimas **6 mensagens** da conversa ativa (`ORDER BY created_at DESC LIMIT 6`), removendo blocos `<think>...</think>` de turnos anteriores para economizar tokens de contexto.
* **RAG Simplificado Local:** Extrai palavras com mais de 3 caracteres do prompt atual e busca até **3 mensagens anteriores** na mesma conversa (`LIKE %palavra%`), injetando-as na mensagem de sistema (`role: system` ou `role: developer` para modelos `o1`/`o3`).
* **Controle de Raciocínio (`think`):**
  * Quando ativado no botão **Raciocínio ON**, instrui o sistema como assistente analítico e, para modelos da série `o1`/`o3`, define `"reasoning_effort": "medium"`.
  * Quando desativado (**Raciocínio OFF**), instrui o modelo a responder de forma concisa e sem raciocínio prévio (e define `"reasoning_effort": "low"` na série `o1`/`o3`).
* **Temperatura e Top-P:** Não são fixados no payload de `chat.php`, delegando ao servidor de inferência (Pantheon / OpenAI) os valores padrão otimizados para cada modelo (o que também evita erros em modelos de raciocínio como `o1`/`o3` que rejeitam `temperature` customizada).

---

## 8. Execução

### Opção A: Servidor Web Local (Apache / XAMPP)

1. Certifique-se de que o Apache, o MySQL/MariaDB e o servidor **Pantheon** (na porta `8081`) estejam em execução.
2. Acesse no navegador:
   ```text
   http://localhost/chat-php-pantheon/
   ```
3. Verifique no canto inferior esquerdo da barra lateral se o indicador apresenta **Online • Servidor (:8081)**.
4. Selecione o modelo desejado no menu superior (os modelos retornados por `/v1/models` aparecem sob o grupo **Modelos Ativos no Servidor (:8081)**) e envie sua mensagem.

### Opção B: Docker Compose

1. Confira as variáveis de ambiente no arquivo `docker-compose.yml` (por padrão aponta para `http://host.docker.internal:8081/v1`).
2. Suba os containers:
   ```bash
   docker compose up -d --build
   ```
3. Acesse `http://localhost:8080/index.php`.

---

## 9. Integração com APIs e Compatibilidade

Este projeto opera sobre a **especificação de API compatível com OpenAI (`/v1`)** no backend (`api.php` e `chat.php`), funcionando de forma transparente com qualquer servidor de inferência que implemente esse contrato — inclusive o **Ollama** quando configurado através do seu endpoint compatível (`/v1`).

| Característica | Especificação Implementada (`chat-php-pantheon`) |
| :--- | :--- |
| **Endpoint de Chat** | `POST {OPENAI_BASE_URL}/chat/completions` |
| **Listagem de Modelos** | `GET {OPENAI_BASE_URL}/models` (com fallback para `/health` e `/models`) |
| **Formato do Stream** | SSE (`data: {"choices":[{"delta":{...}}]}` + `data: [DONE]`) |
| **Extração de Raciocínio** | `delta.reasoning_content`, `delta.reasoning`, `delta.thought` ou `<think>` |
| **Métricas de Uso** | Objeto `usage` (`prompt_tokens`, `completion_tokens`, `total_tokens`) |

### Provedores e Motores Compatíveis

| Provedor / Motor | Endpoint Base (`OPENAI_BASE_URL`) | Situação | Observações Técnicas |
| :--- | :--- | :--- | :--- |
| **Pantheon** | `http://127.0.0.1:8081/v1` | **Implementado e testado** | Ambiente de referência (testado em 2x Tesla K80 / SM 3.7 com `Qwen3.6-35B-A3B` a ~20 tok/s). Inclui fallback de status em `/health` e `/models`. |
| **OpenAI Remota** | `https://api.openai.com/v1` | **Compatível mediante configuração** | Basta configurar `OPENAI_BASE_URL` e `OPENAI_API_KEY`. Suporta `Bearer` token, `stream_options.include_usage`, papel `developer` e `reasoning_effort`. |
| **Ollama (API `/v1`)** | `http://127.0.0.1:11434/v1` | **Compatível mediante configuração** | Funciona diretamente utilizando a camada de compatibilidade OpenAI do Ollama (`/v1/chat/completions` e `/v1/models`). |
| **vLLM / llama.cpp (`llama-server`) / LM Studio** | `http://<host>:<porta>/v1` | **Compatível mediante configuração** | Basta apontar `OPENAI_BASE_URL` para a URL `/v1` do servidor correspondente. |

---

## 10. Exemplos de Uso (Requisições e Respostas Reais)

### 1. Verificação de Status e Modelos (`GET /v1/models`)

Quando a página é carregada, `api.php?action=list_models` consulta `GET http://127.0.0.1:8081/v1/models`:

```http
GET /v1/models HTTP/1.1
Host: 127.0.0.1:8081
Content-Type: application/json
```

Resposta esperada do Pantheon / servidor compatível:

```json
{
  "object": "list",
  "data": [
    {
      "id": "Qwen3.6-35B-A3B",
      "object": "model",
      "created": 1730000000,
      "owned_by": "pantheon"
    }
  ]
}
```

### 2. Requisição de Chat com Streaming (`POST /v1/chat/completions`)

Gerada por `chat.php` ao enviar uma mensagem:

```json
{
  "model": "Qwen3.6-35B-A3B",
  "messages": [
    {
      "role": "system",
      "content": "Você é um assistente prestativo e inteligente."
    },
    {
      "role": "user",
      "content": "Explique a arquitetura Kepler SM 3.7 da Tesla K80."
    }
  ],
  "stream": true,
  "stream_options": {
    "include_usage": true
  }
}
```

### 3. Fluxo de Resposta SSE Processado por `chat.php` e `script.js`

```text
data: {"id":"chatcmpl-1","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"reasoning_content":"Analisando as especificações da GPU NVIDIA Tesla K80 (GK210)..."},"finish_reason":null}]}

data: {"id":"chatcmpl-1","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"content":"A **NVIDIA Tesla K80** utiliza dois chips GK210 baseados na arquitetura **Kepler (SM 3.7)**..."},"finish_reason":null}]}

data: {"id":"chatcmpl-1","object":"chat.completion.chunk","choices":[],"usage":{"prompt_tokens":34,"completion_tokens":180,"total_tokens":214}}

data: [DONE]
```

Ao final do stream, `chat.php` persiste no banco de dados o raciocínio encapsulado em `<think>...</think>` junto ao conteúdo final e grava na coluna `metrics`:

```json
{
  "eval_count": 180,
  "prompt_eval_count": 34,
  "total_tokens": 214,
  "total_sec": 9.12,
  "tok_per_sec": 19.7
}
```

---

## 11. Solução de Problemas (Troubleshooting)

* **Indicador exibe `Offline • Porta 8081`:**
  * Verifique se o servidor Pantheon (ou outro motor configurado) está em execução e escutando no endereço definido em `OPENAI_BASE_URL`.
  * Teste manualmente no terminal: `curl http://127.0.0.1:8081/v1/models` ou `curl http://127.0.0.1:8081/health`.
  * Se a aplicação PHP estiver rodando dentro de um container Docker e o Pantheon estiver no host, utilize `http://host.docker.internal:8081/v1` em vez de `127.0.0.1`.
* **Erro `Erro ao conectar ou configurar o banco de dados`:**
  * Confirme se o serviço MySQL/MariaDB está ativo e se as credenciais `DB_HOST`, `DB_USER` e `DB_PASS` no arquivo `.env` estão corretas.
* **Streaming não aparece token a token (resposta chega toda de uma vez):**
  * Verifique se há proxy reverso (Nginx/Cloudflare) ou buffer de saída do PHP/Apache retendo pacotes. O arquivo `chat.php` já define `zlib.output_compression=Off`, `output_buffering=Off`, `X-Accel-Buffering: no` e envia 2 KB de padding inicial SSE.
* **Botão `Raciocínio ON/OFF` não aparece para um modelo customizado:**
  * O seletor exibe o botão automaticamente para modelos cujo identificador contenha `o1`, `o3`, `r1`, `deepseek-r1`, `qwq`, `marco-o1`, `reasoner` ou `reasoning`. Para modelos com outros nomes que emitam `<think>...</think>` ou `reasoning_content`, a caixa de pensamento continuará sendo renderizada normalmente sempre que o modelo enviar tokens de raciocínio.

---

## 12. Limitações Conhecidas

1. **Indicador visual de porta no Frontend:** Os textos informativos da interface (`script.js`) exibem a referência visual `:8081` (porta padrão do ambiente Pantheon local), mesmo quando `OPENAI_BASE_URL` aponta para outra porta ou servidor remoto.
2. **Gerenciamento de VRAM via UI:** As ações `list_running_models` e `run_model` em `api.php` são stubs que retornam sucesso imediato (`{"models": []}` e `{"success": true}`), pois o protocolo padrão OpenAI `/v1` não define endpoints para carregar ou descarregar modelos explicitamente na VRAM.
3. **RAG Simplificado:** A busca contextual utiliza correspondência lexical (`SQL LIKE`) sobre mensagens anteriores da mesma conversa, não utilizando banco de dados vetorial ou embeddings.
