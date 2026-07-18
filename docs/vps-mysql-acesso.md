# Acessar o MySQL na VPS — passo a passo

Banco `casca` (produção, `assinador.trsystem.com.br`). MySQL 8.0, rodando nativo via
systemd (**não** é o MySQL dos containers Docker do projeto `cameras` — são bancos
diferentes, no mesmo host).

## 1. Conectar na VPS

```bash
ssh root@assinador.trsystem.com.br
```

## 2. Descobrir as credenciais do banco

As credenciais estão no `.env` da aplicação. **Não exponha a senha em prints/logs
compartilhados** — é a senha de produção.

```bash
cd /var/www/assinador
grep '^DB_' .env
```

Isso mostra `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` (entre outros). Anote a senha
só para uso imediato no passo seguinte.

## 3. Conectar no MySQL

```bash
mysql -u root -p casca
```

Vai pedir a senha (a que você viu em `DB_PASSWORD`). Cole e dê enter (não aparece nada
na tela ao digitar — é normal).

Alternativa em um comando só (evita o prompt, mas fica no `.bash_history` — prefira o
`-p` interativo se possível):

```bash
mysql -u root -p'SUA_SENHA_AQUI' casca
```

## 4. Comandos básicos dentro do MySQL

Listar todas as tabelas:

```sql
SHOW TABLES;
```

Ver a estrutura de uma tabela:

```sql
DESCRIBE envelopes;
-- ou
SHOW CREATE TABLE envelopes;
```

Fazer um SELECT simples:

```sql
SELECT id, title, status, created_at FROM envelopes ORDER BY id DESC LIMIT 10;
```

Ver os signatários de um envelope específico:

```sql
SELECT id, name, email, whatsapp, channel, status FROM envelope_signers WHERE envelope_id = 11;
```

Ver a trilha de eventos de um envelope:

```sql
SELECT event, created_at FROM envelope_events WHERE envelope_id = 11 ORDER BY created_at;
```

Sair do MySQL:

```sql
EXIT;
```
(ou `Ctrl+D`)

## 5. Alternativa sem senha: via Laravel Tinker

Se preferir não lidar com a senha do MySQL diretamente, dá pra consultar pelos models do
Laravel (usa as credenciais do `.env` automaticamente). Rodar como `www-data` (senão dá
erro de permissão no diretório de config do tinker):

```bash
cd /var/www/assinador
sudo -u www-data HOME=/tmp /usr/bin/php8.3 artisan tinker
```

Dentro do tinker:

```php
App\Models\Envelope::latest()->take(5)->get(['id', 'title', 'status']);
App\Models\EnvelopeSigner::where('envelope_id', 11)->get(['name', 'email', 'channel', 'status']);
```

Sair: `exit` ou `Ctrl+D`.

## Cuidado

- Este servidor tem **outra aplicação** (`cameras`, em `/var/www/cameras` e containers
  Docker) — bases de dados diferentes. Sempre confirme que está no banco `casca` (o `mysql
  -u root -p casca` já entra direto nele; se conectar sem especificar o banco, rode `USE
  casca;` antes de qualquer coisa).
- Evite `UPDATE`/`DELETE` direto por SQL em produção — prefira uma ação pela interface ou
  peça para alterar via código (`artisan tinker` com os models respeita casts, eventos e
  regras de negócio que um SQL cru ignora).
