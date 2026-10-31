# 💬 Chat AI

Este é um aplicativo web completo e de alta performance desenvolvido em **PHP**, **Vanilla CSS** e **JavaScript**, integrado com o **Ollama** para rodar modelos de Inteligência Artificial localmente e banco de dados **MySQL/MariaDB** para persistência de histórico de conversas.

---

## 🚀 Funcionalidades Principais

*   **Streaming de Respostas**: Respostas em tempo real vindas do Ollama via Server-Sent Events (SSE).
*   **RAG Simplificado (Busca Semântica Local)**: O chat busca automaticamente o contexto relevante em mensagens anteriores da conversa para enriquecer o prompt atual.
*   **Seleção Dinâmica de Modelos**: Seletor de modelos com interface amigável que consulta quais modelos estão instalados e ativos localmente no Ollama.
*   **Histórico por Modelo**: Gravação no banco de dados (`coluna model` na tabela `messages`) do modelo específico utilizado em cada mensagem.
*   **Pronto para Containers**: Dockerizado com suporte a variáveis de ambiente para deploy no **Easypanel** ou qualquer orquestrador Docker.

---

## 🛠️ Tecnologias Utilizadas

*   **Frontend**: HTML5, JavaScript (ES6+), Vanilla CSS (com visual futurista/dark mode e Glassmorphism).
*   **Backend**: PHP (estruturado e rápido, sem dependências pesadas).
*   **Banco de Dados**: MySQL/MariaDB (com criação automática de tabelas e migrações automáticas no primeiro acesso).
*   **Serviço de IA**: [Ollama](https://ollama.com/) rodando localmente no host ou em container.

---

## 📦 Arquitetura de Containers (Docker)

O projeto está totalmente configurado para rodar em containers.

### Variáveis de Ambiente Suportadas

| Variável | Descrição | Valor Padrão |
| :--- | :--- | :--- |
| `DB_HOST` | Host do banco de dados MySQL/MariaDB | `localhost` |
| `DB_NAME` | Nome do banco de dados da aplicação | `chat_app` |
| `DB_USER` | Usuário de conexão do banco de dados | `ubuntu` |
| `DB_PASS` | Senha de conexão do banco de dados | `A@m26062015` |
| `OLLAMA_HOST`| URL do endpoint da API do Ollama | `http://localhost:11434` |

---

## 💻 Como Rodar o Projeto

### Método 1: Usando Docker Compose (Recomendado para Dev Local)

1. Certifique-se de ter o Docker instalado.
2. Navegue até a pasta do projeto e inicie o ambiente:
   ```bash
   docker compose up -d
   ```
3. Acesse no seu navegador:
   *   **Chat**: `http://localhost:8080/index.php`

### Método 2: Deploy no Easypanel

1. Crie um novo projeto no Easypanel (ex: `chat-system`).
2. Adicione um banco de dados **MariaDB** ou **MySQL** no projeto.
3. Adicione uma nova aplicação (**App**) apontada para o seu repositório de código.
4. Em **Build Pack**, selecione **Dockerfile**.
5. Na aba **Environment**, insira as variáveis de ambiente com as credenciais do banco de dados criado e defina a variável `OLLAMA_HOST` (ex: `http://ollama:11434` ou a URL do seu container específico do Ollama).
6. Faça o Deploy. O banco de dados e as tabelas serão estruturados automaticamente na primeira requisição!

---

## 📁 Estrutura de Arquivos Importantes

*   [`index.php`](file:///g:/Outros%20computadores/MARKED/www/htdocs/chat/index.php): Página inicial e interface do chat.
*   [`chat.php`](file:///g:/Outros%20computadores/MARKED/www/htdocs/chat/chat.php): Processa as mensagens do chat, RAG e streaming do Ollama.
*   [`api.php`](file:///g:/Outros%20computadores/MARKED/www/htdocs/chat/api.php): Endpoint para consultas ao banco de dados e controle de modelos do Ollama.
*   [`db.php`](file:///g:/Outros%20computadores/MARKED/www/htdocs/chat/db.php): Configuração de conexão e migração automática de tabelas.
*   [`Dockerfile`](file:///g:/Outros%20computadores/MARKED/www/htdocs/chat/Dockerfile): Arquivo de receita de imagem para o servidor Apache + PHP.
*   [`docker-compose.yml`](file:///g:/Outros%20computadores/MARKED/www/htdocs/chat/docker-compose.yml): Orquestração para desenvolvimento local simplificado.

---

## 🧠 Guia de Instalação de Modelos (Ollama)

Para utilizar os modelos desejados no chat, abra o seu terminal (PowerShell ou CMD) e execute os comandos de instalação correspondentes:

| Modelo | Comando de Instalação |
| :--- | :--- |
| **Llama 3 (8B)** | `ollama pull llama3` |
| **Llama 3.1 (8B)** | `ollama pull llama3.1` |
| **Llama 3.2 (1B)** | `ollama pull llama3.2:1b` |
| **Llama 3.2 (3B)** | `ollama pull llama3.2:3b` |
| **Phi-3 Mini (3.8B)** | `ollama pull phi3:mini` |
| **Phi-3.5 Mini (3.8B)** | `ollama pull phi3.5` |
| **Mistral v0.3 (7B)** | `ollama pull mistral:v0.3` |
| **DeepSeek-R1 (1.5B)** | `ollama pull deepseek-r1:1.5b` |
| **DeepSeek-R1 (7B)** | `ollama pull deepseek-r1:7b` |
| **DeepSeek-R1 (8B)** | `ollama pull deepseek-r1:8b` |
| **Gemma 2 (2B)** | `ollama pull gemma2:2b` |
| **Gemma 2 (9B)** | `ollama pull gemma2:9b` |
| **Qwen 2.5 (7B)** | `ollama pull qwen2.5:7b` |

> 💡 **Dica de Performance**: O chat envia a flag `"num_gpu" => 99` nas requisições do Ollama para garantir que a GPU seja priorizada no processamento das camadas dos modelos, aumentando expressivamente a velocidade de resposta.
