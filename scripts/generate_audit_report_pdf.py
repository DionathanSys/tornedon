from pathlib import Path
from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import cm
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfbase import pdfmetrics
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, PageBreak
from xml.sax.saxutils import escape


ROOT = Path(__file__).resolve().parents[1]
INPUT_PATH = ROOT / "docs" / "reports" / "relatorio-auditoria-2026-04-21.md"
OUTPUT_PATH = ROOT / "docs" / "reports" / "relatorio-auditoria-2026-04-21.pdf"


def register_fonts() -> None:
    candidates = [
        (r"C:\Windows\Fonts\calibri.ttf", "Calibri"),
        (r"C:\Windows\Fonts\arial.ttf", "Arial"),
    ]

    for font_path, font_name in candidates:
        path = Path(font_path)
        if path.exists():
            pdfmetrics.registerFont(TTFont(font_name, str(path)))
            return


def build_styles():
    styles = getSampleStyleSheet()
    base_font = "Helvetica"

    for candidate in ("Calibri", "Arial"):
        if candidate in pdfmetrics.getRegisteredFontNames():
            base_font = candidate
            break

    styles.add(
        ParagraphStyle(
            name="ReportTitle",
            parent=styles["Title"],
            fontName=base_font,
            fontSize=18,
            leading=22,
            alignment=TA_CENTER,
            textColor=colors.HexColor("#0f172a"),
            spaceAfter=12,
        )
    )
    styles.add(
        ParagraphStyle(
            name="ReportH1",
            parent=styles["Heading1"],
            fontName=base_font,
            fontSize=14,
            leading=18,
            textColor=colors.HexColor("#0f172a"),
            spaceBefore=10,
            spaceAfter=8,
        )
    )
    styles.add(
        ParagraphStyle(
            name="ReportH2",
            parent=styles["Heading2"],
            fontName=base_font,
            fontSize=12,
            leading=16,
            textColor=colors.HexColor("#1d4ed8"),
            spaceBefore=8,
            spaceAfter=6,
        )
    )
    styles.add(
        ParagraphStyle(
            name="ReportBody",
            parent=styles["BodyText"],
            fontName=base_font,
            fontSize=9.5,
            leading=13,
            textColor=colors.HexColor("#111827"),
            spaceAfter=5,
        )
    )
    styles.add(
        ParagraphStyle(
            name="ReportBullet",
            parent=styles["BodyText"],
            fontName=base_font,
            fontSize=9.5,
            leading=13,
            leftIndent=14,
            firstLineIndent=-8,
            bulletIndent=6,
            textColor=colors.HexColor("#111827"),
            spaceAfter=3,
        )
    )
    styles.add(
        ParagraphStyle(
            name="ReportCode",
            parent=styles["BodyText"],
            fontName="Courier",
            fontSize=8.5,
            leading=11,
            backColor=colors.HexColor("#f3f4f6"),
            borderPadding=5,
            textColor=colors.HexColor("#111827"),
            spaceAfter=4,
        )
    )

    return styles


def paragraph_for_line(line: str, styles):
    stripped = line.strip()

    if not stripped:
        return Spacer(1, 0.18 * cm)

    if stripped.startswith("# "):
        return Paragraph(escape(stripped[2:]), styles["ReportTitle"])

    if stripped.startswith("## "):
        return Paragraph(escape(stripped[3:]), styles["ReportH1"])

    if stripped.startswith("### "):
        return Paragraph(escape(stripped[4:]), styles["ReportH2"])

    if stripped.startswith("- "):
        content = escape(stripped[2:]).replace("`", "")
        return Paragraph(f"• {content}", styles["ReportBullet"])

    if stripped[0].isdigit() and ". " in stripped[:4]:
        content = escape(stripped).replace("`", "")
        return Paragraph(content, styles["ReportBullet"])

    if "`" in stripped:
        content = escape(stripped).replace("`", "")
        return Paragraph(content, styles["ReportCode"])

    return Paragraph(escape(stripped), styles["ReportBody"])


def add_page_number(canvas, doc):
    canvas.saveState()
    canvas.setFont("Helvetica", 8)
    canvas.setFillColor(colors.HexColor("#6b7280"))
    canvas.drawRightString(19.3 * cm, 1.2 * cm, f"Página {doc.page}")
    canvas.drawString(2 * cm, 1.2 * cm, "Relatório Técnico - Auditoria Central por Empresa")
    canvas.restoreState()


def main() -> None:
    register_fonts()
    styles = build_styles()

    content = INPUT_PATH.read_text(encoding="utf-8").splitlines()

    story = []
    line_count = 0

    for line in content:
        flowable = paragraph_for_line(line, styles)
        story.append(flowable)
        line_count += 1

        if line_count in {90, 180, 270}:
            story.append(PageBreak())

    doc = SimpleDocTemplate(
        str(OUTPUT_PATH),
        pagesize=A4,
        leftMargin=2 * cm,
        rightMargin=2 * cm,
        topMargin=2 * cm,
        bottomMargin=2 * cm,
        title="Relatório Técnico - Implementação da Auditoria Central por Empresa",
        author="Codex",
    )

    doc.build(story, onFirstPage=add_page_number, onLaterPages=add_page_number)


if __name__ == "__main__":
    main()
