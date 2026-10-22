<style>
    .brw { --brw-border: #e5e7eb; --brw-bg: #fff; --brw-soft: #f8fafc; --brw-text: #111827; --brw-muted: #64748b; --brw-accent: #0284c7; color: var(--brw-text); font-size: 14px; }
    .dark .brw { --brw-border: #374151; --brw-bg: #111827; --brw-soft: #1f2937; --brw-text: #f3f4f6; --brw-muted: #94a3b8; --brw-accent: #38bdf8; }
    .brw [x-cloak] { display: none !important; }
    .brw button { cursor: pointer; font: inherit; }
    .brw button:disabled { cursor: not-allowed; opacity: .5; }
    .brw button:focus-visible, .brw input:focus-visible, .brw textarea:focus-visible { outline: 2px solid var(--brw-accent); outline-offset: 3px; }
    .brw svg { width: 20px; height: 20px; flex-shrink: 0; }
    .brw h3 { font-size: 17px; font-weight: 650; line-height: 1.4; margin: 6px 0 0; }
    .brw-layout { display: grid; grid-template-columns: minmax(230px, .75fr) minmax(0, 2fr); gap: 24px; align-items: start; }
    .brw-eyebrow { display: flex; align-items: center; gap: 8px; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: .09em; color: var(--brw-muted); }
    .brw-bank { padding: 24px; background: var(--brw-soft); border: 1px solid var(--brw-border); border-radius: 16px; }
    .brw-bank-total { font-size: 30px; font-weight: 750; letter-spacing: -.04em; margin-top: 22px; font-variant-numeric: tabular-nums; }
    .brw-muted { color: var(--brw-muted); font-size: 12px; }
    .brw-badge { display: inline-flex; margin-top: 14px; background: color-mix(in srgb, var(--brw-accent) 12%, transparent); color: var(--brw-accent); padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 650; }
    .brw-bank-lines { max-height: 300px; overflow-y: auto; margin-top: 18px; }
    .brw-bank-toggle { display: none; }
    .brw-bank-line { border-top: 1px solid var(--brw-border); padding: 16px 0; }
    .brw-row { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; font-size: 12px; }
    .brw-row > span { color: var(--brw-muted); }
    .brw-bank-line p { color: var(--brw-muted); font-size: 12px; margin-top: 6px; overflow-wrap: anywhere; }
    .brw-bank-tip { display: flex; gap: 10px; font-size: 12px; color: var(--brw-muted); border-top: 1px solid var(--brw-border); padding-top: 18px; }
    .brw-modes { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 18px 0; }
    .brw-mode { display: flex; gap: 10px; align-items: center; text-align: left; border: 1px solid var(--brw-border); border-radius: 10px; padding: 13px; background: var(--brw-bg); }
    .brw-mode.is-active { border-color: var(--brw-accent); box-shadow: inset 0 0 0 1px var(--brw-accent); background: color-mix(in srgb, var(--brw-accent) 6%, var(--brw-bg)); }
    .brw-mode.is-active > svg { color: var(--brw-accent); }
    .brw-mode strong { display: block; font-size: 12px; }
    .brw-mode small { display: block; margin-top: 3px; color: var(--brw-muted); font-size: 11px; }
    .brw-search { display: flex; align-items: center; gap: 10px; border: 1px solid var(--brw-border); border-radius: 10px; padding: 0 12px; color: var(--brw-muted); }
    .brw-search:focus-within { border-color: var(--brw-accent); box-shadow: 0 0 0 2px color-mix(in srgb, var(--brw-accent) 15%, transparent); }
    .brw-search input { width: 100%; padding: 12px 0; background: transparent; border: 0; outline: none !important; box-shadow: none !important; font-size: 13px; color: var(--brw-text); }
    .brw-results-heading { display: flex; flex-wrap: wrap; gap: 6px; justify-content: space-between; font-size: 12px; margin: 14px 0 8px; }
    .brw-results { max-height: 310px; min-height: 160px; overflow-y: auto; border: 1px solid var(--brw-border); border-radius: 10px; }
    .brw-record { width: 100%; display: flex; align-items: center; gap: 12px; text-align: left; background: var(--brw-bg); border-bottom: 1px solid var(--brw-border); padding: 13px; }
    .brw-record:last-child { border-bottom: 0; }
    .brw-record:hover { background: var(--brw-soft); }
    .brw-record.is-selected { background: color-mix(in srgb, var(--brw-accent) 7%, var(--brw-bg)); }
    .brw-check { display: grid; place-items: center; width: 24px; height: 24px; border: 1px solid var(--brw-border); border-radius: 6px; color: var(--brw-muted); flex-shrink: 0; }
    .is-selected .brw-check { color: white; border-color: #0284c7; background: #0284c7; }
    .brw-record-main { flex: 1; min-width: 0; }
    .brw-record-main > strong, .brw-record-main > span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; }
    .brw-record-main > span { color: var(--brw-muted); margin-top: 3px; }
    .brw-record-main > small { display: block; color: var(--brw-muted); font-size: 11px; margin-top: 4px; }
    .brw-record-value { text-align: right; flex-shrink: 0; font-variant-numeric: tabular-nums; }
    .brw-record-value > strong { font-size: 13px; }
    .brw-record-value small { display: block; font-size: 10px; margin-top: 4px; }
    .brw-match, .brw-partial { color: #059669; }
    .dark .brw-match, .dark .brw-partial { color: #34d399; }
    .brw-date-warning { color: #b45309; }
    .dark .brw-date-warning { color: #fbbf24; }
    .brw-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; text-align: center; padding: 30px; color: var(--brw-muted); font-size: 12px; }
    .brw-empty > svg { width: 28px; height: 28px; }
    .brw-load-more { width: 100%; background: var(--brw-soft); padding: 12px; color: var(--brw-accent); font-size: 12px !important; }
    .brw-selection { margin-top: 20px; }
    .brw-selection-heading { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .brw-count { display: inline-block; background: var(--brw-soft); border-radius: 5px; padding: 1px 7px; margin-left: 5px; font-size: 12px; }
    .brw-text-button { color: var(--brw-accent); font-size: 11px !important; font-weight: 600 !important; background: none; }
    .brw-selected-list { max-height: 380px; overflow-y: auto; }
    .brw-selected-entry { border-top: 1px solid var(--brw-border); padding-bottom: 12px; }
    .brw-selected-item { display: flex; align-items: start; gap: 12px; padding: 12px 0; }
    .brw-amount-label { display: block; font-size: 10px; color: var(--brw-muted); margin-bottom: 4px; }
    .brw-adjustments { background: var(--brw-soft); border-radius: 7px; padding: 9px 12px; }
    .brw-adjustments summary { cursor: pointer; color: var(--brw-accent); font-size: 11px; font-weight: 600; }
    .brw-adjustments summary small { display: inline-block; margin-left: 8px; color: var(--brw-muted); font-weight: 400; }
    .brw-adjustment-fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 14px; }
    .brw-adjustment-fields > label > span, .brw-notes > span { display: block; font-size: 11px; font-weight: 600; margin-bottom: 5px; }
    .brw-adjustment-fields .brw-amount { background: var(--brw-bg); }
    .brw-adjustment-summary { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px; font-size: 11px; margin-top: 12px; }
    .brw-adjustment-hint { font-size: 10px; color: var(--brw-muted); margin: 9px 0; }
    .brw-notes textarea { width: 100%; border: 1px solid var(--brw-border); border-radius: 6px; padding: 8px; background: var(--brw-bg); color: var(--brw-text); font-size: 12px; }
    .brw-selected-title { flex: 1; min-width: 0; }
    .brw-selected-title > strong { display: block; font-size: 12px; overflow-wrap: anywhere; }
    .brw-selected-title > small { display: block; font-size: 11px; color: var(--brw-muted); margin-top: 4px; }
    .brw-selected-title > small.brw-date-warning { color: #b45309; }
    .brw-edit-value { text-align: right; width: 155px; flex-shrink: 0; font-size: 13px; }
    .brw-edit-value small, .brw-edit-value button { display: block; margin-top: 4px; margin-left: auto; font-size: 10px; }
    .brw-amount { display: flex; align-items: center; gap: 5px; padding: 7px 9px; border: 1px solid var(--brw-border); border-radius: 7px; }
    .brw-amount span { font-size: 11px; color: var(--brw-muted); }
    .brw-amount input { width: 100%; min-width: 0; text-align: right; color: var(--brw-text); font-weight: 600; font-size: 13px; background: transparent; border: 0; padding: 0; box-shadow: none; }
    .brw-remove { padding: 3px; color: var(--brw-muted); background: none; }
    .brw-remove:hover { color: #dc2626; }
    .brw-effect { padding: 10px 12px; background: var(--brw-soft); color: var(--brw-muted); border-radius: 7px; font-size: 11px; margin-top: 10px; }
    .brw-reason { margin-top: 16px; border: 1px solid #f59e0b; padding: 14px; border-radius: 10px; background: color-mix(in srgb, #f59e0b 5%, var(--brw-bg)); }
    .brw-reason label { display: block; font-weight: 600; font-size: 12px; }
    .brw-reason p { font-size: 11px; color: var(--brw-muted); margin: 5px 0 10px; }
    .brw-reason textarea { width: 100%; border: 1px solid var(--brw-border); background: var(--brw-bg); color: var(--brw-text); border-radius: 6px; font-size: 12px; padding: 9px; }
    .brw-footer { position: sticky; bottom: 0; z-index: 2; margin-top: 24px; padding: 18px 0 0; border-top: 1px solid var(--brw-border); background: var(--brw-bg); }
    .brw-totals { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
    .brw-totals > div { padding: 10px 14px; background: var(--brw-soft); border-radius: 8px; }
    .brw-totals span { display: block; color: var(--brw-muted); font-size: 11px; }
    .brw-totals strong { display: block; font-size: 18px; margin-top: 3px; font-variant-numeric: tabular-nums; }
    .brw-difference.is-balanced { background: color-mix(in srgb, #10b981 10%, var(--brw-bg)); }
    .brw-difference.is-balanced strong, .brw-status.is-balanced { color: #059669; }
    .dark .brw-difference.is-balanced strong, .dark .brw-status.is-balanced { color: #34d399; }
    .brw-footer-bottom { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-top: 14px; }
    .brw-status { font-size: 12px; color: var(--brw-muted); max-width: 520px; }
    .brw-footer-actions { display: flex; gap: 10px; flex-shrink: 0; }
    .brw-cancel, .brw-submit { display: flex; align-items: center; justify-content: center; gap: 7px; padding: 10px 14px; border-radius: 8px; font-size: 12px !important; font-weight: 600 !important; }
    .brw-cancel { border: 1px solid var(--brw-border); background: var(--brw-bg); }
    .brw-submit { background: #0284c7; color: #fff; }
    .brw-submit:hover:not(:disabled) { background: #0369a1; }
    .brw-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
    @media (max-width: 760px) {
        .brw-layout { grid-template-columns: 1fr; gap: 18px; }
        .brw-bank { padding: 16px; }
        .brw-bank-total { margin-top: 10px; font-size: 26px; }
        .brw-bank-lines { max-height: 140px; margin-top: 8px; }
        .brw-bank-lines:not(.is-expanded) { display: none; }
        .brw-bank-toggle { display: block; margin-top: 12px; background: none; color: var(--brw-accent); font-size: 11px !important; font-weight: 600 !important; }
        .brw-bank-tip { display: none; }
        .brw-modes { grid-template-columns: 1fr; }
        .brw-results-heading > .brw-muted { display: none; }
        .brw-totals { gap: 6px; }
        .brw-totals > div { padding: 8px; }
        .brw-totals strong { font-size: 14px; }
        .brw-footer-bottom { align-items: stretch; flex-direction: column; }
        .brw-footer-actions > button { flex: 1; }
        .brw-record { gap: 8px; padding: 11px; }
        .brw-record-value > strong { font-size: 12px; }
        .brw-edit-value { width: 130px; }
        .brw-adjustment-fields { gap: 6px; }
        .brw-adjustment-fields .brw-amount { padding: 7px 5px; }
    }
</style>
