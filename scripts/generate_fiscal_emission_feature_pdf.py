from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from textwrap import wrap

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.utils import simpleSplit
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas


PAGE_WIDTH, PAGE_HEIGHT = A4
MARGIN_X = 42
MARGIN_TOP = 44
MARGIN_BOTTOM = 40
CONTENT_WIDTH = PAGE_WIDTH - (MARGIN_X * 2)

COLOR_BG = colors.HexColor("#F5F1E8")
COLOR_PANEL = colors.HexColor("#FFFDF8")
COLOR_PRIMARY = colors.HexColor("#143642")
COLOR_ACCENT = colors.HexColor("#C97064")
COLOR_MUTED = colors.HexColor("#64748B")
COLOR_TEXT = colors.HexColor("#1F2937")
COLOR_LINE = colors.HexColor("#D6D3D1")
COLOR_SUCCESS = colors.HexColor("#1D7A62")
COLOR_WARNING = colors.HexColor("#B45309")


def register_fonts() -> tuple[str, str, str]:
    candidates = [
        ("C:/Windows/Fonts/arial.ttf", "C:/Windows/Fonts/arialbd.ttf", "C:/Windows/Fonts/ariali.ttf"),
        ("C:/Windows/Fonts/calibri.ttf", "C:/Windows/Fonts/calibrib.ttf", "C:/Windows/Fonts/calibrii.ttf"),
    ]

    for regular, bold, italic in candidates:
        if Path(regular).exists() and Path(bold).exists():
            pdfmetrics.registerFont(TTFont("DocRegular", regular))
            pdfmetrics.registerFont(TTFont("DocBold", bold))
            if Path(italic).exists():
                pdfmetrics.registerFont(TTFont("DocItalic", italic))
            else:
                pdfmetrics.registerFont(TTFont("DocItalic", regular))
            return "DocRegular", "DocBold", "DocItalic"

    return "Helvetica", "Helvetica-Bold", "Helvetica-Oblique"


FONT_REGULAR, FONT_BOLD, FONT_ITALIC = register_fonts()


