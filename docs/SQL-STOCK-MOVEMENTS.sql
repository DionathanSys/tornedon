-- SQL Útil para Monitoramento de Movimentações de Estoque

-- ============================================
-- 1. CONSULTAS BÁSICAS
-- ============================================

-- Ver todas as movimentações
SELECT 
    sm.id,
    sm.created_at,
    p.name AS produto,
    CASE sm.type
        WHEN 'entry' THEN 'Entrada'
        WHEN 'exit' THEN 'Saída'
        WHEN 'adjustment' THEN 'Ajuste'
        WHEN 'transfer' THEN 'Transferência'
        WHEN 'return' THEN 'Devolução'
        WHEN 'consumption' THEN 'Consumo'
        WHEN 'loss' THEN 'Perda'
    END AS tipo,
    sm.quantity,
    sm.unit_cost,
    sm.total_cost,
    u.name AS usuario,
    sm.reason
FROM stock_movements sm
JOIN products p ON sm.product_id = p.id
JOIN users u ON sm.user_id = u.id
WHERE sm.deleted_at IS NULL
ORDER BY sm.created_at DESC;

-- Ver movimentações por empresa
SELECT 
    sm.id,
    sm.created_at,
    p.name,
    sm.type AS tipo,
    sm.quantity,
    c.name AS empresa
FROM stock_movements sm
JOIN products p ON sm.product_id = p.id
JOIN companies c ON sm.company_id = c.id
WHERE c.id = 1  -- Mudar company_id conforme necessário
    AND sm.deleted_at IS NULL
ORDER BY sm.created_at DESC;

-- ============================================
-- 2. ANÁLISE POR TIPO DE MOVIMENTO
-- ============================================

-- Resumo de movimentações por tipo
SELECT 
    CASE type
        WHEN 'entry' THEN 'Entrada'
        WHEN 'exit' THEN 'Saída'
        WHEN 'adjustment' THEN 'Ajuste'
        WHEN 'transfer' THEN 'Transferência'
        WHEN 'return' THEN 'Devolução'
        WHEN 'consumption' THEN 'Consumo'
        WHEN 'loss' THEN 'Perda'
    END AS tipo_movimento,
    COUNT(*) AS quantidade_registros,
    SUM(quantity) AS quantidade_total,
    SUM(total_cost) AS custo_total
FROM stock_movements
WHERE deleted_at IS NULL
GROUP BY type
ORDER BY quantidade_total DESC;

-- Entradas vs Saídas no período
SELECT 
    DATE(sm.created_at) AS data,
    CASE 
        WHEN sm.type = 'entry' THEN 'Entrada'
        WHEN sm.type IN ('exit', 'consumption', 'loss') THEN 'Saída'
        ELSE 'Outro'
    END AS categoria,
    SUM(CASE WHEN sm.type = 'entry' THEN sm.quantity ELSE 0 END) AS entradas,
    SUM(CASE WHEN sm.type IN ('exit', 'consumption', 'loss') THEN sm.quantity ELSE 0 END) AS saidas,
    SUM(sm.quantity) AS saldo_bruto
FROM stock_movements sm
WHERE sm.deleted_at IS NULL
    AND sm.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(sm.created_at)
ORDER BY data DESC;

-- ============================================
-- 3. ANÁLISE POR PRODUTO
-- ============================================

-- Movimentações por produto
SELECT 
    p.id,
    p.name,
    COUNT(sm.id) AS total_movimentacoes,
    SUM(CASE WHEN sm.type = 'entry' THEN sm.quantity ELSE 0 END) AS total_entrada,
    SUM(CASE WHEN sm.type IN ('exit', 'consumption') THEN sm.quantity ELSE 0 END) AS total_saida,
    SUM(CASE WHEN sm.type = 'adjustment' THEN sm.quantity ELSE 0 END) AS tempo_ajuste,
    SUM(sm.total_cost) AS custo_total_movimentado
