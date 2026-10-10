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
