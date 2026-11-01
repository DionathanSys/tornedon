# Axion Gestão

Sistema de gestão empresarial para controle de estoque, produção e vendas.

## Sobre o Projeto

Axion Gestão é uma aplicação Laravel desenvolvida para gerenciar processos empresariais, incluindo:

- Controle de estoque e movimentações
- Ordens de produção
- Requisições e vendas
- Cotações e aprovações
- Multi-tenant

## Nome da aplicação

Configure `APP_NAME="Axion Gestão"` no `.env` de cada ambiente. Os painéis e e-mails usam essa configuração; os nomes dos aplicativos instaláveis ficam em `public/manifest*.webmanifest`.

Ao atualizar uma instalação existente, preserve os valores atuais de `session.cookie`, `cache.prefix`, `database.redis.options.prefix` e `horizon.prefix` nas variáveis `SESSION_COOKIE`, `CACHE_PREFIX`, `REDIS_PREFIX` e `HORIZON_PREFIX` antes de mudar `APP_NAME`. Isso mantém os identificadores de sessão, cache e Redis. Depois, execute `php artisan config:clear` e `npm run build` e reinicie os processos persistentes da aplicação.

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
- [Backup do Banco](DATABASE-BACKUP.md)
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
