# Plano de implementação

## Etapas com resultado verificável

1. **Fundação:** validar hospedagem; criar aplicação PHP/Laravel e layout AdminLTE; migrations, login, perfis, policies e bootstrap do proprietário. Entrega: secretária não acessa recursos exclusivos mesmo por URL/API direta.
2. **Pacientes e agenda:** cadastro administrativo, consultas, bloqueios, histórico, chegada, fila, chamada e encerramento. Entrega: fluxo Agendado → Aguardando → Em atendimento → Concluído com proteção contra concorrência.
3. **Cobrança e financeiro:** cobrança da consulta, recebimentos idempotentes, estornos exclusivos e relatórios do profissional. Entrega: recebimento operacional pela secretária limitado ao dia e reconciliação de saldo correta.
4. **Prontuário e documentos:** conteúdo cifrado, revisões, upload privado, download autorizado e auditoria. Entrega: nenhum caminho de leitura clínica acessível ao perfil auxiliar.
5. **Integrações:** outbox/cron, WhatsApp no provedor escolhido, e-mail e Google Agenda. Entrega: testes em sandbox com reagendamento, cancelamento, timeout, duplicidade e callback fora de ordem. Suporte a dois adaptadores não significa manter dois fornecedores enviando ao mesmo tempo.
6. **Homologação e implantação:** testes de permissões/concorrência, restauração, configuração cPanel, TLS, secrets e monitoramento. Entrega: instalação operacional com procedimento de atualização e recuperação documentado.

## Decisões pendentes, sem bloquear documentação/modelagem

| Decisão | Proposta inicial | Momento de resolver |
|---|---|---|
| Versão/recursos do servidor | MySQL 8.4, PHP 8.3 CLI/web, cron por minuto | Antes de instalar dependências |
| Framework e versão visual | Laravel 13 + AdminLTE 4 | Início da implementação |
| Preço ao agendar pela secretária | Valor padrão definido pelo psicólogo, sem edição pela secretária | Agenda/financeiro |
| Google Agenda | Sistema → Google, calendário dedicado, sem convidados automáticos | Integrações |
| Antecedência e-mail | A definir; não ativar envio sem regra | Integrações |
| Consulta marcada com menos de 24h | Não enviar WhatsApp imediato | Integrações |
| Tolerância de atraso e tentativas | Até 5 min após horário-alvo; depois expirar | Integrações |
| Fornecedores de WhatsApp/e-mail | Adaptadores após escolha e validação das contas | Integrações |
| Recorrência, duração e intervalo de consulta | Agendamento simples primeiro; parâmetros do psicólogo | Agenda |
| Retenção, formatos clínicos e descarte | Definição específica com profissional antes de produção | Homologação |
| Backups e recuperação | RPO 24h / RTO 8h propostos | Contratação/homologação |

## Verificação necessária

- Importar DDL em banco MySQL 8.4 descartável e verificar FKs, checks e índices; depois converter em migrations e validar rollback em ambiente de desenvolvimento.
- Testes de autorização por ação/objeto/campo e janela local do dia, incluindo perfis personalizados.
- Testes transacionais concorrentes para agenda, chamada, pagamento e criação de revisões.
- Testes de horário UTC/fuso, fronteira de meia-noite, consulta criada após prazo e falha do cron.
- Testes de provedor aceito/timeout/falha e callbacks duplicados, inválidos e fora de ordem.
- Testes de upload inválido, acesso direto a arquivos, recuperação de senha e revogação de sessão.
- Restauração completa em ambiente isolado antes de liberar produção.

## Estado desta entrega

Requisitos, arquitetura, modelo relacional e DDL de referência criados. Nenhuma instalação, implantação ou integração externa foi executada. Este ambiente não disponibiliza executáveis MySQL, Docker ou PHP; validação de execução e compatibilidade do servidor permanece pendente. A conferência local cobre estrutura documental e referências do DDL, sem afirmar validação sintática/operacional por MySQL.


## Atualização — fundação implementada

Login/logout, recuperação de senha, usuários, perfis, revogação de sessões, auditoria administrativa e interface AdminLTE implementados na branch `codex/fundacao-autenticacao`. Testes: 19 cenários e 103 verificações com PHP 8.3.33, SQLite e MySQL 8.4.11. Login e painel conferidos no navegador com conta fictícia em banco isolado. O DDL de referência de 23 tabelas também foi importado com sucesso em outro banco descartável. Isso atualiza as limitações de ambiente descritas na entrega documental inicial.

A instalação agora utiliza migrations; `database/schema.sql` permanece referência do escopo completo. E-mail real, agenda, pacientes, financeiro, prontuário, documentos e integrações não estão entregues nesta etapa. Não houve implantação em servidor cPanel.
