# Arquitetura técnica e implantação

## Decisão proposta

Monólito modular em PHP 8.3, Laravel 13 com páginas Blade, AdminLTE 4 e MySQL 8.4/InnoDB. Não implementar microserviços. Laravel e versões de AdminLTE/MySQL são propostas técnicas, enquanto PHP 8.3, MySQL, AdminLTE e WHM/cPanel são requisitos confirmados. Validar dependências com Composer e fixar versões em lockfiles ao iniciar o código.

Módulos: Identidade, Pacientes, Agenda/Atendimento, Prontuário, Financeiro, Documentos, Integrações e Auditoria. Controllers validam requisições; policies autorizam; serviços de aplicação controlam transações e regras; adaptadores isolam fornecedores externos. Interface renderizada no servidor, com JavaScript pontual e polling do painel, sem exigir WebSocket.

Laravel 13 exige PHP 8.3 como mínimo. O PHP 8.3 está em suporte de segurança até 31/12/2027; usar o patch de segurança disponível na hospedagem e prever atualização antes do fim do suporte. AdminLTE 4 usa Bootstrap 5.3. Fontes: [Laravel](https://laravel.com/framework/docs/releases), [PHP](https://www.php.net/supported-versions.php), [AdminLTE](https://docs.adminlte.io/html/introduction).

## Ambiente WHM/cPanel

- Verificar versão real do MySQL: não presumir que MariaDB seja equivalente. DDL alvo: MySQL 8.4. Se o provedor tiver outra versão, ajustar e validar antes da implantação.
- Confirmar PHP web e CLI 8.3, extensões exigidas pelo framework e aplicação: PDO/MySQL, mbstring, OpenSSL, cURL, fileinfo, XML/DOM, intl e sodium, além das demais exigências do Composer.
- Document root apontando exclusivamente para `public/`. Código, `.env`, logs, sessões e uploads privados fora da raiz pública. Se o plano não permitir isso, adequar o layout sem publicar o projeto inteiro.
- HTTPS, cookies Secure/HttpOnly/SameSite, sessões com expiração e proteção CSRF. Debug desligado em produção. Hash de senha pelo framework, rate limiting de login e recuperação; MFA proposto para o psicólogo.
- Composer e compilação dos assets podem executar em desenvolvimento/CI; enviar artefato pronto se não houver SSH/Node no servidor. Node não será requisito de execução em produção.
- Usuário de banco de aplicação com privilégios mínimos; migrations executadas com credencial própria de implantação, quando viável. Segredos fora do repositório.
- Verificar cron por minuto, limites de processo/memória/tempo, rede de saída HTTPS/SMTP, espaço, backups e capacidade de receber webhooks HTTPS.

Cron conceitual, a substituir pelos caminhos reais depois de conferidos:

```cron
* * * * * /CAMINHO/PHP83 /home/CONTA/app/artisan schedule:run >> /home/CONTA/logs/scheduler.log 2>&1
```

Não configurar esse comando antes da existência da aplicação. O scheduler iniciará processamento limitado da fila MySQL e encerrará antes do limite do host, com trava contra sobreposição. Laravel scheduler e jobs deverão ser testados no ambiente alvo. Cron requer configuração de frequência e comando no cPanel: [documentação](https://docs.cpanel.net/cpanel/advanced/cron-jobs/).

## Transações e concorrência

Uma transação grava a mudança de domínio, o histórico e o trabalho pendente (outbox). Não chamar APIs dentro da transação do banco. Workers assumem tarefas com lease e token; falhas liberam para nova tentativa conforme política. Índices cobrem estados/horários de execução. Recuperar leases expirados sem presumir que uma API não recebeu a chamada anterior.

Todas as mutações de agenda e bloqueios devem travar a mesma linha do consultório com `SELECT ... FOR UPDATE` antes de conferir conflitos: `existing_start < new_end AND existing_end > new_start`. Isso serializa agendamentos concorrentes para o único profissional. Canceladas e faltas não bloqueiam; consultas realizadas preservam ocupação histórica. Cobranças são travadas antes de verificar saldo e lançar pagamento. Usar versão de registro para rejeitar edição concorrente desatualizada.

## Adaptadores e entrega

- `WhatsAppGateway`: envio de template/mensagem, consulta de status quando disponível, validação de callbacks e classificação de erros. Uma implementação oficial e outra para o fornecedor não oficial escolhido. Não assumir equivalência de recursos; validar templates e requisitos do fornecedor antes de habilitar. Conexão persistente de sessão, se exigida, deve ficar em serviço externo.
- `MailGateway`: SMTP/API e registro de aceitação/falha; confirmar SPF/DKIM/DMARC e limites com o provedor na implantação.
- `CalendarGateway`: OAuth do psicólogo, tokens cifrados, renovação e revogação; evento identificado por ID estável e versão local. Exportação inicial proposta. Título discreto “Consulta”, sem convidar o paciente automaticamente, sem informações clínicas ou valores.

Callbacks exigem assinatura/autenticidade conforme o provedor e deduplicação pelo ID externo. Retenção mínima de payload; não registrar tokens ou dados clínicos em logs. Recebimento de mensagem do paciente não altera consulta. Status “aceito pelo provedor”, “entregue” e “falhou” são distintos. Exatamente uma entrega não é garantida em rede externa: usar chave idempotente quando suportada; timeout ambíguo exige reconciliação ou revisão, sem reenvio cego.

Na futura sincronização de entrada do Google, armazenar sync token, paginar e tratar token inválido com nova sincronização completa; não habilitar entrada sem regra de conflitos. Fonte: [Google Calendar — sincronização](https://developers.google.com/workspace/calendar/api/guides/sync).

## Proteção e continuidade

Cifrar conteúdo clínico e segredos de integração na aplicação, com identificação de chave, armazenamento separado e plano de rotação. Arquivos privados devem ter proteção equivalente e download autenticado; banco guarda chave de armazenamento, não URL pública. Backups cifrados abrangem banco e arquivos; material de recuperação das chaves mantido separadamente sob controle do proprietário.

Auditar acessos clínicos, alterações de agenda, pagamentos e administração. Auditoria não deve copiar textos clínicos nem segredos. Não excluir registros financeiros/clínicos em cascata. Retenção, descarte e requisitos profissionais/legais precisam de definição específica antes da produção; esta especificação não presume conformidade jurídica concluída.

Propostas de operação: backup diário, cópia externa, teste de restauração antes da produção e periodicamente; RPO de 24h e RTO de 8h sujeitos à capacidade contratada. Exibir ao psicólogo falhas de integração, último cron executado e fila atrasada. Rotacionar logs e monitorar espaço em disco.
