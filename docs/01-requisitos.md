# Requisitos iniciais

Versão 0.1 — 16/09/2026. Escopo confirmado na conversa; decisões técnicas propostas identificadas explicitamente.

## Contexto e limites

Um único psicólogo por instalação, com pacientes e usuários auxiliares. Sem portal do paciente, múltiplas clínicas, assinatura eletrônica, emissão fiscal ou chatbot nesta versão. Documentos inicialmente são arquivos enviados ao sistema. Não há criação automática de consultas por mensagens recebidas.

## Perfis e permissões

O psicólogo é o proprietário da instalação, cadastra/desativa usuários e cria perfis. Cada pessoa tem credencial própria. O perfil Secretária é fornecido inicialmente. Autorizações são verificadas no servidor em cada ação e objeto, além da apresentação dos menus.

| Recurso | Psicólogo | Secretária |
|---|---|---|
| Gerenciar usuários, perfis e integrações | Sim | Não |
| Consultar/editar cadastro administrativo | Sim | Sim, somente campos administrativos |
| Consultar agenda e agendar/reagendar/cancelar | Sim | Sim |
| Consultar valores | Todos | Somente consultas do dia local do consultório |
| Registrar chegada e falta | Sim | Sim |
| Chamar e encerrar atendimento | Sim | Não |
| Registrar pagamento | Sim | Somente consulta do dia |
| Estornar pagamento, ajustar valor ou dispensar cobrança | Sim | Não |
| Relatórios, receitas, pendências históricas e exportação financeira | Sim | Não |
| Prontuário e documentos clínicos | Sim | Não |
| Auditoria completa | Sim | Não |

Proposta conservadora: valores, baixas e documentos fora desses limites ficam exclusivos do proprietário até nova definição. Criar outro perfil não permite contornar a regra de que somente o psicólogo acessa gestão financeira e conteúdo clínico. Um usuário pode ter um perfil no MVP; perfis reúnem diversas permissões. Não desativar o único proprietário. Revogar sessões ao desativar usuário ou modificar privilégios.

## Requisitos funcionais

- RF01 — Pacientes: nome, data de nascimento opcional, telefone, e-mail opcional, contato de responsável opcional, estado ativo/inativo e preferências de comunicação. Não tornar CPF obrigatório nem usar telefone como identificador único.
- RF02 — Agenda: início, fim, paciente, modalidade/local e preço acordado. Consultas futuras podem ser alteradas por usuários autorizados. Bloqueios de agenda impedem novas marcações. Recorrência semanal é uma proposta: gerar ocorrências individuais, permitindo editar uma ocorrência sem afetar as demais; implementação posterior ao agendamento simples.
- RF03 — Atendimento: ao registrar chegada, mudar para Aguardando e exibir na fila do psicólogo; ao chamar, mudar para Em atendimento; ao encerrar, Concluído. Registrar Não compareceu quando houver falta. Pagamento não condiciona atendimento.
- RF04 — Painéis: psicólogo vê agenda do dia, fila de espera e atendimento atual; secretária vê agenda operacional, chegada, falta e cobrança das consultas do dia. Atualização automática proposta por consulta periódica a cada 15 segundos.
- RF05 — Financeiro: cobrança por consulta, pagamentos parciais ou integrais, método e data do recebimento, saldo pendente e relatórios por período exclusivos do psicólogo. Sem integração bancária no MVP. Uma falta ou cancelamento não gera multa nem quitação automática; decisão do psicólogo.
- RF06 — Prontuário: entradas por paciente, vinculadas opcionalmente à consulta; rascunho e finalização; correções por nova revisão, preservando a anterior. Conteúdo clínico inacessível à secretária inclusive em busca, exportação, respostas de API e logs.
- RF07 — Documentos: upload, classificação, download autorizado e arquivamento lógico; paciente obrigatório e consulta opcional. Proposta inicial: PDF, JPEG e PNG, limite configurável de 10 MB, validação de conteúdo/MIME e nome físico aleatório. Não executar ou servir diretamente uploads.
- RF08 — WhatsApp: um lembrete por versão de agendamento, 24 horas antes do início, usando o provedor ativo oficial ou não oficial. Incluir instrução para contato direto com o profissional em caso de cancelamento/reagendamento. Não processar respostas como ações de agenda.
- RF09 — E-mail: infraestrutura de lembretes separada do WhatsApp; habilitação depende da definição da antecedência e configuração do provedor.
- RF10 — Google Agenda: conectar conta do psicólogo e manter vínculo entre consulta e evento. Proposta MVP: exportar alterações do sistema para um calendário escolhido. Importação/sincronização bidirecional depende de definição de conflitos e confirmação de escopo.
- RF11 — Usuários: login, recuperação de acesso por token temporário armazenado como hash, perfis, desativação, auditoria e sessões individuais. Sem cadastro público.

