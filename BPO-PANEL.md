# Painel BPO — carteira e operação financeira

## Endereços e entrada

- `/bpo/login`: login do BPO com apresentação própria.
- `/bpo` e `/bpo/carteira`: entrada na carteira, sem selecionar uma empresa.
- `/bpo/empresas/{tenant}`: ambiente operacional de uma empresa autorizada.

Após autenticar nos acessos operacionais, usuários com acesso BPO ativo são direcionados à carteira. Usuários sem atendimento BPO mantêm o destino anterior. Destinos de convites, downloads e páginas BPO já solicitadas são preservados; a entrada administrativa explícita pelo Management mantém seu fluxo.

Os painéis existentes continuam disponíveis conforme as permissões. O menu da conta mostra somente os outros painéis em que há empresas autorizadas e aponta para uma empresa efetivamente permitida naquele painel.

## Autorização da carteira

Não existe entidade de escritório. `BpoAccessService` resolve a carteira a partir de:

1. Usuário ativo e vínculo ativo com empresa ativa.
2. Atribuição de papel ativa originada de um atendimento BPO ativo.
3. Responsável do atendimento ativo e com vínculo válido.
4. Papel ativo da própria empresa, com o painel `bpo` habilitado.

Um papel chamado “BPO” ou o marcador de painel sozinho não concede entrada. Empresas próprias ou vínculos diretos independentes não entram na carteira automaticamente. Auxiliares acessam somente as empresas dos atendimentos em que receberam atribuições.

Papéis e alcances são reavaliados em cada solicitação. O middleware geral do painel e o middleware empresarial são persistentes nas chamadas Livewire. Componentes de conciliação revalidam a autorização sobre a importação proprietária.

### Compatibilidade de papéis

`PanelAccess` centraliza os painéis disponíveis na configuração de papéis. Ao conceder papéis em um atendimento BPO, a administração da empresa também habilita esse painel nos papéis envolvidos. Isso não concede novas ações e não permite que integrantes sem atendimento entrem no BPO.

A migration `2026_10_25_000002_enable_bpo_panel_for_existing_engagements.php` acrescenta o painel aos papéis já utilizados em atribuições BPO ativas. Ela preserva suas permissões e os painéis anteriores. Na configuração de um papel destinado a auxiliares, mantenha o painel BPO habilitado.

Vínculos anteriores em `legacy` mantêm seu comportamento nos painéis anteriores. **Dentro do BPO**, mesmo esses vínculos utilizam os papéis explícitos e seus alcances. Isso evita que o modo de compatibilidade libere dados além do atendimento na nova experiência.

## Entrega operacional

### Carteira

- Busca por nome ou CNPJ, com tolerância à pontuação do documento.
- Filtros de carteira completa, empresas com pendências e atendimentos em que o usuário é responsável.
- Identificação do responsável/auxiliar.
- Parcelas a pagar vencidas ou com vencimento nos próximos sete dias.
- Recebíveis vencidos, transações para conciliar/revisar e rejeições fiscais/revisão de RPS.
- Indicadores limitados às permissões e aos registros autorizados. Módulos não permitidos aparecem como “Sem acesso”, sem consultar ou expor seus totais.

Os saldos financeiros são apresentados por empresa, sem somar resultados de negócios independentes na carteira.

### Empresa em operação

- Visão geral com saldos abertos e prioridades visíveis.
- Contas a pagar e receber: listas, abas, criação, alteração e detalhes com parcelas, pagamentos e anexos, usando os serviços financeiros existentes.
- Movimentações financeiras: consulta e manutenção de movimentos manuais permitidos.
- Conciliação: importação OFX e reutilização da bancada de seleção e conferência dos lançamentos.
- Acompanhamento fiscal: consulta de notas, estados de emissão e retornos de rejeição. A emissão rápida dedicada é uma próxima frente do produto.
- DRE gerencial limitada à empresa em operação.
- Equipe e acessos com as permissões já existentes de gestão/delegação.

Empresa e identificação permanecem visíveis no cabeçalho e na navegação. O seletor de empresas resolve somente a carteira autorizada.

### Detalhe customizado de contas