FROM products p
LEFT JOIN stock_movements sm ON p.id = sm.product_id AND sm.deleted_at IS NULL
GROUP BY p.id, p.name
ORDER BY total_movimentacoes DESC;

-- Produtos com maior movimentação em 30 dias
SELECT 
    p.name,
    COUNT(*) AS num_movimentacoes,
    SUM(sm.quantity) AS quantidade_movida,
    SUM(sm.total_cost) AS valor_movido
FROM stock_movements sm
JOIN products p ON sm.product_id = p.id
WHERE sm.deleted_at IS NULL
    AND sm.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY p.id, p.name
ORDER BY quantidade_movida DESC
LIMIT 10;

-- ============================================
-- 4. ANÁLISE POR USUÁRIO
-- ============================================

-- Movimentações por usuário
SELECT 
    u.id,
    u.name,
    COUNT(sm.id) AS total_registros,
    SUM(sm.quantity) AS quantidade_moventada,
    MIN(sm.created_at) AS primeira_movimentacao,
    MAX(sm.created_at) AS ultima_movimentacao
FROM stock_movements sm
JOIN users u ON sm.user_id = u.id
WHERE sm.deleted_at IS NULL
GROUP BY u.id, u.name
ORDER BY total_registros DESC;

-- Usuários que registraram perdas
SELECT 
    u.name,
    COUNT(*) AS total_perdas,
    SUM(sm.quantity) AS quantidade_perdida,
    SUM(sm.total_cost) AS valor_perdido
FROM stock_movements sm
JOIN users u ON sm.user_id = u.id
WHERE sm.type = 'loss'
    AND sm.deleted_at IS NULL
GROUP BY u.id, u.name
ORDER BY quantidade_perdida DESC;

-- ============================================
-- 5. ANÁLISE FINANCEIRA
-- ============================================

-- Custos totais por tipo de movimento
SELECT 
    CASE type
        WHEN 'entry' THEN 'Entrada'
        WHEN 'exit' THEN 'Saída'
        WHEN 'adjustment' THEN 'Ajuste'
        WHEN 'consumption' THEN 'Consumo'
        WHEN 'loss' THEN 'Perda'
        WHEN 'transfer' THEN 'Transferência'
        WHEN 'return' THEN 'Devolução'
    END AS tipo,
    COUNT(*) AS quantidade_registros,
    SUM(total_cost) AS custo_total,
    AVG(total_cost) AS custo_medio
FROM stock_movements
WHERE deleted_at IS NULL
GROUP BY type
ORDER BY custo_total DESC;

-- Perdas e ajustes (potencial problema de estoque)
SELECT 
    sm.id,
    sm.created_at,
    p.name AS produto,
    CASE sm.type 
        WHEN 'loss' THEN 'PERDA'
        WHEN 'adjustment' THEN 'AJUSTE'
    END AS tipo_desvio,
    sm.quantity,
    sm.total_cost,
    u.name AS usuario,
    sm.reason,
    sm.observations
FROM stock_movements sm
JOIN products p ON sm.product_id = p.id
JOIN users u ON sm.user_id = u.id
WHERE sm.type IN ('loss', 'adjustment')
    AND sm.deleted_at IS NULL
ORDER BY sm.created_at DESC;

-- ============================================
-- 6. RASTREABILIDADE (Reference)
-- ============================================

-- Movimentações ligadas a requisições
SELECT 
    sm.id,
    r.number AS requisicao_numero,
    p.name AS produto,
    sm.quantity,
    sm.type,
    sm.created_at
FROM stock_movements sm
JOIN products p ON sm.product_id = p.id
LEFT JOIN requisitions r ON sm.reference_id = r.id 
    AND sm.reference_type = 'requisition'
WHERE sm.reference_type = 'requisition'
    AND sm.deleted_at IS NULL
ORDER BY sm.created_at DESC;

