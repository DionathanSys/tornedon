<style>
    :root {
        color-scheme: light;
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        color: #17212b;
        background: #edf2f5;
    }

    * { box-sizing: border-box; }

    body {
        min-width: 320px;
        margin: 0;
        background: linear-gradient(145deg, #edf2f5 0%, #f8fafb 52%, #e8eef2 100%);
    }

    button, input { font: inherit; }

    .page-shell {
        width: min(100% - 32px, 1080px);
        margin: 0 auto;
        padding: 32px 0 56px;
    }

    .public-header,
    .section,
    .message-card {
        border: 1px solid #d9e2e8;
        border-radius: 22px;
        background: rgba(255, 255, 255, .94);
        box-shadow: 0 18px 50px rgba(44, 62, 80, .08);
    }

    .public-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 24px;
        padding: 24px 28px;
        border-top: 5px solid #177e89;
    }

    .brand {
        display: flex;
        align-items: center;
        gap: 16px;
        min-width: 0;
    }

    .brand img {
        width: 68px;
        max-height: 54px;
        object-fit: contain;
    }

    .eyebrow {
        margin: 0 0 7px;
        color: #177e89;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .14em;
        text-transform: uppercase;
    }

    h1, h2, p { margin-top: 0; }
    h1 { margin-bottom: 5px; font-size: clamp(22px, 4vw, 32px); letter-spacing: -.03em; }
    h2 { margin-bottom: 16px; font-size: 18px; letter-spacing: -.02em; }

    .header-side {
        flex: 0 0 auto;
        text-align: right;
    }

    .order-title {
        margin: 0;
        font-size: 17px;
        font-weight: 800;
    }

    .muted { color: #667684; }
    .small { font-size: 13px; }

    .content-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.45fr) minmax(300px, .75fr);
        align-items: start;
        gap: 20px;
        margin-top: 20px;
    }

    .stack { display: grid; gap: 20px; }
    .section { padding: 24px; }

    .meta-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .meta-card {
        min-width: 0;
        padding: 13px 14px;
        border: 1px solid #e2e9ed;
        border-radius: 13px;
        background: #f8fafb;
    }

    .meta-label {
        margin-bottom: 5px;
        color: #71808c;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .meta-value { overflow-wrap: anywhere; font-weight: 650; }
    .meta-secondary { margin-top: 3px; color: #71808c; font-size: 12px; }

    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 8px; border-bottom: 1px solid #e7edf0; text-align: left; vertical-align: top; }
    th { color: #71808c; font-size: 11px; letter-spacing: .07em; text-transform: uppercase; }
    td { font-size: 14px; }
    td.number, th.number { text-align: right; white-space: nowrap; }
    tr:last-child td { border-bottom: 0; }

    .notes { white-space: pre-line; line-height: 1.65; }
    .summary-table td:first-child { color: #667684; }
    .summary-table td:last-child { text-align: right; font-weight: 700; white-space: nowrap; }
    .summary-total td { border-top: 2px solid #d9e2e8; color: #17212b !important; font-size: 17px; font-weight: 850 !important; }

    .signature-panel { position: sticky; top: 20px; }
    .signature-intro { line-height: 1.6; }
    label { display: block; margin-bottom: 7px; color: #334451; font-size: 13px; font-weight: 750; }
    input[type="text"] {
        display: block;
        width: 100%;
        min-height: 46px;
        padding: 10px 13px;
        border: 1px solid #cbd7de;
        border-radius: 11px;
        outline: none;
        background: #fff;
    }
    input[type="text"]:focus { border-color: #177e89; box-shadow: 0 0 0 3px rgba(23, 126, 137, .14); }
    .field { margin-bottom: 18px; }
    .field-error { margin: 6px 0 0; color: #b42318; font-size: 12px; }

    .canvas-frame {
        overflow: hidden;
        border: 2px solid #177e89;
        border-radius: 15px;
        background: #fbfeff;
        box-shadow: inset 0 0 0 5px #eaf7f8;
    }
    canvas { display: block; width: 100%; height: 240px; cursor: crosshair; touch-action: none; }
    .canvas-help { margin: 8px 0 0; color: #71808c; font-size: 12px; }
    .signature-tools { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 10px; }
    .clear-button { padding: 7px 10px; border: 1px solid #cbd7de; border-radius: 8px; color: #41515d; background: #fff; cursor: pointer; font-size: 12px; }
    .clear-button:hover { background: #f1f5f7; }
    .agreement { display: flex; align-items: flex-start; gap: 9px; margin: 20px 0; color: #41515d; font-size: 13px; font-weight: 500; line-height: 1.5; }
    .agreement input { width: 17px; height: 17px; margin-top: 1px; accent-color: #177e89; }
    .submit-button { width: 100%; min-height: 48px; border: 0; border-radius: 11px; color: #fff; background: #177e89; cursor: pointer; font-weight: 800; }
    .submit-button:hover { background: #126b74; }
    .submit-button:disabled { cursor: wait; opacity: .7; }

    .alert { margin-bottom: 20px; padding: 14px 16px; border: 1px solid #f1b8b3; border-radius: 12px; color: #8f2118; background: #fff5f4; font-size: 13px; }
    .alert ul { margin: 0; padding-left: 18px; }
    .message-card { max-width: 680px; margin: 10vh auto 0; padding: 44px 32px; text-align: center; }
    .message-icon { display: grid; width: 58px; height: 58px; margin: 0 auto 20px; place-items: center; border-radius: 50%; color: #177e89; background: #e5f5f6; font-size: 28px; font-weight: 850; }
    .message-card h1 { margin-bottom: 12px; }
    .message-card p { margin-bottom: 0; line-height: 1.65; }
    .signed-image { display: block; max-width: 300px; max-height: 140px; margin: 24px auto 10px; object-fit: contain; }

    @media (max-width: 780px) {
        .page-shell { width: min(100% - 20px, 680px); padding-top: 10px; }
        .public-header { align-items: flex-start; flex-direction: column; padding: 20px; }
        .header-side { text-align: left; }
        .content-grid { grid-template-columns: 1fr; }
        .signature-panel { position: static; }
        .section { padding: 19px; }
    }

    @media (max-width: 480px) {
        .meta-grid { grid-template-columns: 1fr; }
        th, td { padding: 10px 6px; }
        .message-card { margin-top: 5vh; padding: 34px 22px; }
    }
</style>
