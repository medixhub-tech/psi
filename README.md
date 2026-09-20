# Medpsico

Sistema para consultório individual de psicologia: PHP 8.3, Laravel 13, MySQL 8.4 e AdminLTE 4. Repositório: https://github.com/medixhub-tech/psi.

## Estado do desenvolvimento

Implementados: login/logout, limitação de tentativas, recuperação de senha, painel AdminLTE em português, cadastro/edição/desativação de usuários, criação/edição de perfis, permissões exclusivas do psicólogo e auditoria administrativa. Alterações de usuário/perfil e recuperação de senha invalidam sessões anteriores. Sem cadastro público, senha padrão ou dados reais de pacientes.

Também implementados: cadastro administrativo de pacientes, agenda diária, bloqueios de horário, reagendamento/cancelamento, histórico e fila com atualização a cada 15 segundos. A secretária registra chegada/falta; somente o profissional chama e conclui o atendimento. Alterações usam transações, bloqueio da agenda e controle de versão para impedir sobreposição e sobrescrita de dados desatualizados.

Implementados: tipos de atendimento e valores cadastrados pelo profissional; cobrança por consulta; recebimentos parciais ou integrais; estornos integrais; ajustes com motivo e resumo financeiro por período. A secretária acessa somente cobranças das consultas do dia local.

Prontuário e documentos implementados: registros por paciente com consulta opcional, rascunhos e finalização, revisões preservadas e correções com motivo. Arquivos PDF/JPEG/PNG de até 10 MB, cifrados e privados, com download autorizado e arquivamento reversível. Acesso exclusivo do psicólogo e auditado. Integrações permanecem pendentes. As permissões desses módulos já estão catalogadas, mas não representam funcionalidades disponíveis nesta entrega. MFA ainda não implementado. Não está liberado para operação clínica em produção.

## Executar localmente

Requisitos: PHP 8.3 com extensões do Laravel e PDO MySQL, MySQL 8.4 e Composer 2. Os assets AdminLTE 4.0.0 e Bootstrap 5.3.8 estão versionados localmente com suas licenças; não é necessário Node para esta etapa.

```sh
composer install
cp .env.example .env
php artisan key:generate
```

Crie um banco MySQL vazio e um usuário para a aplicação. Configure `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` no `.env`. Se usar socket local, adicione `DB_SOCKET`. Em seguida:

```sh
php artisan migrate --seed
php artisan psi:create-owner
php artisan serve --host=127.0.0.1 --port=8000
```

O comando `psi:create-owner` solicita os dados do profissional e a senha oculta, com confirmação. Execute uma única vez. Acesse http://127.0.0.1:8000/entrar. No painel do psicólogo, crie o usuário da secretária; comunique a senha inicial por canal privado. O próprio usuário pode redefini-la pelo fluxo de recuperação quando o e-mail estiver configurado.

No Mac preparado durante o desenvolvimento, PHP está em `/opt/homebrew/opt/php@8.3/bin/php`. Se não estiver no PATH, use esse caminho ou configure seu terminal. A instalação Homebrew do MySQL não é iniciada automaticamente por este projeto.

Por padrão, `MAIL_MAILER=log` é somente desenvolvimento: e-mails não são enviados e links de recuperação aparecem no log local privado. Para entrega real, configure SMTP/API em `config/mail.php` via `.env`, valide remetente e envio antes do uso. Nunca exponha `storage/` publicamente.

## Testes

```sh
php artisan test --compact
```

A suíte rápida usa SQLite em memória para isolamento, sem mudar o banco de produção. Para verificar MySQL, crie um banco **descartável e exclusivo** e execute:

```sh
DB_CONNECTION=mysql DB_DATABASE=medpsico_testing DB_USERNAME=USUARIO_TESTE DB_PASSWORD=SENHA_TESTE php artisan test --compact
```

Os testes recriam as tabelas do banco indicado. Nunca use o banco de produção. A integração contínua usa MySQL 8.4 isolado.

## cPanel

