# Tornedon

Sistema de gestão empresarial para controle de estoque, produção e vendas.

## Sobre o Projeto

Tornedon é uma aplicação Laravel desenvolvida para gerenciar processos empresariais, incluindo:

- Controle de estoque e movimentações
- Ordens de produção
- Requisições e vendas
- Cotações e aprovações
- Multi-tenant

## Tecnologias

- **Laravel 10+**
- **Filament** (Admin Panel)
- **PHP 8.1+**
- **MySQL/PostgreSQL**
- **Tailwind CSS**

## Documentação

- [Arquitetura e Padrões](docs/Arquitetura%20e%20Padrões.md)
- [Guia de Desenvolvimento](docs/DEVELOPMENT-GUIDE.md)
- [Padrões de Código](docs/CODING-STANDARDS.md)
- [Requisitos](docs/REQUIREMENTS.md)
- [Contribuição](docs/CONTRIBUTING.md)
- [Padrões de Banco de Dados](docs/DATABASE-STANDARDS.md)
- [Regras de Negócio](docs/regras-negocio/)

## Instalação

1. Clone o repositório
2. Instale dependências: `composer install && npm install`
3. Configure `.env`
4. Execute migrations: `php artisan migrate`
5. Inicie o servidor: `php artisan serve`

Ver [Guia de Desenvolvimento](docs/DEVELOPMENT-GUIDE.md) para instruções detalhadas.

## Contribuição

Ver [Guia de Contribuição](docs/CONTRIBUTING.md) para contribuir com o projeto.

## Licença

Este projeto está sob a licença MIT.

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
