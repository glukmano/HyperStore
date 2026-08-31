---
name: theme-sdk
description: Enforces Theme architecture, themes/default, Child Themes, reusable UI component abstractions, and Page Builder blocks. Use when working on themes, storefront templates, or UI design systems.
---

# Theme SDK & Storefront UI Layer

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 6, 22)

## Core Rules & Mandates

1. **Default Theme Baseline (`themes/default`)**:
   - Simple, functional, professional, responsive.
   - Built on Tailwind CSS 4 and daisyUI 5 defaults.
   - **No unnecessary custom or overly flashy design** for the baseline; prioritize clean usability and commercial readiness.
2. **Reusable UI Component Abstractions**:
   - Storefront and Control Center views must use clean `<x-ui.*>` abstractions:
     ```blade
     <x-ui.button>
     <x-ui.modal>
     <x-ui.table>
     <x-ui.input>
     <x-ui.select>
     <x-ui.tabs>
     <x-ui.card>
     ```
   - Do NOT scatter raw daisyUI classes across core business views.
3. **Child Themes & Overrides**:
   - Themes can inherit from a parent theme via Child Theme manifests.
   - Support template overrides for specific Product Types, Categories, and Landing Pages.
4. **Page Builder Integration**:
   - Reusable theme sections and blocks register with the Page Builder schema.
   - Blocks must render seamlessly in both RTL and LTR viewports.

## Pre-Execution Checklist
- [ ] Are UI components wrapped in `<x-ui.*>` Blade component abstractions?
- [ ] Is RTL and LTR support verified with logical Tailwind utilities?
- [ ] Does the theme include a valid `theme.json` manifest?

## Forbidden Shortcuts
- ❌ Hardcoding raw third-party CSS classes directly inside domain templates.
- ❌ Breaking parent-child theme inheritance rules.
- ❌ Creating non-responsive or non-RTL-compliant views.

## Validation Steps
1. Render templates under both default theme and simulated child theme.
2. Verify responsive layout on mobile, tablet, and desktop breakpoints.
3. Test Page Builder block registration and preview rendering.
