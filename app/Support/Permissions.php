<?php

namespace App\Support;

class Permissions
{
    public const DELEGABLE = [
        'patients.manage' => 'Cadastro administrativo de pacientes',
        'appointments.manage' => 'Agendamento, reagendamento e cancelamento',
        'attendance.record' => 'Registrar chegada e falta',
        'billing.today' => 'Consultar valores e registrar pagamentos do dia',
    ];

    public const OWNER = [
        'users.manage' => 'Administrar usuários e perfis',
        'attendance.treat' => 'Chamar e concluir atendimento',
        'finance.manage' => 'Gestão financeira e relatórios',
        'clinical.manage' => 'Prontuário clínico',
        'documents.manage' => 'Documentos clínicos',
        'integrations.manage' => 'Integrações',
        'audit.view' => 'Auditoria',
    ];
}
