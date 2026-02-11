## Detalhes
- Necessário possuir classe de Service com sub classes representando *actions*, responsáveis por fazer as operações.
- Possuir comunicação entre as camadas, respeitando o fluxo:
```md
	Filament -> ProductStockService -> Action
```
- Utilizar as traits existentes em Actions e Service, para realizar a comunicação de sucesso, erros etc.
## Task
- [ ] Criar CRUD completo (Seguir os padrões adotados no CRUD de Product);
- [ ] Realizar a comunicação com Filament;
