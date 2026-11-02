# PWA do painel Operation

## Estrutura

- Painel e entrada do aplicativo: `/operation`.
- Manifesto: `public/manifest-operation.webmanifest`, com escopo `/operation`, cobrindo a entrada, o login e as rotas das empresas.
- Ícones PNG: 192 e 512 px, maskable de 512 px e Apple Touch Icon de 180 px.
- `npm run build` gera `public/build/sw.js`. A rota pública `/sw.js` entrega esse arquivo com MIME JavaScript e revalidação de cache, permitindo escopo `/`.
- O runtime Workbox está embutido no worker. As entradas do precache apontam para `/build/assets/`.
- A navegação continua no servidor Laravel, sem fallback para `index.html`. O cache é de assets estáticos; os formulários e dados operacionais dependem de conexão.

## Instalação

No Chrome/Edge compatível, o painel apresenta a sugestão e o item **Menu → Instalar aplicativo** quando o navegador emite `beforeinstallprompt`. O diálogo nativo só é aberto após um clique. Recusar a instalação consome a oferta; uma nova depende de o navegador emiti-la novamente.

No iPhone/iPad, a opção apresenta instruções para abrir no Safari e usar **Compartilhar → Adicionar à Tela de Início**. A sugestão fica oculta no aplicativo já aberto em modo standalone. Dispensar o banner mantém a opção do menu disponível enquanto houver oferta de instalação.

## Publicação e validação

1. Publicar os arquivos e executar `npm run build` (ou `BUILD_FRONTEND=1 ./deploy/deploy.sh`).
2. Atualizar os caches de rotas e views pelo fluxo normal de deploy.
3. Acessar `/operation` por HTTPS. Para desenvolvimento, localhost é um contexto seguro; HTTP por IP da rede não é. O worker é gerado pelo build de produção, não pelo servidor Vite de desenvolvimento.
4. No DevTools, conferir **Application → Manifest** e **Service Workers**: manifesto sem erros e `/sw.js` ativado com escopo `/`.
5. Instalar pelo botão e abrir pelo ícone criado. Validar login, seleção/troca de empresa e navegação no painel. Abrir o endereço em uma aba continua sendo navegação normal do navegador.

O navegador pode suprimir ofertas em modo privado, após recusas anteriores ou quando o aplicativo já estiver instalado. A validação final da interface nativa deve ser feita no dispositivo de destino.

## Verificações automatizadas

```bash
npm run build
node --test tests/Frontend/operation-pwa.test.mjs tests/Frontend/pwa-worker.test.mjs
php artisan test tests/Feature/OperationPwaTest.php
```

Os PNGs podem ser regenerados com `php scripts/generate-pwa-icons.php` (requer PHP GD).
