# Usuários, papéis e alcances por empresa

## Modelo entregue

Uma identidade e um login por pessoa. Os papéis são acumuláveis por empresa; um usuário pode atuar como administrador, vendedor, técnico, BPO ou auxiliar em contextos diferentes. Não existe cadastro de escritório BPO.

- `company_user`: vínculo com a empresa e modo de autorização (`legacy` ou `roles`).
- `company_roles`: papéis personalizados da empresa, permissões e painéis disponíveis.
- `company_role_assignments`: atribuições de papéis, concedente e origem direta/BPO.
- `bpo_engagements`: autorização de atendimento, responsável e limite de delegação.
- `company_access_invitations`: convites pessoais, com token armazenado somente como hash, validade e aceite.
- `users.scoped_access_enabled`: mantém a proteção de consultas mesmo após remover o último vínculo migrado.

Papéis administrativos da plataforma continuam separados dos papéis operacionais. Não há bypass operacional de tenancy para administrador da plataforma. A administração central pode gerenciar a configuração de acessos; operar dados exige vínculo ativo com a empresa.

## Onde configurar

**Admin / Mobile → Configurações → Equipe e acessos**. No **Operation**, a mesma página está disponível no menu para quem pode administrar a equipe ou delegar um atendimento BPO.

O administrador da empresa pode:

1. Adicionar modelos de papéis sem sobrescrever os existentes.
2. Criar/editar papéis, escolhendo ações, alcance e painéis.
3. Atribuir vários papéis a uma pessoa cadastrada, identificada por e-mail.
4. Convidar por e-mail. A tela também fornece um link para compartilhamento.
5. Autorizar um BPO e definir as ações delegáveis aos auxiliares.
6. Suspender vínculos, revogar atribuições, revogar convites e encerrar atendimentos.

### Interface de gerenciamento

A página é organizada em **Pessoas**, **Papéis e permissões**, **Atendimentos BPO** e **Convites**. Em Pessoas, selecione um integrante para consultar seus papéis, editar cada concessão ou abrir as permissões resultantes. A edição contextual já preenche a pessoa, o atendimento e os papéis atuais.

O editor de papéis usa uma bancada por módulo, com busca, filtro de ações permitidas, atalhos de somente consulta/permissão/bloqueio e resumo de alcance. A interface apresenta ações pertinentes a cada módulo: **Orçamentos** é o cadastro de `Quote` usado no módulo de vendas. As chaves de autorização continuam compatíveis com os papéis já gravados. Os modelos novos utilizam as ações apresentadas no editor.

**Concedido pela empresa** substitui o rótulo anterior “Direto”: é um acesso independente de atendimento BPO. **Pelo atendimento de [responsável]** identifica papéis que serão retirados ao encerrar aquele atendimento. A página explica o efeito junto de cada concessão.

A consulta de permissões resultantes é somente leitura e considera os alcances acumulados, a suspensão do vínculo e as autorizações atuais do BPO. Na delegação, os papéis disponíveis são filtrados pelos limites do atendimento para facilitar a escolha de acessos válidos.

O convite tem validade de sete dias. A consulta por GET não concede acesso. O aceite é feito por POST com CSRF. Quem já possui conta entra com o mesmo e-mail; quem ainda não possui pode criar sua identidade pelo convite. O formulário não aceita privilégios administrativos da plataforma. O envio de e-mail utiliza o canal de notificações/mail configurado no Laravel, após o commit.

## Próprios, atribuídos e todos

- `own`: autoria (`created_by`) registrada pelo servidor.
- `assigned`: responsabilidade funcional, quando disponível. Na OS: técnico, supervisor ou vendedor; na requisição: vendedor.
- `company`: todos os registros da empresa autorizada.
- Permissão ausente: ação não permitida.

O catálogo omite alcances não suportados pelo módulo. Cadastros/configurações sem autoria adequada oferecem somente alcance de empresa. Criação é uma ação binária, pois ainda não existe registro para restringir.

Papéis somam permissões **por ação**. Um papel pode autorizar consulta ampla e outro alteração apenas de próprios. Próprios + atribuídos é a união desses conjuntos. Não há uma camada de bloqueios individuais contradizendo papéis.

Itens, parcelas e pagamentos herdam a visibilidade do documento principal. Alteração de responsável exige ação `assign`. Empresa, autoria e vínculo de itens com o documento original são protegidos nas gravações do modelo. Registros antigos sem autoria não recebem um autor presumido.

As ações disponíveis são definidas em `PermissionCatalog`; seus nomes são estáveis e os papéis são específicos por empresa. As regras de negócio existentes continuam valendo depois da autorização.

## Camada de código

