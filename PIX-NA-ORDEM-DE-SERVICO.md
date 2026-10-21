# Recebimento PIX na ordem de serviço

## Habilitação

1. Execute `php artisan migrate` e publique o build com `npm run build`.
2. Configure uma conta financeira e uma conexão IntegraBancos ativa, com PIX suportado pelo banco e pelo contrato local. Configure e homologue o webhook dessa conexão.
3. No management, em **Direitos das empresas**, habilite **Emissão de cobranças PIX** para a empresa.
4. Cadastre também **Recebimento PIX na ordem de serviço**, escolhendo a conta financeira, a categoria e os usuários autorizados. O padrão é somente usuários selecionados; uma lista vazia não autoriza ninguém. A alternativa é todos os usuários elegíveis da empresa.
5. Garanta que os usuários tenham vínculo ativo e acesso ao módulo de ordens no painel `operation`.

O direito respeita as datas de início/fim e não concede acesso a outras empresas ou ao módulo financeiro. Contas e categorias precisam pertencer à empresa do direito.

## Operação

No detalhe da OS no `operation`, **Gerar cobrança PIX** salva os dados do atendimento, encerra a OS, trata a requisição vinculada, fatura e confirma comercialmente, gera uma parcela PIX e solicita a emissão. Serviços, descontos, materiais e deslocamento seguem o cálculo da fatura.

Nenhum documento fiscal é criado por esse fluxo. O responsável pode gerar os documentos posteriormente pela ação fiscal da fatura.

O bloco apresenta QR Code, copia e cola, link assinado para compartilhar e confirmação persistente após a baixa financeira bancária. O pagamento também aparece no resumo da OS na listagem (aba Todas para ordens faturadas).

O fluxo inicial aceita pagamento integral de uma OS com uma única parcela. Fatura agrupada, recebimento anterior ou cobrança existente incompatível exigem revisão pelo financeiro. Repetir a ação retoma a mesma cobrança; inclusive em resultado incerto, não cria outra cobrança nem reenvia a emissão automaticamente. Falhas de emissão definitivas, divergências, cancelamentos e devoluções são tratados no financeiro existente.

## Filas e atualização

- Mantenha um worker consumindo a fila `banking` e o scheduler Laravel ativo.
- O webhook e as consultas usam o processamento bancário e a baixa financeira existentes.
- A tela consulta o estado local a cada cinco segundos. A consulta bancária de recuperação é limitada por cobrança, por padrão a cada 30 segundos enquanto a tela está aberta. Ajuste `PIX_OPERATION_QUERY_INTERVAL_SECONDS` aos limites homologados com o provedor (mínimo de 20 segundos).
- `pix:dispatch-queries`, já agendado a cada cinco minutos, recupera também as emissões de OS ainda pendentes de registro.
- Revogar o direito bloqueia novas solicitações e o acesso aos dados de pagamento pelo técnico. O recebimento de cobranças existentes continua sendo processado em segundo plano, e o resumo financeiro permanece disponível para quem pode visualizar a OS.
- A validade do link público não cancela a cobrança bancária.

## Verificação

`php artisan test tests/Feature/Filament/Operation/ServiceOrderPixPaymentTest.php`

Antes de liberar uma empresa, homologue geração, webhook de pagamento e consulta na conexão IntegraBancos efetivamente utilizada.