@dataclass
class PdfDoc:
    canvas: canvas.Canvas
    page_number: int = 0
    y: float = PAGE_HEIGHT - MARGIN_TOP

    def new_page(self) -> None:
        if self.page_number > 0:
            self.draw_footer()
            self.canvas.showPage()
        self.page_number += 1
        self.y = PAGE_HEIGHT - MARGIN_TOP
        self.draw_background()

    def draw_background(self) -> None:
        self.canvas.setFillColor(COLOR_BG)
        self.canvas.rect(0, 0, PAGE_WIDTH, PAGE_HEIGHT, fill=1, stroke=0)
        self.canvas.setFillColor(COLOR_PRIMARY)
        self.canvas.rect(0, PAGE_HEIGHT - 16, PAGE_WIDTH, 16, fill=1, stroke=0)

    def draw_footer(self) -> None:
        self.canvas.setStrokeColor(COLOR_LINE)
        self.canvas.line(MARGIN_X, MARGIN_BOTTOM - 8, PAGE_WIDTH - MARGIN_X, MARGIN_BOTTOM - 8)
        self.canvas.setFont(FONT_REGULAR, 8)
        self.canvas.setFillColor(COLOR_MUTED)
        self.canvas.drawString(MARGIN_X, MARGIN_BOTTOM - 22, "Tornedon - Fluxo da feature de emissao fiscal")
        self.canvas.drawRightString(PAGE_WIDTH - MARGIN_X, MARGIN_BOTTOM - 22, f"Pagina {self.page_number}")

    def ensure_space(self, needed: float) -> None:
        if self.y - needed < MARGIN_BOTTOM + 10:
            self.new_page()

    def title(self, text: str, subtitle: str) -> None:
        self.ensure_space(140)
        self.canvas.setFillColor(COLOR_PRIMARY)
        self.canvas.setFont(FONT_BOLD, 24)
        self.canvas.drawString(MARGIN_X, self.y, text)
        self.y -= 26
        self.canvas.setFont(FONT_REGULAR, 12)
        self.canvas.setFillColor(COLOR_MUTED)
        for line in simpleSplit(subtitle, FONT_REGULAR, 12, CONTENT_WIDTH):
            self.canvas.drawString(MARGIN_X, self.y, line)
            self.y -= 16
        self.y -= 10

    def section(self, label: str, intro: str | None = None) -> None:
        self.ensure_space(60)
        self.canvas.setFillColor(COLOR_ACCENT)
        self.canvas.roundRect(MARGIN_X, self.y - 4, 110, 20, 8, fill=1, stroke=0)
        self.canvas.setFillColor(colors.white)
        self.canvas.setFont(FONT_BOLD, 10)
        self.canvas.drawString(MARGIN_X + 10, self.y + 2, label.upper())
        self.y -= 28
        if intro:
            self.paragraph(intro, size=11, color=COLOR_TEXT, leading=15)
            self.y -= 4

    def paragraph(self, text: str, *, size: int = 11, color=COLOR_TEXT, leading: int = 15) -> None:
        lines = simpleSplit(text, FONT_REGULAR, size, CONTENT_WIDTH)
        self.ensure_space((len(lines) * leading) + 4)
        self.canvas.setFont(FONT_REGULAR, size)
        self.canvas.setFillColor(color)
        for line in lines:
            self.canvas.drawString(MARGIN_X, self.y, line)
            self.y -= leading

    def bullet_list(self, items: list[str], *, size: int = 11, gap: int = 7) -> None:
        for item in items:
            lines = simpleSplit(item, FONT_REGULAR, size, CONTENT_WIDTH - 18)
            self.ensure_space((len(lines) * 15) + gap + 4)
            self.canvas.setFillColor(COLOR_PRIMARY)
            self.canvas.circle(MARGIN_X + 4, self.y + 3, 2.3, fill=1, stroke=0)
            self.canvas.setFont(FONT_REGULAR, size)
            self.canvas.setFillColor(COLOR_TEXT)
            for idx, line in enumerate(lines):
                x = MARGIN_X + 14
                self.canvas.drawString(x, self.y, line)
                self.y -= 15
            self.y -= gap

    def callout(self, title: str, body: str, tone: str = "neutral") -> None:
        lines = simpleSplit(body, FONT_REGULAR, 10, CONTENT_WIDTH - 36)
        height = 38 + (len(lines) * 13)
        self.ensure_space(height + 10)
        fill = COLOR_PANEL
        stripe = COLOR_PRIMARY
        if tone == "success":
            stripe = COLOR_SUCCESS
        elif tone == "warning":
            stripe = COLOR_WARNING
        self.canvas.setFillColor(fill)
        self.canvas.roundRect(MARGIN_X, self.y - height + 8, CONTENT_WIDTH, height, 12, fill=1, stroke=0)
        self.canvas.setFillColor(stripe)
        self.canvas.roundRect(MARGIN_X, self.y - height + 8, 8, height, 12, fill=1, stroke=0)
        self.canvas.setFont(FONT_BOLD, 11)
        self.canvas.setFillColor(COLOR_PRIMARY)
        self.canvas.drawString(MARGIN_X + 18, self.y - 10, title)
        self.canvas.setFont(FONT_REGULAR, 10)
        self.canvas.setFillColor(COLOR_TEXT)
        y = self.y - 26
        for line in lines:
            self.canvas.drawString(MARGIN_X + 18, y, line)
            y -= 13
        self.y -= height + 8

    def flow_box(self, number: int, title: str, body: str) -> None:
        body_lines = simpleSplit(body, FONT_REGULAR, 9, CONTENT_WIDTH - 76)
        height = 42 + (len(body_lines) * 11)
        self.ensure_space(height + 12)

        self.canvas.setFillColor(COLOR_PANEL)
        self.canvas.roundRect(MARGIN_X, self.y - height + 8, CONTENT_WIDTH, height, 12, fill=1, stroke=0)
        self.canvas.setFillColor(COLOR_PRIMARY)
        self.canvas.circle(MARGIN_X + 18, self.y - 14, 12, fill=1, stroke=0)
        self.canvas.setFont(FONT_BOLD, 12)
        self.canvas.setFillColor(colors.white)
        self.canvas.drawCentredString(MARGIN_X + 18, self.y - 18, str(number))
        self.canvas.setFont(FONT_BOLD, 11)
        self.canvas.setFillColor(COLOR_PRIMARY)
        self.canvas.drawString(MARGIN_X + 42, self.y - 10, title)
        self.canvas.setFont(FONT_REGULAR, 9)
        self.canvas.setFillColor(COLOR_TEXT)
        y = self.y - 25
        for line in body_lines:
            self.canvas.drawString(MARGIN_X + 42, y, line)
            y -= 11
        self.y -= height + 6
        if self.y > MARGIN_BOTTOM + 40:
            self.canvas.setStrokeColor(COLOR_LINE)
            self.canvas.setDash(4, 4)
            self.canvas.line(MARGIN_X + 18, self.y + 4, MARGIN_X + 18, self.y - 6)
            self.canvas.setDash()

    def timeline_pair(self, left_title: str, left_body: str, right_title: str, right_body: str) -> None:
        left_lines = simpleSplit(left_body, FONT_REGULAR, 10, (CONTENT_WIDTH / 2) - 28)
        right_lines = simpleSplit(right_body, FONT_REGULAR, 10, (CONTENT_WIDTH / 2) - 28)
        height = max(len(left_lines), len(right_lines)) * 13 + 42
        self.ensure_space(height + 16)

        mid_x = MARGIN_X + (CONTENT_WIDTH / 2)
        card_width = (CONTENT_WIDTH / 2) - 8
        top = self.y

        for idx, (title, lines, x, stripe) in enumerate([
            (left_title, left_lines, MARGIN_X, COLOR_WARNING),
            (right_title, right_lines, mid_x + 8, COLOR_SUCCESS),
        ]):
            self.canvas.setFillColor(COLOR_PANEL)
            self.canvas.roundRect(x, top - height + 8, card_width, height, 12, fill=1, stroke=0)
            self.canvas.setFillColor(stripe)
            self.canvas.roundRect(x, top - 20, card_width, 20, 12, fill=1, stroke=0)
            self.canvas.setFillColor(colors.white)
            self.canvas.setFont(FONT_BOLD, 10)
            self.canvas.drawString(x + 10, top - 14, title)
            self.canvas.setFillColor(COLOR_TEXT)
            self.canvas.setFont(FONT_REGULAR, 10)
            y = top - 34
            for line in lines:
                self.canvas.drawString(x + 10, y, line)
                y -= 13

        self.y -= height + 12


