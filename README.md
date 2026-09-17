# Medixhub — sistema para consultório de psicologia

Repositório do projeto: https://github.com/medixhub-tech/psi

Fluxo autorizado: branches `codex/`, Pull Requests e merge após revisão das alterações e verificações disponíveis. Não versionar credenciais, documentos clínicos ou dados reais de pacientes.

Especificação inicial para um psicólogo individual, com usuários auxiliares e implantação em WHM/cPanel. Esta entrega documenta o projeto; ainda não contém a aplicação funcional.

- [Requisitos e critérios de aceite](docs/01-requisitos.md)
- [Arquitetura e implantação](docs/02-arquitetura.md)
- [Modelo de dados e regras de integridade](docs/03-modelo-de-dados.md)
- [Etapas de desenvolvimento e decisões pendentes](docs/04-plano.md)
- [DDL inicial MySQL](database/schema.sql)

PHP 8.3, MySQL e AdminLTE são requisitos confirmados. Laravel 13, AdminLTE 4 e MySQL 8.4 são escolhas técnicas propostas, sujeitas à conferência da hospedagem. Não importar o DDL em banco existente: é uma referência inicial para um banco vazio e será convertido em migrations quando a implementação começar.