-- Movimentações ligadas a ordens de produção
SELECT 
    sm.id,
    sm.reference_id AS production_order_id,
    p.name AS produto,
    sm.quantity,
    CASE sm.type
        WHEN 'consumption' THEN 'Consumido'
        WHEN 'entry' THEN 'Retornado'
    END AS acao,
    sm.created_at
FROM stock_movements sm
JOIN products p ON sm.product_id = p.id
WHERE sm.reference_type = 'production_order'
    AND sm.deleted_at IS NULL
ORDER BY sm.created_at DESC;

-- Movimentações sem referência (soltas)
SELECT 
    sm.id,
    sm.created_at,
    p.name,
    sm.type,
    sm.quantity,
    sm.reason,
    u.name AS usuario
FROM stock_movements sm
JOIN products p ON sm.product_id = p.id
JOIN users u ON sm.user_id = u.id
WHERE sm.reference_type IS NULL
    AND sm.deleted_at IS NULL
ORDER BY sm.created_at DESC;

-- ============================================
-- 7. HISTÓRICO E AUDITORIA
-- ============================================

-- Modificações (alterações de movimentações)
SELECT 
    sm.id,
    sm.created_at,
    sm.updated_at,
    p.name,
    sm.quantity,
    c_by.name AS criado_por,
    u_by.name AS modificado_por,
    CASE 
        WHEN sm.updated_at IS NOT NULL 
        THEN TIMEDIFF(sm.updated_at, sm.created_at)
        ELSE 'N/A'
    END AS tempo_ate_edicao
FROM stock_movements sm
JOIN products p ON sm.product_id = p.id
JOIN users c_by ON sm.created_by = c_by.id
LEFT JOIN users u_by ON sm.updated_by = u_by.id
WHERE sm.deleted_at IS NULL
    AND sm.updated_at IS NOT NULL
ORDER BY sm.updated_at DESC;

-- Movimentações deletadas (auditoria)
SELECT 
    sm.id,
    sm.created_at,
    sm.deleted_at,
    p.name,
    sm.type,
    sm.quantity,
    u.name AS usuario_criador
FROM stock_movements sm
JOIN products p ON sm.product_id = p.id
JOIN users u ON sm.created_by = u.id
WHERE sm.deleted_at IS NOT NULL
ORDER BY sm.deleted_at DESC
LIMIT 50;

-- ============================================
-- 8. ALERTAS E VALIDAÇÃO
-- ============================================

-- Movimentações anômalas (quantidades muito altas)
SELECT 
    sm.id,
    sm.created_at,
    p.name,
    sm.quantity,
    sm.total_cost,
    sm.type,
    u.name
FROM stock_movements sm
JOIN products p ON sm.product_id = p.id
JOIN users u ON sm.user_id = u.id
WHERE sm.deleted_at IS NULL
    AND (sm.quantity > 10000 OR sm.total_cost > 100000)
ORDER BY sm.quantity DESC;

-- Registros com total_cost divergente (unit_cost * quantity != total_cost)
SELECT 
    sm.id,
    sm.created_at,
    p.name,
    sm.quantity,
    sm.unit_cost,
    sm.total_cost,
    (sm.quantity * sm.unit_cost) AS calculo_esperado,
    ABS((sm.quantity * sm.unit_cost) - sm.total_cost) AS divergencia
FROM stock_movements sm
JOIN products p ON sm.product_id = p.id
WHERE sm.deleted_at IS NULL
    AND sm.unit_cost IS NOT NULL
    AND ABS((sm.quantity * sm.unit_cost) - sm.total_cost) > 0.01
ORDER BY divergencia DESC;

-- Movimento duplicado (mesmo produto, usuario, tipo, no mesmo dia)
SELECT 
    DATE(sm.created_at) AS data,
    p.name,
    u.name,
    sm.type,
    COUNT(*) AS num_movimentacoes,
    GROUP_CONCAT(sm.id) AS ids
FROM stock_movements sm
JOIN products p ON sm.product_id = p.id
JOIN users u ON sm.user_id = u.id
WHERE sm.deleted_at IS NULL
GROUP BY DATE(sm.created_at), p.id, u.id, sm.type
HAVING COUNT(*) > 1
ORDER BY data DESC;