A raiz do domínio deve apontar para `public/`. Configurar PHP web/CLI 8.3, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` HTTPS e `SESSION_SECURE_COOKIE=true`. Banco, `.env`, uploads e logs ficam fora da pasta pública. Gerar uma chave própria na primeira instalação e preservá-la nas atualizações. Usar `composer install --no-dev --optimize-autoloader`, migrations revisadas e backup antes de atualizar. `storage/` e `bootstrap/cache/` precisam de escrita pelo usuário da aplicação; não usar permissões 777.

Após configurar o ambiente, executar `php artisan config:cache` e `php artisan view:cache`. O cron e as integrações serão adicionados na etapa correspondente; esta entrega não envia lembretes.

## Modelagem e documentação

- [Requisitos e critérios de aceite](docs/01-requisitos.md)
- [Arquitetura e hospedagem](docs/02-arquitetura.md)
- [Modelo relacional completo](docs/03-modelo-de-dados.md)
- [Plano de desenvolvimento](docs/04-plano.md)
- [DDL de referência do escopo completo](database/schema.sql)

Para instalar a aplicação, use **migrations**, não importe `schema.sql`. O DDL é referência do escopo completo; a aplicação implementa as tabelas da fundação, pacientes e agenda. Nas migrations, `users.password` segue a convenção Laravel em vez de `password_hash`; perfis recebem timestamps, permissões recebem nome e sessões/recuperação/cache/jobs seguem o framework. Na agenda, datas são armazenadas em UTC e apresentadas no fuso do consultório (padrão America/Sao_Paulo). Agendamento simples, sem recorrência; intervalo máximo de 12 horas. Reagendamento de paciente aguardando exige confirmação e o remove da fila. O cadastro inativo impede novos agendamentos e preserva os anteriores. Antes de agendar, o profissional cadastra os tipos de atendimento e valores. Mudanças de preço não alteram consultas existentes. Consultas anteriores ficam sem valor até ajuste pelo profissional. Reagendamento preserva recebimentos; falta e cancelamento não dispensam a cobrança automaticamente.

A linha `practice.id=1` é criada somente pelo comando de bootstrap.

Fluxo autorizado: branches `codex/` e merge direto na `main` após verificações, sem Pull Requests. `.env`, credenciais, logs e dados clínicos nunca devem ser commitados. Alterar um repositório público para privado no futuro não recolhe cópias já feitas.

Recebimentos e estornos são registros internos, sem transferência bancária automática. Totais usam a data do lançamento; saldos usam o vencimento da consulta e todos os pagamentos atuais. Despesas, conciliação bancária e emissão fiscal não fazem parte desta etapa.

## Prontuário e documentos

Abra Pacientes e selecione Prontuário ou Documentos. Cada salvamento clínico gera uma versão; nenhuma revisão anterior é sobrescrita. Correções após uma finalização exigem motivo. Não há salvamento automático. Por sigilo, textos clínicos não são guardados na sessão após erros de validação.

Conteúdo, motivos de correção, nomes e bytes dos arquivos usam a cifragem autenticada do Laravel com APP_KEY. Arquivos ficam em storage/app/private/clinical, sem rota pública ou URL temporária. Downloads são anexos e conferem integridade; arquivamento bloqueia o download até restauração. A auditoria guarda IDs e ações, sem conteúdo clínico.

Backup deve incluir banco, arquivos privados e APP_KEY preservada separadamente e com acesso restrito. Perder a chave impede recuperar o conteúdo. Não execute key:generate em atualizações. Rotação requer manter chaves anteriores em APP_PREVIOUS_KEYS e validar restauração antes de remover qualquer chave. No cPanel, configurar upload_max_filesize de pelo menos 10M e post_max_size superior (ex.: 12M), além dos limites do servidor web. Não há assinatura digital, editor de documentos ou antivírus nesta etapa.

Validação desta etapa: 59 testes / 455 verificações em MySQL. Dois testes de concorrência financeira são exclusivos do MySQL. A liberação em produção depende da homologação e do teste de restauração.
