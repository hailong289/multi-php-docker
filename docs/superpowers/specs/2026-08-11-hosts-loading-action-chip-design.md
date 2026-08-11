# Hosts loading Action Chip UI

**Date:** 2026-08-11  
**Status:** Approved for planning

## Problem

The Domains hosts write loading UI shows a technical poll counter (`attempt/maxAttempts`), an earnest progress bar, and rotating status copy in a bulky `.hosts-progress` block inside the panel heading. It looks like a process log rather than a calm status.

## Goal

Replace that block with a compact **action chip** beside the Add / Sync buttons during hosts write / wait-for-helper flows.

## Non-goals

- Changing poll timing, protocol launch, or hosts backend APIs.
- Redesigning sync-only button spinner (`hosts-sync` pending).
- Global toast redesign.

## Design

### Placement

When `hostsProgress` is set, render a chip in `panel-heading-actions` (after Sync), not under the status paragraphs.

### Appearance

- Pill / rounded chip matching existing badge tokens (`--badge-bg`, `--badge-line`, `--badge-text`).
- Soft pulse/dot (or tiny spinner) + single translated `hostsProgress.message_key` line.
- Do **not** show `attempt` / `maxAttempts` on screen.
- Keep `aria-live="polite"` on the chip.

### Markup / CSS

- Update `DomainsView.vue`: remove `.hosts-progress` block; add chip next to actions.
- Add `.hosts-action-chip` (+ pulse animation) in `styles.css`; can delete or leave unused `.hosts-progress*` rules cleaned up.
- Optional: shorten any `hosts.progress_*` string that overflows a chip (vi/en).

### Logic

`hostsProgress` object in `useManager.js` stays; UI simply ignores numeric fields for display.

## Success

During add-domain hosts write or admin write, user sees a discreet chip instead of the old progress card; poll still completes as today.