-- ============================================
-- 9. RELATÓRIOS EXECUTIVOS
-- ============================================

-- Dashboard: Resumo diário
SELECT 
    DATE(sm.created_at) AS data,
    COUNT(*) AS total_movimentacoes,
    COUNT(DISTINCT sm.product_id) AS produtos_moventados,
    COUNT(DISTINCT sm.user_id) AS usuarios_ativos,
    SUM(sm.quantity) AS quantidade_total,
    SUM(sm.total_cost) AS valor_total,
    SUM(CASE WHEN sm.type = 'loss' THEN sm.quantity ELSE 0 END) AS perdas
FROM stock_movements sm
WHERE sm.deleted_at IS NULL
    AND sm.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(sm.created_at)
ORDER BY data DESC;

-- Top 5 produtos por valor movementado (últimos 30 dias)
SELECT 
    p.id,
    p.name,
    SUM(sm.total_cost) AS valor_movimentado,
    SUM(sm.quantity) AS quantidade,
    COUNT(*) AS num_movimentacoes
FROM stock_movements sm
JOIN products p ON sm.product_id = p.id
WHERE sm.deleted_at IS NULL
    AND sm.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY p.id, p.name
ORDER BY valor_movimentado DESC
LIMIT 5;

-- Custo de perdas por semana
SELECT 
    YEAR(sm.created_at) AS ano,
    WEEK(sm.created_at) AS semana,
    SUM(sm.total_cost) AS custo_perdas,
    SUM(sm.quantity) AS quantidade_perdida,
    COUNT(*) AS num_perdas
FROM stock_movements sm
WHERE sm.type = 'loss'
    AND sm.deleted_at IS NULL
GROUP BY ano, semana
ORDER BY ano DESC, semana DESC;

-- ============================================
-- 10. ÍNDICES VERIFICAÇÃO
-- ============================================

-- Ver índices da tabela
SHOW INDEX FROM stock_movements;

-- Performance: queries lentas
SELECT 
    sm.id,
    sm.created_at,
    (SELECT COUNT(*) FROM stock_movements WHERE company_id = sm.company_id) AS movs_empresa,
    (SELECT COUNT(*) FROM stock_movements WHERE product_id = sm.product_id) AS movs_produto
FROM stock_movements sm
WHERE sm.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
LIMIT 100;

-- ============================================
-- 11. BACKUP E LIMPEZA
-- ============================================

-- Contar registros antes de limpar deletados
SELECT 
    COUNT(*) AS total_registros,
    COUNT(CASE WHEN deleted_at IS NULL THEN 1 END) AS registros_ativos,
    COUNT(CASE WHEN deleted_at IS NOT NULL THEN 1 END) AS registros_deletados
FROM stock_movements;

-- Deletados com mais de 90 dias (candidatos para force delete)
SELECT 
    id,
    deleted_at,
    DATEDIFF(NOW(), deleted_at) AS dias_desde_delecao
FROM stock_movements
WHERE deleted_at IS NOT NULL
    AND deleted_at <= DATE_SUB(NOW(), INTERVAL 90 DAY)
ORDER BY deleted_at ASC;

-- ============================================
-- NOTAS IMPORTANTES
-- ============================================
/*
1. Use índices criados para melhor performance:
   - (product_stock_id, created_at)
   - (company_id, created_at)
   - (type)
   - (user_id)
   - (reference_type, reference_id)

2. Para ficar performance, use:
   - LIMIT para resultados grandes
   - WHERE com company_id sempre que possível
   - DATE_SUB() para períodos, não comparações abertas

3. Soft deletes: use deleted_at IS NULL em WHERE

4. JSON fields: use JSON_EXTRACT() para consultas
   Exemplo: WHERE JSON_EXTRACT(additional_info, '$.lote') = '123'

5. Money: resultados são DECIMAL(12,4), considere formatar

*/