## Estados e transições

| Origem | Ação | Destino | Responsável |
|---|---|---|---|
| Agendado | Registrar chegada | Aguardando | Secretária ou psicólogo |
| Agendado | Registrar falta após início previsto | Não compareceu | Secretária ou psicólogo |
| Agendado/Aguardando | Cancelar | Cancelado | Secretária ou psicólogo |
| Aguardando | Chamar | Em atendimento | Psicólogo |
| Em atendimento | Encerrar | Concluído | Psicólogo |
| Agendado/Aguardando | Reagendar | Agendado | Secretária ou psicólogo |

Reagendamento mantém o ID da consulta, incrementa a versão do agendamento, preserva histórico anterior e limpa a chegada pendente. Reagendar paciente que já chegou exige confirmação operacional na interface. Correções de falta e estados finais exigem o psicólogo, motivo e histórico; não reescrever atendimento finalizado silenciosamente. Proposta: apenas um atendimento Em atendimento por vez.

## Regra dos lembretes

O horário-alvo é `início UTC - 24 horas`, não a meia-noite da véspera. Exemplo: consulta em 20/09 às 15h → lembrete em 19/09 às 15h no mesmo fuso, quando não há mudança de offset.

Reagendar invalida lembretes pendentes da versão anterior e gera novo planejamento. Cancelar invalida pendentes. Uma mensagem já enviada não pode ser desfeita. Antes do disparo, conferir versão, estado, autorização do canal e horário novamente.

Premissa a confirmar: consulta criada/reagendada depois do horário-alvo não dispara lembrete imediato. Proposta operacional: cron a cada minuto, tolerância de atraso de até 5 minutos por falha transitória; após esse prazo marcar expirado e mostrar falha ao psicólogo. O horário solicitado é o de envio, sem promessa de entrega instantânea pelo provedor. Não fazer troca automática de provedor em resultado incerto, pois pode duplicar o lembrete.

Mensagem base: “Olá, {primeiro_nome}! Lembramos que sua consulta com {profissional} está agendada para {data}, às {hora}. Caso precise cancelar ou reagendar, entre em contato diretamente com o profissional pelo telefone {telefone}.” Usar data explícita para evitar ambiguidade. Não incluir prontuário, diagnóstico ou motivo da consulta.

## Critérios de aceite prioritários

1. Secretária tentando acessar URL/API financeira, prontuário ou documento clínico recebe acesso negado, sem vazamento de dados.
2. Consultas de ontem/amanhã não expõem preço ou permitem baixa à secretária, inclusive por ID direto. O “dia” é calculado no fuso do consultório.
3. Registrar chegada exibe Aguardando no painel em até 15 segundos; chamar muda para Em atendimento e identifica o responsável.
4. Duas tentativas simultâneas de agendar o mesmo intervalo não criam sobreposição; duas chamadas simultâneas não abrem atendimentos concorrentes.
5. Repetir uma requisição de pagamento com a mesma chave de idempotência não duplica o recebimento; pagamentos acima do saldo são rejeitados.
6. Reagendar/cancelar antes de o envio ser assumido pelo provedor impede o lembrete antigo; se já aceito, registrar essa condição sem alegar cancelamento do envio.
7. Falha/timeout do provedor não produz repetição cega; webhook duplicado não duplica alterações.
8. Finalização e revisão do prontuário preservam autor, data e versão; download exige sessão e autorização vigente.
9. Restauração em ambiente isolado recupera banco, documentos e capacidade de descriptografar dados protegidos.
10. Faltas, consultas concluídas e pagamentos permanecem estados independentes; relatórios distinguem data da consulta e data do recebimento.
