# Interface do painel Operation — maryUI

O Operation usa **maryUI 2 + daisyUI 5 + Tailwind 4** para a interface. O Filament
continua responsável pelas rotas, autenticação e identificação da empresa, e os
serviços existentes continuam responsáveis pelas operações de negócio.

## Layout e assets

- `app/Filament/Operation/OperationPage.php`: base das páginas operacionais.
- `resources/views/components/operation/layout.blade.php`: layout próprio, com
  Livewire e sem os assets de formulários/modais do Filament.
- `resources/css/operation.css`: entrada Vite carregada apenas no Operation.
- `config/mary.php`: prefixo `mary-`, evitando colisões com componentes existentes.
- Os temas claro/escuro usam os temas da daisyUI e a preferência `theme` já usada
  pelo aplicativo.

Use `<x-mary-card>`, `<x-mary-input>`, `<x-mary-select>`, `<x-mary-choices>`,
`<x-mary-modal>` e os demais componentes da biblioteca. Não renderize schemas ou
modais de ações Filament dentro das telas Operation.

## Componentes compartilhados

`resources/views/components/operation/` contém os componentes de página, tema,
barra inferior, botão flutuante, filtros e status. Apenas a disposição da barra
inferior, do botão flutuante e os limites de viewport têm CSS próprio; campos e
modais usam os estilos da biblioteca.

`page` organiza o conteúdo com espaçamento de grid, sobre o fundo do layout.
`panel` padroniza o padding e o espaçamento interno dos cards. `record-item`
usa o List Item do Mary UI, com separador entre registros. `search-select`
padroniza a seleção única pesquisável com texto simples, sem a cápsula de
seleção múltipla: cliente e serviço usam busca no servidor; técnico e equipamento
usam busca local nas opções já limitadas à empresa e ao cliente.

## Filtros e paginação das listagens

OS e requisições têm o botão **Filtrar**, com data inicial/final, **Aplicar** e
**Limpar**. O período considera `order_date` nas OS e `sale_date` nas requisições,
inclui as duas datas e aceita um único limite. O período aplicado aparece junto
à busca, funciona com as abas e atualiza seus contadores.

`HasDateFilters` guarda apenas o período validado na sessão, separado por
usuário, empresa e listagem. O filtro é restaurado ao voltar ou recarregar a
página. Limpar remove o período da sessão daquela listagem; um intervalo
inválido mantém o último período aplicado.

As duas listagens usam paginação Livewire de **15 registros** e o componente
Mary UI `operation.pagination`. Busca, troca de aba, aplicação e limpeza do
período voltam para a primeira página. A ordenação inclui o ID para estabilizar
a navegação entre registros com a mesma data.

A barra dos registros mostra até três ações; as demais vão para **Mais**.
Os botões de criação nas listagens e no Menu enviam o evento
`operation-create-record` para `App\Livewire\OperationRecordCreator`, que mantém
um único fluxo de criação para OS e requisições, incluindo cadastro rápido de
cliente. Esse componente fica fora da barra de navegação.

O modal de serviços pertence à página `ServiceOrderDetail`. Seu formulário
inteiro fica dentro de `<x-mary-modal>`, separado do formulário de atendimento.
Inclusão/edição só são permitidas em OS aberta. As consultas e validações de
cliente, serviço, técnico, equipamento e item preservam o escopo da empresa.

## Verificação

```sh
composer install
npm ci
npm run build
php artisan test tests/Feature/Filament/Operation/OperationPanelTest.php
```

Ao alterar modais, confira também em navegador: abertura pelo Menu e pelo botão
flutuante, busca e seleção, validação, envio, rolagem em tela curta, tema escuro
e o último registro acima da barra inferior.

## Deploy

`public/build` não é versionado. O script `deploy/deploy.sh` gera o build por
padrão, instalando também as dependências de desenvolvimento necessárias ao
Vite (`npm ci --include=dev`). Ele valida o manifesto e o CSS do Operation antes
de concluir. Use `BUILD_FRONTEND=0` apenas quando o build completo já tiver sido
gerado e enviado para o servidor.

Para corrigir uma instalação sem manifesto, execute na raiz da aplicação:

```sh
npm ci --include=dev
npm run build
php artisan view:clear
```

Devem existir `public/build/manifest.json` e os arquivos correspondentes em
`public/build/assets/`. Se o PHP estiver em um contêiner, o diretório gerado
precisa estar disponível no volume ou na imagem que atende a aplicação.
