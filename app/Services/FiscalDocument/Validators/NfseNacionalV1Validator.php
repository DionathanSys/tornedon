<?php

namespace App\Services\FiscalDocument\Validators;

/**
 * Validates the assembled NFS-e Nacional V1 payload before submission.
 *
 * All rules follow the V1 specification:
 * - tipo_emitente = 1
 * - tomador nacional (uf ≠ 'EX')
 * - sem exportação, sem blocos especiais
 * - payload mínimo com campos obrigatórios
 */
class NfseNacionalV1Validator
{
    /** @var array<string, string[]> */
    private array $errors = [];

    /**
     * Validate the given payload array.
     *
     * @return array<string, string[]> Empty array if valid.
     */
    public function validate(array $payload): array
    {
        $this->errors = [];

        $this->validateRoot($payload);
        $this->validateTomador($payload['tomador'] ?? []);
        $this->validateServico($payload['servico'] ?? []);

        return $this->errors;
    }

    /**
     * Convenience method that returns true when the payload is valid.
     */
    public function passes(array $payload): bool
    {
        return $this->validate($payload) === [];
    }

    /**
     * Returns collected errors from the last validate() call.
     *
     * @return array<string, string[]>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    // ------------------------------------------------------------------
    // Root-level validation
    // ------------------------------------------------------------------

    private function validateRoot(array $payload): void
    {
        // data_emissao — required, ISO-8601 with timezone
        $dataEmissao = $payload['data_emissao'] ?? null;
        if (! is_string($dataEmissao) || trim($dataEmissao) === '') {
            $this->addError('data_emissao', 'A data de emissão é obrigatória.');
        } elseif (! $this->isIso8601WithTimezone($dataEmissao)) {
            $this->addError('data_emissao', 'A data de emissão deve estar no formato ISO-8601 com timezone (ex: 2026-05-05T10:30:00-03:00).');
        }

        // numero — required, numeric string 1-9 digits
        $numero = $payload['numero'] ?? null;
        if (! is_string($numero) || trim($numero) === '') {
            $this->addError('numero', 'O número do documento é obrigatório.');
        } elseif (! preg_match('/^\d{1,9}$/', $numero)) {
            $this->addError('numero', 'O número deve ser uma string numérica de 1 a 9 dígitos.');
        }

        // serie — required, 1-5 characters
        $serie = $payload['serie'] ?? null;
        if (! is_string($serie) || trim($serie) === '') {
            $this->addError('serie', 'A série é obrigatória.');
        } elseif (strlen($serie) > 5) {
            $this->addError('serie', 'A série deve conter de 1 a 5 caracteres.');
        }

        // tipo_emitente — must be '1' in V1
        $tipoEmitente = $payload['tipo_emitente'] ?? null;
        if ($tipoEmitente !== null && $tipoEmitente !== '1') {
            $this->addError('tipo_emitente', 'A V1 suporta apenas tipo_emitente = 1 (prestador).');
        }

        // tomador — required block
        if (! is_array($payload['tomador'] ?? null) || empty($payload['tomador'])) {
            $this->addError('tomador', 'O bloco do tomador é obrigatório.');
        }

        // servico — required block
        if (! is_array($payload['servico'] ?? null) || empty($payload['servico'])) {
            $this->addError('servico', 'O bloco de serviço é obrigatório.');
        }

        // data_competencia — optional, but if present must be ISO-8601
        $dataCompetencia = $payload['data_competencia'] ?? null;
        if ($dataCompetencia !== null && is_string($dataCompetencia) && ! $this->isIso8601WithTimezone($dataCompetencia)) {
            $this->addError('data_competencia', 'A data de competência deve estar no formato ISO-8601 com timezone.');
        }

        // informacoes_complementares — optional, max 2000 chars
        $infoComp = $payload['informacoes_complementares'] ?? null;
        if ($infoComp !== null && is_string($infoComp) && mb_strlen($infoComp) > 2000) {
            $this->addError('informacoes_complementares', 'As informações complementares devem conter no máximo 2000 caracteres.');
        }
    }

    // ------------------------------------------------------------------
    // Tomador validation
    // ------------------------------------------------------------------

    private function validateTomador(array $tomador): void
    {
        if (empty($tomador)) {
            return; // Already reported at root level
        }

        // cnpj or cpf — one is required
        $cnpj = $tomador['cnpj'] ?? null;
        $cpf  = $tomador['cpf'] ?? null;

        $hasCnpj = is_string($cnpj) && trim($cnpj) !== '';
        $hasCpf  = is_string($cpf) && trim($cpf) !== '';

        if (! $hasCnpj && ! $hasCpf) {
            $this->addError('tomador.documento', 'O tomador deve possuir CNPJ ou CPF.');
        } else {
            if ($hasCnpj && ! preg_match('/^\d{14}$/', $cnpj)) {
                $this->addError('tomador.cnpj', 'O CNPJ deve conter exatamente 14 dígitos.');
            }

            if ($hasCpf && ! preg_match('/^\d{11}$/', $cpf)) {
                $this->addError('tomador.cpf', 'O CPF deve conter exatamente 11 dígitos.');
            }
        }

        // razao_social — required, 1-300 chars
        $razaoSocial = $tomador['razao_social'] ?? null;
        if (! is_string($razaoSocial) || trim($razaoSocial) === '') {
            $this->addError('tomador.razao_social', 'A razão social do tomador é obrigatória.');
        } elseif (mb_strlen($razaoSocial) > 300) {
            $this->addError('tomador.razao_social', 'A razão social deve conter no máximo 300 caracteres.');
        }

        // telefone — optional, 6-20 digits when present
        $telefone = $tomador['telefone'] ?? null;
        if ($telefone !== null && is_string($telefone) && trim($telefone) !== '') {
            if (! preg_match('/^\d{6,20}$/', $telefone)) {
                $this->addError('tomador.telefone', 'O telefone deve conter de 6 a 20 dígitos.');
            }
        }

        // email — optional, max 60 chars
        $email = $tomador['email'] ?? null;
        if ($email !== null && is_string($email) && trim($email) !== '') {
            if (mb_strlen($email) > 60) {
                $this->addError('tomador.email', 'O e-mail deve conter no máximo 60 caracteres.');
            }
        }

        // tipo_destinatario — must be '0' in V1 (national)
        $tipoDestinatario = $tomador['tipo_destinatario'] ?? null;
        if ($tipoDestinatario !== null && $tipoDestinatario !== '0') {
            $this->addError('tomador.tipo_destinatario', 'A V1 suporta apenas tipo_destinatario = 0 (nacional).');
        }

        // endereco — required block
        $this->validateTomadorEndereco($tomador['endereco'] ?? []);
    }

    private function validateTomadorEndereco(array $endereco): void
    {
        if (empty($endereco)) {
            $this->addError('tomador.endereco', 'O endereço do tomador é obrigatório.');
            return;
        }

        // logradouro — required, 1-255 chars
        $logradouro = $endereco['logradouro'] ?? null;
        if (! is_string($logradouro) || trim($logradouro) === '') {
            $this->addError('tomador.endereco.logradouro', 'O logradouro é obrigatório.');
        } elseif (mb_strlen($logradouro) > 255) {
            $this->addError('tomador.endereco.logradouro', 'O logradouro deve conter no máximo 255 caracteres.');
        }

        // numero — required, 1-60 chars
        $numero = $endereco['numero'] ?? null;
        if (! is_string($numero) || trim($numero) === '') {
            $this->addError('tomador.endereco.numero', 'O número do endereço é obrigatório.');
        } elseif (mb_strlen($numero) > 60) {
            $this->addError('tomador.endereco.numero', 'O número do endereço deve conter no máximo 60 caracteres.');
        }

        // complemento — optional, max 256 chars
        $complemento = $endereco['complemento'] ?? null;
        if ($complemento !== null && is_string($complemento) && mb_strlen($complemento) > 256) {
            $this->addError('tomador.endereco.complemento', 'O complemento deve conter no máximo 256 caracteres.');
        }

        // bairro — required, 1-60 chars
        $bairro = $endereco['bairro'] ?? null;
        if (! is_string($bairro) || trim($bairro) === '') {
            $this->addError('tomador.endereco.bairro', 'O bairro é obrigatório.');
        } elseif (mb_strlen($bairro) > 60) {
            $this->addError('tomador.endereco.bairro', 'O bairro deve conter no máximo 60 caracteres.');
        }

        // uf — required, exactly 2 chars, must not be 'EX'
        $uf = $endereco['uf'] ?? null;
        if (! is_string($uf) || trim($uf) === '') {
            $this->addError('tomador.endereco.uf', 'A UF é obrigatória.');
        } elseif (strlen($uf) !== 2) {
            $this->addError('tomador.endereco.uf', 'A UF deve conter exatamente 2 caracteres.');
        } elseif (strtoupper($uf) === 'EX') {
            $this->addError('tomador.endereco.uf', 'A V1 não suporta emissão para exterior (UF = EX).');
        }

        // codigo_municipio — optional, 7 digits when present
        $codigoMunicipio = $endereco['codigo_municipio'] ?? null;
        if ($codigoMunicipio !== null && is_string($codigoMunicipio) && trim($codigoMunicipio) !== '') {
            if (! preg_match('/^\d{7}$/', $codigoMunicipio)) {
                $this->addError('tomador.endereco.codigo_municipio', 'O código do município deve conter exatamente 7 dígitos.');
            }
        }

        // cep — optional, 8 digits when present
        $cep = $endereco['cep'] ?? null;
        if ($cep !== null && is_string($cep) && trim($cep) !== '') {
            if (! preg_match('/^\d{8}$/', $cep)) {
                $this->addError('tomador.endereco.cep', 'O CEP deve conter exatamente 8 dígitos.');
            }
        }
    }

    // ------------------------------------------------------------------
    // Serviço validation
    // ------------------------------------------------------------------

    private function validateServico(array $servico): void
    {
        if (empty($servico)) {
            return; // Already reported at root level
        }

        // codigo — required, format validated (6 digits expected for nacional)
        $codigo = $servico['codigo'] ?? null;
        if (! is_string($codigo) || trim($codigo) === '') {
            $this->addError('servico.codigo', 'O código do serviço é obrigatório.');
        }

        // discriminacao — required, 1-2000 chars
        $discriminacao = $servico['discriminacao'] ?? null;
        if (! is_string($discriminacao) || trim($discriminacao) === '') {
            $this->addError('servico.discriminacao', 'A discriminação do serviço é obrigatória.');
        } elseif (mb_strlen($discriminacao) > 2000) {
            $this->addError('servico.discriminacao', 'A discriminação deve conter no máximo 2000 caracteres.');
        }

        // codigo_nbs — required, 9 digits
        $codigoNbs = $servico['codigo_nbs'] ?? null;
        if (! is_string($codigoNbs) || trim($codigoNbs) === '') {
            $this->addError('servico.codigo_nbs', 'O código NBS é obrigatório.');
        } elseif (! preg_match('/^\d{9}$/', $codigoNbs)) {
            $this->addError('servico.codigo_nbs', 'O código NBS deve conter exatamente 9 dígitos.');
        }

        // valor_servicos — required, >= 0.01
        $valorServicos = $servico['valor_servicos'] ?? null;
        if (! is_numeric($valorServicos)) {
            $this->addError('servico.valor_servicos', 'O valor dos serviços é obrigatório.');
        } elseif ((float) $valorServicos < 0.01) {
            $this->addError('servico.valor_servicos', 'O valor dos serviços deve ser no mínimo R$ 0,01.');
        }

        // valor_recebido — optional, >= 0
        $valorRecebido = $servico['valor_recebido'] ?? null;
        if ($valorRecebido !== null && is_numeric($valorRecebido) && (float) $valorRecebido < 0) {
            $this->addError('servico.valor_recebido', 'O valor recebido não pode ser negativo.');
        }

        // valor_desconto_condicionado — optional, >= 0
        $descontoCond = $servico['valor_desconto_condicionado'] ?? null;
        if ($descontoCond !== null && is_numeric($descontoCond) && (float) $descontoCond < 0) {
            $this->addError('servico.valor_desconto_condicionado', 'O desconto condicionado não pode ser negativo.');
        }

        // valor_desconto_incondicionado — optional, >= 0
        $descontoIncond = $servico['valor_desconto_incondicionado'] ?? null;
        if ($descontoIncond !== null && is_numeric($descontoIncond) && (float) $descontoIncond < 0) {
            $this->addError('servico.valor_desconto_incondicionado', 'O desconto incondicionado não pode ser negativo.');
        }

        // tributos_municipais
        $this->validateTributosMunicipais($servico['tributos_municipais'] ?? []);

        // tributos_nacionais
        $this->validateTributosNacionais($servico['tributos_nacionais'] ?? []);
    }

    private function validateTributosMunicipais(array $tributos): void
    {
        if (empty($tributos)) {
            return;
        }

        // tipo_operacao — must be '1' in V1
        $tipoOperacao = $tributos['tipo_operacao'] ?? null;
        if ($tipoOperacao !== null && $tipoOperacao !== '1') {
            $this->addError('servico.tributos_municipais.tipo_operacao', 'A V1 suporta apenas tipo_operacao = 1 (tributação no município).');
        }

        // aliquota_iss — 0-100 when present (percentage, not decimal)
        $aliquotaIss = $tributos['valor_aliquota'] ?? $tributos['aliquota_iss'] ?? null;
        if ($aliquotaIss !== null && is_numeric($aliquotaIss)) {
            $aliquota = (float) $aliquotaIss;
            if ($aliquota < 0 || $aliquota > 100) {
                $this->addError('servico.tributos_municipais.aliquota_iss', 'A alíquota do ISS deve estar entre 0 e 100 (percentual).');
            }
        }
    }

    private function validateTributosNacionais(array $tributos): void
    {
        if (empty($tributos)) {
            return;
        }

        // cst — required within tributos_nacionais block
        $cst = $tributos['cst'] ?? null;
        if (! is_string($cst) || trim($cst) === '') {
            $this->addError('servico.tributos_nacionais.cst', 'O CST dos tributos nacionais é obrigatório.');
        }

        // tipo_retencao — must be '1' or '2'
        $tipoRetencao = $tributos['tipo_retencao'] ?? null;
        if ($tipoRetencao !== null && ! in_array($tipoRetencao, ['1', '2'], true)) {
            $this->addError('servico.tributos_nacionais.tipo_retencao', 'O tipo de retenção deve ser 1 (retido) ou 2 (não retido).');
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function isIso8601WithTimezone(string $value): bool
    {
        // Accepts: 2026-05-05T10:30:00-03:00 or 2026-05-05T10:30:00Z
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-]\d{2}:\d{2}|Z)$/', $value);
    }

    private function addError(string $key, string $message): void
    {
        $this->errors[$key][] = $message;
    }
}