A visualização de contas a pagar/receber usa `ViewFinancialTitle` e a view `filament/bpo/pages/financial-title.blade.php`. O cabeçalho ocupa dois terços do grid superior e o painel de anexos um terço. Abaixo, parcelas e pagamentos/recebimentos são apresentados simultaneamente, cada um em metade da área, sem abas. Valores, situação, classificação e saldo considerado são consultados novamente no servidor.

Os relation managers BPO herdam as ações financeiras existentes e oferecem colunas compactas, seleção de colunas e paginação independentes. As mutações são autorizadas pelo alcance da ação sobre a conta e continuam sujeitas às regras dos serviços de baixa, estorno e edição. Os eventos de parcelas/baixas atualizam também o resumo.

`App\Livewire\Bpo\RecordAttachments` é um componente reutilizável para contas, configurável no mount com `heading`, `allowedTypes`, `uploadsEnabled` e `deletionsEnabled`, além de `module` e `recordId`. Por padrão aceita os tipos genérico, documento fiscal e contrato, respeitando MIME/tamanho de `config/attachments.php` e o limite temporário do Livewire. A lista mantém a semântica de versões atuais do serviço de anexos. Oferece envio múltiplo, edição de descrição, prévia, download e remoção lógica.

Consulta exige acesso atual à conta; envio/alteração exige a permissão de atualização do lançamento, inclusive para anexar comprovantes a contas já pagas. O componente revalida empresa, registro e vínculo antes das ações. Downloads e prévias continuam pelas rotas autenticadas, com autorização explícita do título sem depender de um tenant Filament na URL do arquivo.

## Tema e estrutura visual

Foi utilizado o procedimento manual documentado pelo Filament 4:

- `resources/css/filament/bpo/theme.css`: tema próprio, importando os estilos técnicos dos componentes e declarando diretórios `@source`.
- `vite.config.js`: entrada dedicada do tema.
- `BpoPanelProvider`: registro por `viteTheme()`.
- `resources/views/components/bpo/layout.blade.php`: estrutura própria de navegação e área de trabalho.
- `resources/views/components/bpo/auth-layout.blade.php`: estrutura própria de autenticação.

A estrutura visual não utiliza o shell padrão de sidebar/topbar do Filament. Os componentes de formulários, tabelas e modais continuam sendo utilizados, com tema específico. O painel é voltado a desktop, com navegação lateral fixa, tabelas amplas, resumo operacional e largura mínima de área de trabalho de 1000 px. Os painéis anteriores mantêm seus estilos.

O seletor **Tema do painel**, no cabeçalho e no login, oferece Claro, Escuro e Sistema. A preferência utiliza a mesma chave `theme` dos painéis Filament e é aplicada antes do carregamento do CSS para evitar a abertura do modo escuro com uma tela clara. Mudanças do dispositivo, de outra aba e eventos `theme-changed` mantêm a interface sincronizada. `resources/js/bpo-theme.js` controla esse comportamento sem depender de uma sessão no backend.

As superfícies e os textos auxiliares usam tokens semânticos em `theme.css`, com paletas específicas para claro/escuro. As descrições dos módulos têm 11 px e os textos de apoio não dependem das cores claras fixas usadas na primeira versão. O tema também fornece a paleta das bancadas de equipe, permissões e conciliação.

Referências consultadas:

- <https://filamentphp.com/docs/4.x/styling/overview>
- <https://filamentphp.com/docs/4.x/users/tenancy>

## Implantação e verificação

```bash
php artisan migrate
npm run build
```

Aplicar as migrations e publicar o build junto do código. Reiniciar os workers conforme o processo de implantação do projeto. O desenvolvimento não aplicou migrations ao banco operacional; a conferência visual usa dados fictícios em SQLite temporário.

```bash
php artisan test tests/Feature/Filament/Bpo/BpoPanelTest.php
php artisan test --compact
```

As regressões incluem entrada automática, preservação de convites, usuários sem BPO, isolamento da carteira, registros próprios, consulta de saldos com ator explícito, revogação em componentes montados, manutenção financeira e reutilização da conciliação. O tema também é conferido em navegador desktop.
