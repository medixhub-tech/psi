# Medpsico

Sistema para consultório individual de psicologia: PHP 8.3, Laravel 13, MySQL 8.4 e AdminLTE 4. Repositório: https://github.com/medixhub-tech/psi.

## Estado do desenvolvimento

Implementados: login/logout, limitação de tentativas, recuperação de senha, painel AdminLTE em português, cadastro/edição/desativação de usuários, criação/edição de perfis, permissões exclusivas do psicólogo e auditoria administrativa. Alterações de usuário/perfil e recuperação de senha invalidam sessões anteriores. Sem cadastro público, senha padrão ou dados reais de pacientes.

Também implementados: cadastro administrativo de pacientes, agenda diária, bloqueios de horário, reagendamento/cancelamento, histórico e fila com atualização a cada 15 segundos. A secretária registra chegada/falta; somente o profissional chama e conclui o atendimento. Alterações usam transações, bloqueio da agenda e controle de versão para impedir sobreposição e sobrescrita de dados desatualizados.

Implementados: tipos de atendimento e valores cadastrados pelo profissional; cobrança por consulta; recebimentos parciais ou integrais; estornos integrais; ajustes com motivo e resumo financeiro por período. A secretária acessa somente cobranças das consultas do dia local.

Prontuário e documentos implementados: registros por paciente com consulta opcional, rascunhos e finalização, revisões preservadas e correções com motivo. Arquivos PDF/JPEG/PNG de até 10 MB, cifrados e privados, com download autorizado e arquivamento reversível. Acesso exclusivo do psicólogo e auditado. Implementada a infraestrutura de lembretes e Google Agenda; a ativação real depende de credenciais e homologação com os provedores. As permissões desses módulos já estão catalogadas, mas não representam funcionalidades disponíveis nesta entrega. MFA ainda não implementado. Não está liberado para operação clínica em produção.

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

Após configurar o ambiente, executar `php artisan config:cache` e `php artisan view:cache`. O cron de integrações está descrito abaixo. Canais permanecem desativados até configuração e ativação explícita pelo profissional.

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

## Integrações e lembretes

Evolution API v2 é o provedor inicial escolhido. WhatsApp e e-mail são planejados 24 horas antes do início UTC da consulta. Consultas criadas após esse horário não disparam imediatamente. O cron tolera até cinco minutos de atraso, depois expira o lembrete. Trata-se do horário de solicitação do envio, sem garantia de entrega instantânea.

1. Configure EVOLUTION_URL (base HTTPS), EVOLUTION_TOKEN e EVOLUTION_INSTANCE no .env privado do servidor. Evolution roda em serviço externo ao cPanel. O adaptador usa POST /message/sendText/{instance}, campos number e text e cabeçalho apikey. Homologue a compatibilidade com a versão instalada.
2. Configure SMTP: MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS e TLS conforme o provedor. Mailers log/array nunca contam como lembretes enviados.
3. No paciente, registre a autorização de cada canal e como foi obtida. Para WhatsApp, use telefone internacional, por exemplo +5511999999999. Pacientes existentes começam sem autorização registrada.
4. No painel Integrações do profissional, ative os canais desejados. Presença de credenciais não confirma conectividade externa. Segredos não aparecem no painel.
5. Configure o cron por minuto no cPanel, após confirmar os caminhos reais:

```cron
* * * * * /CAMINHO/PHP83 /home/CONTA/app/artisan schedule:run >> /home/CONTA/logs/scheduler.log 2>&1
```

O comando manual `php artisan psi:integrations` pode enviar lembretes reais quando os canais estiverem ativos. Processa lotes limitados, com trava compartilhada; mantenha CACHE_STORE=database em produção. O painel mostra última execução e resultados. Nenhum cron de produção foi instalado durante o desenvolvimento.

As tabelas reminders e calendar_events funcionam como filas duráveis, atualizadas na mesma transação da agenda. Reagendamento cancela lembretes pendentes e gera novos planos. Antes de reservar um envio, o worker confere versão, situação, autorização e contato atual. Mudanças após a reserva não recolhem mensagens em trânsito. Interrupções após reserva ficam com resultado incerto, sem reenvio automático. Falhas e resultados incertos exigem revisão do profissional; não há troca automática de provedor. Aceito não significa entregue/lido. Webhooks de entrega ainda não foram implementados.

A mensagem orienta o paciente a contatar diretamente o profissional para cancelar ou reagendar. Respostas não alteram a agenda. O adaptador oficial Meta também está disponível: configure WHATSAPP_META_* e um template aprovado com cinco parâmetros de corpo, nesta ordem: primeiro nome, profissional, data, hora e telefone de contato. O template deve conter a instrução de contato direto. Informe uma versão Graph API suportada pela conta; não há versão presumida.

### Google Agenda

Configure GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET e GOOGLE_CALENDAR_ID no .env. Use o ID real de um calendário dedicado, não o apelido primary. Ative Calendar API no projeto Google e registre o retorno HTTPS exato APP_URL/integracoes/google/retorno. Conecte pelo painel do profissional. OAuth usa state de uso único com validade de dez minutos, PKCE e refresh token cifrado com APP_KEY.

Sincronização somente do sistema para o Google: consultas futuras viram eventos privados chamados Consulta, sem paciente, valor, local, convidados ou lembretes do Google. IDs estáveis permitem reconciliar tentativas repetidas. Falhas transitórias têm até cinco tentativas com intervalo crescente; erros podem ser reenfileirados pelo profissional. Não há importação de alterações externas.

Mudar o calendário preserva eventos do anterior. Desconectar interrompe novos trabalhos e remove o token ativo localmente, sem apagar eventos; revogue o acesso também no Google se necessário. Requisições já iniciadas podem concluir. Reconectar o mesmo calendário preserva IDs e reconcilia alterações feitas durante a desconexão.

Após editar .env, recrie o cache de configuração. Homologue com consultas fictícias e contas de destinatários controladas pelo profissional antes de ativar pacientes reais. Não houve envio externo nem conexão com conta Google real nesta etapa. Validação local: 81 testes / 541 verificações em MySQL, com workers concorrentes e HTTP/SMTP simulados. Aceitação e entrega reais dependem das contas configuradas.

Referências: [Evolution DTO](https://github.com/EvolutionAPI/evolution-api/blob/main/src/api/dto/sendMessage.dto.ts), [Google eventos](https://developers.google.com/workspace/calendar/api/v3/reference/events/insert), [Google OAuth](https://developers.google.com/identity/protocols/oauth2/web-server), [Meta API](https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api).