def build_pdf(output_path: Path) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    pdf = canvas.Canvas(str(output_path), pagesize=A4)
    doc = PdfDoc(pdf)

    doc.new_page()
    doc.title(
        "Fluxo da Feature de Emissao Fiscal",
        "Documento explicativo da implementacao com preflight obrigatorio, fila serializada por empresa/serie/ambiente e reaproveitamento seguro da numeracao fiscal antes da aceitacao da API.",
    )
    doc.callout(
        "Objetivo da feature",
        "Evitar remendos no processo de emissao, consolidar a validacao antes da API, impedir concorrencia indevida dentro do mesmo grupo fiscal e eliminar o buraco de numeracao quando uma tentativa falha antes da aceitacao da API.",
        tone="success",
    )
    doc.section("Visao geral")
    doc.bullet_list([
        "Toda emissao de NF-e agora passa por um FiscalEmissionPreflightService, que valida documento, destinatario, itens, perfil fiscal, CFOP, regime tributario e referencias obrigatorias antes de qualquer envio real.",
        "O clique em emitir nao envia a nota imediatamente. Ele coloca o documento em fila com status queued e registra um emission_group_key que agrupa company + model + serie + ambiente.",
        "A fila processa somente um documento por vez dentro do mesmo grupo. Isso evita duas notas da mesma serie tentarem usar numeros sequenciais em paralelo.",
        "O numero fiscal passa a ser atribuido apenas no instante do envio real e so e confirmado na sequencia depois que a API aceita o documento para processamento.",
    ])

    doc.section("Arquitetura")
    doc.bullet_list([
        "NfeDocumentService: recebe a intencao de emitir, executa o preflight para fila, grava o status queued e despacha o job do agrupamento.",
        "FiscalEmissionPreflightService: concentra as validacoes de emissao e devolve um contexto normalizado com serie, ambiente, scenario_code, candidate_number e queue_group_key.",
        "ProcessQueuedNfeEmissionJob: controla o lock por grupo, escolhe o proximo documento mais antigo da fila e processa um por vez.",
        "SendNfeAction: atribui temporariamente o menor numero livre, monta o payload, chama a API, confirma numeracao no sucesso e limpa a atribuicao em falhas pre-aceitacao.",
        "NfeSequence: deixa de ser somente reserva antecipada e passa a suportar confirmacao explicita do numero depois da aceitacao.",
    ])

    doc.new_page()
    doc.title(
        "Fluxo operacional",
        "Sequencia principal desde o clique em Emitir NF-e ate o documento seguir para processamento na API.",
    )
    flow_steps = [
        (
            "Usuario solicita a emissao",
            "O servico recebe o documento e bloqueia reenvios para status que ja nao podem voltar ao fluxo, como autorizado, cancelado ou em processamento.",
        ),
        (
            "Preflight para fila",
            "O FiscalEmissionPreflightService valida cabecalho, destinatario, endereco, itens, CFOP, CST/CSOSN, perfil fiscal, natureza da operacao e exigencias especificas do cenario.",
        ),
        (
            "Entrada em fila",
            "Se estiver apto, o documento fica com nfe_status = queued, recebe emission_requested_at e emission_group_key. Nao existe consumo de numero aqui.",
        ),
        (
            "Lock por agrupamento",
            "O job da fila abre um lock por company + model + serie + ambiente. Enquanto esse lock estiver ativo, nenhuma outra nota do mesmo grupo segue para envio real.",
        ),
        (
            "Preflight antes do envio",
            "Antes de chamar a API, o job executa o preflight novamente. Isso protege contra alteracoes feitas no documento depois que ele entrou na fila.",
        ),
        (
            "Atribuicao tardia do numero",
            "Somente agora o SendNfeAction consulta o menor numero livre e grava temporariamente document_number e document_series para a tentativa atual.",
        ),
        (
            "Envio para a API",
            "O payload e montado com as datas reais do documento e os dados tributarios ja validados. Em seguida, a SDK chama a API de emissao.",
        ),
        (
            "Resultado da tentativa",
            "Se a API aceitar o lote para processamento, a sequencia e confirmada e a nota vai para in_processing. Se falhar antes disso, o numero e limpo e o proximo documento da fila pode reaproveita-lo.",
        ),
    ]
    for index, (title, body) in enumerate(flow_steps, start=1):
        doc.flow_box(index, title, body)

    doc.new_page()
    doc.title(
        "Regra critica de numeracao",
        "A feature foi desenhada para impedir perda de numeracao quando multiplas notas da mesma empresa e serie entram em emissao.",
    )
    doc.callout(
        "Regra central",
        "O numero fiscal so e considerado consumido apos a aceitacao sincrona da API. Falha de validacao local, preflight ou rejeicao antes da aceitacao nao deve deixar numero preso.",
        tone="warning",
    )
    doc.timeline_pair(
        "Cenario antigo",
        "Id 1 reservava o numero 1 antes do envio real. Id 2 ja reservava o numero 2. Se Id 1 falhasse antes da aceitacao da API, o numero 1 ficava sem uso e a serie passava a ter buraco.",
        "Cenario novo",
        "Id 1 entra na fila e tenta usar o numero 1 quando chegar sua vez. Se falhar antes da aceitacao, o numero e limpo. Id 2, que ainda nao foi enviado, pega o numero 1. Quando Id 1 for corrigido e reenfileirado, ele segue no fim da fila e usa o proximo numero disponivel.",
    )
    doc.bullet_list([
        "O job sempre processa o documento queued mais antigo do grupo.",
        "Ao corrigir uma nota e reenfileira-la, o sistema grava novo emission_requested_at e ela volta para o fim da fila.",
        "O comportamento evita perda de numero e tambem evita que duas notas da mesma serie disputem a sequencia em paralelo.",
    ])

    doc.section("Tratamento de falhas")
    doc.bullet_list([
        "Falha no preflight antes do envio: documento sai de queued para pending, registra erro estruturado e nao consome numeracao.",
        "Falha da API antes da aceitacao: SendNfeAction limpa document_number, document_series e nfe_sequence_id, registra o erro e libera o grupo para a proxima nota.",
        "Sucesso de aceitacao: a chave e salva, o status muda para in_processing e a sequencia e confirmada.",
    ])

    doc.new_page()
    doc.title(
        "Validacoes consolidadas",
        "O preflight concentra regras que antes estavam espalhadas pelo CRUD, payload builder e resposta da API.",
    )
    doc.bullet_list([
        "Documento deve ser NF-e e nao pode estar em status que bloqueie nova emissao.",
        "A serie precisa ser resolvida e a natureza da operacao precisa existir.",
        "Operation type, issue purpose e freight_data.modalidade_frete sao obrigatorios para emissao.",
        "O destinatario deve existir, ter CPF/CNPJ valido e endereco completo com logradouro, numero, bairro, municipio, UF, CEP e codigo do municipio.",
        "A empresa precisa ter FiscalProfile ativo, e a natureza da operacao deve possuir configuracao fiscal correspondente.",
        "Cada item precisa ter produto, codigo, NCM, CFOP, unidade, quantidade positiva, valores consistentes, origem do produto e tributacao minima de ICMS, PIS e COFINS.",
        "O sistema valida compatibilidade entre regime tributario e CST/CSOSN, alem da compatibilidade de CFOP com operacao interna ou interestadual.",
        "Cenarios especiais, como devolucao, exigem referencia fiscal valida antes do envio.",
    ])
    doc.callout(
        "Efeito pratico",
        "O builder de payload deixa de ser o ponto principal de decisao de negocio. Ele passa a montar o payload a partir de um contexto ja validado, reduzindo retrabalho e remendos.",
        tone="success",
    )

    doc.section("Base para cenarios futuros")
    doc.bullet_list([
        "A resolucao de scenario_code ja prepara o caminho para fluxos como devolucao, remessa, retorno, transferencia e bonificacao.",
        "A fila e a numeracao nao precisam mudar quando um novo cenario entra. O que muda e a regra fiscal do preflight e, depois, a forma de montar o payload.",
        "Isso permite adicionar NF de remessa e NF de retorno como cenarios fiscais, e nao como ifs espalhados pelo fluxo de emissao.",
    ])

    doc.section("Resumo executivo")
    doc.bullet_list([
        "A emissao passa a ser previsivel, serializada e auditavel.",
        "A validacao fica centralizada e mais facil de manter.",
        "A numeracao deixa de ser consumida antes da hora.",
        "O fluxo fica preparado para crescer sem aumentar o numero de remendos no dominio fiscal.",
    ])

    doc.draw_footer()
    pdf.save()


if __name__ == "__main__":
    target = Path("docs/reports/fluxo-feature-emissao-fiscal.pdf")
    build_pdf(target)
    print(target.resolve())