- `CompanyAuthorization`: resolve vínculos, atribuições ativas, alcances, consulta autorizada e acesso por registro. Não mantém cache de permissões entre decisões.
- `CompanyTeamService`: mutações transacionais, bloqueio da empresa, convites, papéis e delegação; registra auditoria e protege o último administrador ativo.
- `RoleRecordScope`: restringe consultas Eloquent dos modelos registrados, incluindo filhos, ao ator autenticado. Soma as empresas com acesso autorizado e os alcances por empresa.
- `RoleAccessEnforcement`: integra Gate/Policies e eventos de gravação dos modelos registrados. Policies existentes preservam restrições de ciclo de vida e integridade financeira.
- `UserAccessService`: compatibilidade entre o controle anterior e o novo modelo.
- `AuthorizesCompanyPermission`: autorização das páginas de configuração e relatórios a cada ciclo Livewire.

O catálogo cobre os recursos centrais de vendas, OS, produção, financeiro, estoque e configurações registradas. Os serviços de relatório que usam SQL direto precisam de uma autorização explícita de relatório; SQL direto não recebe scopes Eloquent. As páginas DRE e lucro por produto possuem essa verificação. As exportações de OS/faturas revalidam o ator por registro, e a exportação XML verifica o alcance ao solicitar e baixar.

### Ao desenvolver novos fluxos

1. Registrar o agregado em `PermissionCatalog` e informar autoria/responsabilidade reais, ou restringir a alcance de empresa.
2. Registrar o caminho para o agregado em `children()` para modelos dependentes.
3. Consultar via Eloquent autorizado ou chamar `CompanyAuthorization::scopeQuery()` com empresa e ator explícitos.
4. Autorizar ações próprias e exportações, inclusive operações SQL em massa que não disparam eventos dos modelos.
5. Em filas/webhooks, manter o escopo empresarial e a autorização de negócio explícitos: esses processos não possuem automaticamente um usuário de sessão.
6. Para ações de formulário que criam um agregado e depois seus itens, configurar as permissões de manutenção dos itens necessárias ao fluxo. Modelos financeiros fornecidos já incluem essas permissões.

## Delegação BPO

O responsável só delega em atendimento ativo, com vínculo ativo, autorização da empresa e permissões próprias suficientes. Não existe herança automática da carteira inteira.

Para delegar uma ação relativa a registros a outra pessoa, a autorização da empresa precisa liberar a ação para delegação com alcance de empresa. Isso evita interpretar incorretamente “próprios do BPO” como “próprios do auxiliar”. O papel do auxiliar pode ser mais restrito (`own` ou `assigned`). Gestão da equipe não é delegável pelo atendimento BPO.

Mudanças em papéis usados por auxiliares são verificadas contra os limites de delegação. A resolução de permissões também verifica se o responsável ainda possui a autorização ampla. Encerrar o atendimento desativa as atribuições e convites originados nele; atribuições diretas permanecem.

Um BPO não pode reativar um vínculo suspenso pela empresa. A empresa pode reativar pela atribuição de papéis. Autorizar atendimento ou aceitar convite não converte silenciosamente um vínculo direto anterior para permissões restritas.

## Compatibilidade e ativação

A migration é aditiva. Vínculos anteriores permanecem em `legacy`; seus acessos não são substituídos automaticamente. Integrações internas anteriores que gravam diretamente no pivot também continuam no modo anterior por compatibilidade. **Novos fluxos de equipe devem usar `CompanyTeamService`, nunca um `attach()` sem modo de acesso.**

Na tela, **atribuir papéis diretamente** migra aquele usuário naquela empresa para `roles`. Essa operação substitui as atribuições diretas anteriores, preservando atribuições de outras origens. Revise todos os papéis necessários antes de migrar. A empresa pode continuar usando os painéis e módulos atuais sem BPO.

Proprietários anteriores (`company_user.role = owner`) podem iniciar a configuração. Se a empresa não tem proprietário identificado no vínculo anterior, a administração central com acesso à empresa deve configurar o administrador inicial. Não se infere proprietário a partir de um vínculo comum.

### Implantação

```bash
php artisan migrate
```

Aplicar a migration `2026_10_25_000001_create_company_access_roles.php` antes de ativar o novo código em produção e reiniciar os workers pelo procedimento normal do projeto. A migração adiciona autoria nullable às contas a pagar/receber, sem preencher o histórico por inferência.

Não foi executada migration no banco operacional durante o desenvolvimento desta entrega. Testes usam SQLite em memória. Bloqueios e concorrência devem ser homologados também no banco operacional.

### Verificação

```bash
php artisan test tests/Feature/Services/CompanyAuthorizationTest.php
composer test
```

As regressões abrangem combinação de papéis, próprios/atribuídos/todos, separação entre consulta e escrita, origem dos acessos, revogação, delegação BPO, suspensão, último administrador, convite/aceite/cadastro, páginas de gerenciamento e compatibilidade com vínculos anteriores.
