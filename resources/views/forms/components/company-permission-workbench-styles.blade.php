<style>
    .cpw { --cpw-border:#e2e8f0; --cpw-bg:#fff; --cpw-soft:#f8fafc; --cpw-text:#0f172a; --cpw-muted:#64748b; --cpw-accent:#0284c7; color:var(--cpw-text); border:1px solid var(--cpw-border); border-radius:14px; overflow:hidden; font-size:13px; }
    .dark .cpw { --cpw-border:#334155; --cpw-bg:#111827; --cpw-soft:#1e293b; --cpw-text:#f1f5f9; --cpw-muted:#94a3b8; --cpw-accent:#38bdf8; }
    .cpw [x-cloak] { display:none!important; }
    .cpw svg { width:20px; height:20px; flex-shrink:0; }
    .cpw button { cursor:pointer; font:inherit; text-align:left; }
    .cpw button:focus-visible,.cpw input:focus-visible,.cpw select:focus-visible { outline:2px solid var(--cpw-accent); outline-offset:3px; }
    .cpw-toolbar { display:flex; align-items:center; gap:18px; padding:16px; background:var(--cpw-bg); border-bottom:1px solid var(--cpw-border); }
    .cpw-search { display:flex; gap:9px; align-items:center; border:1px solid var(--cpw-border); border-radius:8px; padding:0 10px; color:var(--cpw-muted); flex:1; }
    .cpw-search input { min-width:0; width:100%; border:0; box-shadow:none!important; background:transparent; padding:9px 0; color:var(--cpw-text); font-size:12px; }
    .cpw-enabled { display:flex; align-items:center; gap:7px; font-size:11px; white-space:nowrap; cursor:pointer; }
    .cpw-enabled input { border-radius:4px; color:#0284c7; }
    .cpw-total { color:var(--cpw-muted); font-size:11px; white-space:nowrap; }
    .cpw-total strong { color:var(--cpw-text); font-size:16px; margin-right:3px; }
    .cpw-layout { display:grid; grid-template-columns:235px minmax(0,1fr); min-height:380px; }
    .cpw-modules { max-height:470px; overflow-y:auto; background:var(--cpw-soft); border-right:1px solid var(--cpw-border); padding:10px; }
    .cpw-module { display:flex; width:100%; justify-content:space-between; align-items:center; gap:8px; padding:10px; border-radius:8px; border:1px solid transparent; margin-bottom:3px; background:transparent; }
    .cpw-module small { display:block; font-size:9px; color:var(--cpw-muted); margin-bottom:3px; }
    .cpw-module strong { display:block; font-size:12px; font-weight:600; }
    .cpw-module:hover { background:var(--cpw-bg); }
    .cpw-module.is-active { border-color:var(--cpw-accent); background:var(--cpw-bg); color:var(--cpw-accent); }
    .cpw-count { display:grid; place-items:center; min-width:23px; height:23px; border-radius:6px; font-size:10px; color:var(--cpw-muted); background:var(--cpw-bg); }
    .cpw-count.has-grants { background:color-mix(in srgb,var(--cpw-accent) 12%,var(--cpw-bg)); color:var(--cpw-accent); }
    .cpw-editor { padding:20px; min-width:0; background:var(--cpw-bg); }
    .cpw-module-heading { display:flex; justify-content:space-between; gap:12px; }
    .cpw-module-heading > svg { color:var(--cpw-muted); }
    .cpw-eyebrow { text-transform:uppercase; letter-spacing:.07em; color:var(--cpw-muted); font-size:9px; font-weight:600; }
    .cpw-module-heading h3 { font-size:19px; font-weight:700; margin:4px 0; }
    .cpw-module-heading p { font-size:11px; color:var(--cpw-muted); line-height:1.6; max-width:470px; }
    .cpw-presets { display:flex; flex-wrap:wrap; align-items:center; gap:8px; padding:14px 0; border-bottom:1px solid var(--cpw-border); margin-top:7px; }
    .cpw-presets > span { font-size:10px; color:var(--cpw-muted); }
    .cpw-presets button { border:1px solid var(--cpw-border); background:var(--cpw-soft); border-radius:6px; padding:5px 7px; font-size:10px; color:var(--cpw-accent); }
    .cpw-column-heading { display:flex; justify-content:space-between; gap:12px; color:var(--cpw-muted); font-size:10px; padding:16px 0 6px; }
    .cpw-permissions { max-height:335px; overflow-y:auto; }
    .cpw-permission { display:flex; align-items:center; gap:10px; border-bottom:1px solid var(--cpw-border); padding:14px 0; }
    .cpw-permission:last-child { border:0; }
    .cpw-indicator { display:grid; place-items:center; width:22px; height:22px; flex-shrink:0; color:var(--cpw-muted); background:var(--cpw-soft); border-radius:6px; }
    .cpw-permission.is-enabled .cpw-indicator { color:var(--cpw-accent); background:color-mix(in srgb,var(--cpw-accent) 10%,var(--cpw-bg)); }
    .cpw-action { flex:1; min-width:0; }
    .cpw-action strong { font-size:12px; font-weight:600; }
    .cpw-action p { font-size:10px; color:var(--cpw-muted); margin-top:3px; line-height:1.5; }
    .cpw-permission select { width:185px; flex-shrink:0; border:1px solid var(--cpw-border); border-radius:7px; padding:8px 30px 8px 9px; font-size:11px; background-color:var(--cpw-bg); color:var(--cpw-muted); }
    .cpw-permission.is-enabled select { color:var(--cpw-text); border-color:color-mix(in srgb,var(--cpw-accent) 45%,var(--cpw-border)); }
    .cpw-scope-value { font-size:11px; color:var(--cpw-muted); text-align:right; max-width:185px; }
    .cpw-footer { display:flex; align-items:start; gap:9px; padding:13px 16px; border-top:1px solid var(--cpw-border); background:var(--cpw-soft); color:var(--cpw-muted); font-size:10px; line-height:1.7; }
    .cpw-footer svg { width:17px; height:17px; margin-top:1px; }
    .cpw-empty { padding:28px 12px; font-size:11px; color:var(--cpw-muted); line-height:1.7; text-align:center; }
    .cpw-empty-editor { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:10px; }
    .cpw-sr-only { position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0; }
    @media (max-width:760px) {
        .cpw-toolbar { flex-wrap:wrap; gap:10px; padding:12px; }.cpw-search { flex-basis:100%; }.cpw-total { margin-left:auto; }
        .cpw-layout { grid-template-columns:1fr; }.cpw-modules { max-height:155px; display:flex; flex-wrap:wrap; gap:5px; border-right:0; border-bottom:1px solid var(--cpw-border); }.cpw-module { width:auto; flex:1 0 150px; padding:8px; margin:0; }
        .cpw-editor { padding:14px; }.cpw-permission { flex-wrap:wrap; }.cpw-action { flex-basis:calc(100% - 32px); }.cpw-permission select { margin-left:32px; width:calc(100% - 32px); }.cpw-scope-value { margin-left:32px; }.cpw-column-heading > span:last-child { display:none; }.cpw-permissions { max-height:400px; }
    }
</style>
