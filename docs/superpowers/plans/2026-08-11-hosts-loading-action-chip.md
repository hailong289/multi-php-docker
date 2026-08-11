# Hosts Loading Action Chip Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the bulky hosts progress card with a compact action chip beside Domains Add/Sync buttons.

**Architecture:** Display-only change in `DomainsView.vue` + CSS. Keep `hostsProgress` polling logic in `useManager.js`; stop rendering attempt counters.

**Tech Stack:** Vue 3, existing Manager `styles.css` tokens.

**Spec:** `docs/superpowers/specs/2026-08-11-hosts-loading-action-chip-design.md`

## Global Constraints

- Do not change hosts poll/protocol behaviour.
- Do not redesign unrelated loading states.
- Match existing badge / dark-light CSS variables.

---

### Task 1: DomainsView chip + CSS

**Files:**
- Modify: `server/manager/frontend/src/views/DomainsView.vue`
- Modify: `server/manager/frontend/src/styles.css`
- Modify (optional): `server/manager/frontend/src/i18n/en.js`, `vi.js`

- [ ] **Step 1: Move progress UI into heading actions**

In `DomainsView.vue` panel-heading-actions, after the Sync button, add:

```vue
<span
  v-if="hostsProgress"
  class="hosts-action-chip"
  role="status"
  aria-live="polite"
>
  <span class="hosts-action-chip-dot" aria-hidden="true"></span>
  <span class="hosts-action-chip-text">{{ $t(hostsProgress.message_key) }}</span>
</span>
```

Delete the old `.hosts-progress` block (lines with progress head / count / bar).

- [ ] **Step 2: Replace CSS**

Remove `.hosts-progress`, `.hosts-progress-head`, `.hosts-progress-count`, `.hosts-progress-bar`, `.hosts-progress-fill`.

Add:

```css
.hosts-action-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  max-width: min(280px, 52vw);
  padding: 6px 12px;
  border-radius: 999px;
  border: 1px solid var(--badge-line);
  background: var(--badge-bg);
  color: var(--badge-text);
  font-size: 12.5px;
  line-height: 1.3;
}

.hosts-action-chip-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--blue);
  box-shadow: 0 0 0 0 color-mix(in srgb, var(--blue) 45%, transparent);
  animation: hosts-chip-pulse 1.4s ease-out infinite;
  flex: 0 0 auto;
}

.hosts-action-chip-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@keyframes hosts-chip-pulse {
  0% {
    box-shadow: 0 0 0 0 color-mix(in srgb, var(--blue) 45%, transparent);
  }
  70% {
    box-shadow: 0 0 0 8px transparent;
  }
  100% {
    box-shadow: 0 0 0 0 transparent;
  }
}

@media (max-width: 640px) {
  .hosts-action-chip {
    max-width: 100%;
  }
}
```

If `color-mix` is a concern for older browsers used by this project, fall back to `rgba(88, 166, 255, 0.35)` for the pulse shadow only.

- [ ] **Step 3: Optional i18n trim**

If `hosts.progress_protocol` is too long for the chip, shorten vi/en to a chip-friendly length (still clear about admin prompt).

- [ ] **Step 4: Visual check**

Open Domains in Manager (or static review of markup). Trigger add domain if stack running; otherwise confirm template/CSS compile with existing frontend build if available:

```powershell
cd server/manager/frontend
npm run build
```

Expected: build succeeds.

- [ ] **Step 5: Commit**

```powershell
git add server/manager/frontend/src/views/DomainsView.vue server/manager/frontend/src/styles.css server/manager/frontend/src/i18n/en.js server/manager/frontend/src/i18n/vi.js
git commit -m "style(manager): replace hosts progress card with action chip"
```
