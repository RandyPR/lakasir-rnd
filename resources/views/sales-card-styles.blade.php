{{-- Styles of the compact sales card (filament/tenant/widgets/sales-card). Loaded on the dashboard and the sales list only. --}}
<style>
    .sc { --sc-card: #fff; --sc-ring: rgba(var(--gray-950), .08); --sc-ink: rgb(var(--gray-950)); --sc-ink2: rgb(var(--gray-600));
          --sc-muted: rgb(var(--gray-500)); --sc-up: rgb(var(--success-600)); --sc-down: rgb(var(--danger-600));
          --sc-tint: rgba(var(--primary-500), .09); --sc-edge: rgba(var(--primary-500), .35); --sc-alert-ink: rgb(var(--warning-800));
          --sc-alert-bg: rgb(var(--warning-50)); container-type: inline-size; }
    .dark .sc { --sc-card: rgb(var(--gray-900)); --sc-ring: rgba(255, 255, 255, .1); --sc-ink: rgb(var(--gray-50)); --sc-ink2: rgb(var(--gray-300));
          --sc-muted: rgb(var(--gray-400)); --sc-up: rgb(var(--success-400)); --sc-down: rgb(var(--danger-400));
          --sc-tint: rgba(var(--primary-500), .16); --sc-edge: rgba(var(--primary-500), .45); --sc-alert-ink: rgb(var(--warning-300));
          --sc-alert-bg: rgba(var(--warning-500), .12); }
    .sc section { background: var(--sc-card); border: 1px solid var(--sc-ring); border-radius: .75rem; color: var(--sc-ink); }
    .sc .sc-up { color: var(--sc-up); font-weight: 600; }
    .sc .sc-down { color: var(--sc-down); font-weight: 600; }
    .sc .sc-flat { color: var(--sc-muted); font-weight: 600; }
    .sc i { font-style: normal; color: var(--sc-muted); font-weight: 400; }

    /* wide: one thin strip */
    .sc .sc-phone { display: none; }
    .sc .sc-pick { display: flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
    .sc .sc-pick b { color: var(--sc-ink); font-size: .875rem; font-weight: 650; }
    .sc .sc-pick-btn { width: 2rem; height: 2rem; border-radius: .5rem; border: 1px solid var(--sc-line, rgba(var(--gray-950), .12)); background: transparent; color: var(--sc-ink); font-size: 1.1rem; line-height: 1; cursor: pointer; }
    .sc .sc-pick-btn:disabled { opacity: .35; cursor: default; }
    .sc .sc-pick-date { height: 2rem; padding: 0 .4rem; border-radius: .5rem; border: 1px solid var(--sc-line, rgba(var(--gray-950), .12)); background: transparent; color: var(--sc-ink); font-size: .8125rem; color-scheme: light dark; }
    .sc .sc-pick-today { height: 2rem; padding: 0 .65rem; border-radius: .5rem; border: 0; background: var(--sc-tint); color: rgb(var(--primary-600)); font-size: .8125rem; font-weight: 600; cursor: pointer; }
    .dark .sc .sc-pick-today { color: rgb(var(--primary-400)); }
    .sc .sc-alert { margin: 0 .75rem .65rem; padding: .45rem .7rem; border-radius: .5rem; background: var(--sc-alert-bg); color: var(--sc-alert-ink); font-size: .8125rem; line-height: 1.4; }
    .sc .sc-head { display: flex; align-items: baseline; justify-content: space-between; gap: .75rem; padding: .55rem 1rem 0; font-size: .75rem; color: var(--sc-muted); }
    .sc .sc-head b { color: var(--sc-ink); font-size: .8125rem; font-weight: 650; }
    .sc .sc-head-meta { display: flex; gap: .9rem; flex-wrap: wrap; justify-content: flex-end; }
    .sc .sc-head-meta strong { color: var(--sc-ink2); font-weight: 600; }
    .sc .sc-body { display: flex; align-items: stretch; padding: .25rem .5rem .6rem; }
    .sc .sc-seg { flex: 1 1 0; min-width: 0; padding: .25rem .65rem; }
    .sc .sc-seg--profit { background: var(--sc-tint); border-radius: .55rem; }
    .sc .sc-seg--count { flex: .8 1 0; }
    .sc .sc-label { font-size: .75rem; color: var(--sc-ink2); font-weight: 500; white-space: nowrap; }
    .sc .sc-value { font-size: 1.375rem; font-weight: 650; letter-spacing: -.02em; line-height: 1.25; white-space: nowrap; }
    .sc .sc-sub { font-size: .75rem; color: var(--sc-muted); white-space: nowrap; min-height: 1em; }
    .sc .sc-op { flex: none; width: 1.4rem; align-self: center; text-align: center; color: var(--sc-muted); font-size: 1rem; }
    .sc .sc-rule { flex: none; width: 1px; background: var(--sc-ring); margin: .4rem .5rem; }

    /* narrow: the roomy phone version (container width, not window width) */
    @container (max-width: 700px) {
        .sc .sc-wide { display: none; }
        .sc .sc-phone { display: block; padding: .875rem 1rem .75rem; }
        .sc .sc-top { display: flex; justify-content: space-between; align-items: baseline; font-size: .8125rem; color: var(--sc-muted); }
        .sc .sc-top b { color: var(--sc-ink); font-size: .875rem; font-weight: 650; }
        .sc .sc-p-label { margin-top: .75rem; font-size: .8125rem; color: var(--sc-ink2); }
        .sc .sc-p-hero { font-size: 1.75rem; font-weight: 700; letter-spacing: -.02em; line-height: 1.2; white-space: nowrap; }
        .sc .sc-p-delta { font-size: .8125rem; color: var(--sc-muted); }
        .sc .sc-p-two { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin-top: .75rem; padding-top: .75rem; border-top: 1px solid var(--sc-ring); }
        .sc .sc-p-two > div { padding: .4rem .6rem; border-radius: .6rem; }
        .sc .sc-p-profit { background: var(--sc-tint); }
        .sc .sc-p-k { display: flex; justify-content: space-between; font-size: .8125rem; color: var(--sc-ink2); }
        .sc .sc-p-v { font-size: 1.25rem; font-weight: 650; letter-spacing: -.02em; line-height: 1.3; white-space: nowrap; }
        .sc .sc-p-s { font-size: .8125rem; color: var(--sc-muted); min-height: 1em; }
        .sc .sc-p-rows { margin-top: .4rem; }
        .sc .sc-p-row { display: grid; grid-template-columns: 4.2rem 1fr 3.8rem; align-items: center; min-height: 2.5rem; font-size: .9375rem; padding: 0 .5rem; border-radius: .55rem; }
        .sc .sc-p-row b { text-align: right; font-weight: 650; font-size: 1rem; font-variant-numeric: tabular-nums; }
        .sc .sc-p-row em { font-style: normal; text-align: right; font-size: .8125rem; color: var(--sc-muted); }
        .sc .sc-p-row--profit { background: var(--sc-tint); }
        .sc .sc-p-foot { margin-top: .75rem; font-size: .8125rem; color: var(--sc-muted); }
        .sc .sc-alert { margin: .75rem 0 0; }
    }
    @container (max-width: 340px) {
        .sc .sc-p-v { font-size: 1.0625rem; }
        .sc .sc-p-hero { font-size: 1.5625rem; }
        .sc .sc-hide-small { display: none; }
    }

    /* The period buttons of the dashboard (Mingguan ... Tahunan) are wider than a small phone: share the width. */
    @media (max-width: 480px) {
        .fi-btn-group:has(input[name="filters.period"]) { width: 100%; grid-auto-columns: 1fr; }
        .fi-btn-group:has(input[name="filters.period"]) .fi-btn { padding-inline: .25rem; font-size: .8125rem; white-space: nowrap; }
    }
    @media (max-width: 340px) {
        .fi-btn-group:has(input[name="filters.period"]) .fi-btn { font-size: .75rem; }
    }
</style>
